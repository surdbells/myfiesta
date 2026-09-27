import { Injectable, computed, inject, signal } from '@angular/core';
import {
  DoorOfflineStore,
  OfflineScan,
  ScanResult,
  asksHowMany,
  conflictsIn,
  queuedParty,
  scanId,
} from '@myfiesta/door';
import { Api, ApiError } from './api';

/** Where the phone's answer and the server's later disagreed, about one scan. */
type Conflict = ScanResult & { client_id: string };

/**
 * The door when the signal goes, on a phone.
 *
 * A venue's wifi is a rumour. Until this existed the phone app — the thing
 * actually held at a door — simply failed on every scan the moment the
 * connection did, while the console could carry on; the API has had both
 * endpoints for it all along.
 *
 * What it does is what the console does, because it is the same store and the
 * same rules underneath, out of `@myfiesta/door`. A door that answers
 * differently depending on which app is holding it is a door whose staff stop
 * trusting either answer.
 *
 * The sequence is: try the server; on a connection failure decide from the
 * saved list and queue what was decided; send the queue whenever the server
 * comes back. A refusal is never treated as a connection failure — a 401 means
 * this pass has been taken back, and a phone that keeps admitting people on a
 * revoked pass is worse than one that stops.
 *
 * One of these for the whole app, and one door screen open at a time — but
 * what the last door asked the server for can still be on its way when the
 * next is opened: its list, or a sync on a slow wifi. What comes back belongs
 * to the door that was left, and is said there when it opens again. It used to
 * be said on whichever door was open by then, as if it were that door's own.
 */
@Injectable({ providedIn: 'root' })
export class DoorOffline extends DoorOfflineStore {
  private readonly api = inject(Api);

  /** The event whose door is open: the one `prepare` was last asked for. */
  private readonly doorOpen = signal<string | null>(null);

  /** Scans waiting to be sent, counted for each event — see `pendingCount`. */
  private readonly waiting = signal<Readonly<Record<string, number>>>({});

  /**
   * How many scans are waiting to be sent from the door that is open.
   *
   * Counted for each event, and read for the open door's. It was one number
   * for the whole phone, set by whichever count finished last, and a sync for
   * a door already left, finishing late, put that event's count on the next
   * event's door: "Sending 2 scans made while offline" on a door that had
   * made none, and its list not fetched while they waited.
   */
  readonly pendingCount = computed(() => {
    const open = this.doorOpen();

    return open === null ? 0 : (this.waiting()[open] ?? 0);
  });

  /**
   * How many tickets are on the saved list, and when it was fetched: the list
   * the open door decides from, never another event's.
   */
  readonly listCount = signal(0);
  readonly listUpdatedAt = signal<Date | null>(null);

  /** True once a request has failed for want of a connection. */
  readonly connectionLost = signal(false);

  /**
   * Where the phone's answer and the server's later disagreed, for each event.
   *
   * On this phone only, never saved, so kept for a door that has been left
   * rather than dropped: the guest it is about may still be in that room.
   */
  private readonly clashes = signal<Readonly<Record<string, Conflict[]>>>({});

  /** Where the phone's answer and the server's later disagreed, at the door that is open. */
  readonly conflicts = computed<Conflict[]>(() => {
    const open = this.doorOpen();

    return open === null ? [] : (this.clashes()[open] ?? []);
  });

  /**
   * People let in offline on scans the server could not take, at the door
   * that is open.
   *
   * Never sent again, because they never can be as they are — see `sync` —
   * and on no record but this phone's: the ticket still reads as unused on
   * the server. Kept on the phone, not only on the screen, until door staff
   * dismiss them, and read back by `prepare`, so a reload or the app being
   * killed before anybody looked does not lose them.
   */
  readonly unrecorded = signal<OfflineScan[]>([]);

  /** The sync under way for each event, for a second call to wait on — see `sync`. */
  private readonly sending = new Map<string, Promise<boolean>>();

  /**
   * Open the event's door: load what this phone already has for it, and
   * count it.
   *
   * Nothing of the last door's is said on this one meanwhile, and if another
   * door is opened before this has finished reading, what it read is not said
   * at all.
   */
  async prepare(eventId: string): Promise<void> {
    if (this.doorOpen() !== eventId) {
      this.listCount.set(0);
      this.listUpdatedAt.set(null);
      this.unrecorded.set([]);
    }

    this.doorOpen.set(eventId);

    await this.load(eventId).catch(() => null);
    const kept = await this.unrecordedAdmissions(eventId).catch(() => []);

    if (this.doorOpen() !== eventId) return;

    this.showSavedList();
    this.unrecorded.set(kept);
    await this.countPending(eventId);
  }

  /**
   * Fetch the list again.
   *
   * Not while scans are waiting: a list fetched before the server has heard
   * about offline admissions would not know about them, and this phone would
   * forget it had already let those people in. Waiting for this event, that
   * is: another event's scans have nothing to do with its list.
   */
  async refreshList(eventId: string): Promise<void> {
    if (!this.supported || (this.waiting()[eventId] ?? 0) > 0) return;

    try {
      await this.save(await this.api.doorList(eventId));

      // A list for a door left while it was on its way is saved for that
      // door, and the list in memory is still the open door's.
      this.showSavedList();
      this.connectionLost.set(false);
    } catch (error) {
      if (this.lostConnection(error)) this.connectionLost.set(true);
    }
  }

  /**
   * Decide without the server, and write down what was decided.
   *
   * The scan is queued whatever the answer was, including a refusal: the
   * server needs the whole night, not only the admissions, or its record of
   * who was turned away and why is this phone's alone. Except the question
   * of how many of a table are here, which let nobody in and turned nobody
   * away: the scan that answers it is queued instead, with the number that
   * went in (`queuedParty`).
   *
   * @param clientId the id the scan was first sent to the server with, if it
   *                 was: a request that timed out may still have arrived, and
   *                 the same id is what makes the queued copy the same scan
   *                 rather than a second person. If the server had answered
   *                 it differently, `sync` says so (`conflictsIn`).
   */
  async decideAndQueue(
    eventId: string,
    code: string,
    party: number | null,
    clientId: string = scanId(),
  ): Promise<ScanResult> {
    const outcome = await this.decide(code, party);

    if (asksHowMany(outcome)) return outcome;

    const scan: OfflineScan = {
      client_id: clientId,
      event_id: eventId,
      code,
      party: queuedParty(party, outcome),
      offline_result: outcome.result,
      scanned_at: new Date().toISOString(),
    };

    await this.enqueue(scan).catch(() => undefined);
    await this.countPending(eventId);

    return outcome;
  }

  /**
   * Send what is waiting. Oldest first, in batches.
   *
   * A scan is forgotten only once the server has answered for it, so a sync
   * whose response is lost is simply sent again — the scan ids make that
   * harmless.
   *
   * Or once the server has said it never will. It refuses a batch whole if
   * one scan in it does not validate, and this phone used to send that batch
   * again every fifteen seconds all night, with every scan behind the bad one
   * and the list refresh waiting on them. Which ones may go is decided in
   * `@myfiesta/door`, the same for the console: only those whose code or party
   * the server named. The rest go with the next sync. Of those, a refusal is
   * forgotten, and an admission is kept on the phone, unsent, for `unrecorded`.
   *
   * What the server got wrong it names itself, including for a scan that
   * reached it online and was queued when its answer did not come back: it
   * compares the door's decision with its own and reports the difference like
   * any other offline conflict. `conflictsIn` still compares on the phone, as
   * a fallback for an answer that carries no decision to compare.
   *
   * Answers whether it all went: true once the server has answered for every
   * scan that was waiting when the sync began, false if any is still on the
   * phone for want of signal or because the server refused its batch.
   *
   * One at a time for an event. A call while a sync is already sending waits
   * for that one and has its answer. It used to be told true straight away,
   * with the scans still on their way and nobody knowing yet whether they
   * would get there: a door screen opened again mid-sync went on to fetch its
   * list as if the queue had gone, found scans still waiting, and fetched
   * nothing — so a phone just back in signal kept the old list for minutes
   * more.
   */
  sync(eventId: string): Promise<boolean> {
    if (!this.supported) return Promise.resolve(true);

    const under = this.sending.get(eventId);

    if (under) return under;

    const sending = this.send(eventId).finally(() => this.sending.delete(eventId));

    this.sending.set(eventId, sending);

    return sending;
  }

  /** One sync, from reading the queue to counting what is left: see `sync`. */
  private async send(eventId: string): Promise<boolean> {
    const pending = await this.pending(eventId).catch(() => []);

    if (pending.length === 0) {
      this.waiting.update((counts) => ({ ...counts, [eventId]: 0 }));

      return true;
    }

    let batch: OfflineScan[] = [];

    try {
      for (let i = 0; i < pending.length; i += 200) {
        batch = pending.slice(i, i + 200);
        const result = await this.api.syncScans(
          eventId,
          batch.map(({ event_id: _event, ...scan }) => scan),
        );

        await this.forget(result.data.map((row) => row.client_id));

        const conflicts = conflictsIn(batch, result);

        if (conflicts.length > 0) {
          this.clashes.update((all) => ({ ...all, [eventId]: [...(all[eventId] ?? []), ...conflicts] }));
        }
      }

      this.connectionLost.set(false);

      return true;
    } catch (error) {
      if (this.lostConnection(error)) this.connectionLost.set(true);
      else if (error instanceof ApiError && error.status === 422) {
        const admissions = await this.dropUnsendable(batch, error.fields).catch(() => []);
        const kept = new Set(admissions.map((scan) => scan.client_id));

        // Said now only if this event's door is still the one open. They are
        // on the phone either way, and `prepare` reads them back when it is.
        if (admissions.length > 0 && this.doorOpen() === eventId) {
          this.unrecorded.update((all) => [...all.filter((scan) => !kept.has(scan.client_id)), ...admissions]);
        }
      }

      return false;
    } finally {
      await this.countPending(eventId);
    }
  }

  /** Read at the door that is open; any other door's are kept for it. */
  dismissConflicts(): void {
    const open = this.doorOpen();

    if (open === null) return;

    this.clashes.update(({ [open]: _read, ...rest }) => rest);
  }

  /** Door staff have read them: off the screen, and off the phone. */
  async dismissUnrecorded(): Promise<void> {
    const read = this.unrecorded().map((scan) => scan.client_id);

    this.unrecorded.set([]);

    if (read.length > 0) await this.forget(read).catch(() => undefined);
  }

  /**
   * Whether this was the connection rather than an answer.
   *
   * The API client turns an unreachable server into status 0. Anything else is
   * the server speaking, and a door must not fall back to its own judgement
   * because the server said no.
   */
  lostConnection(error: unknown): boolean {
    return error instanceof ApiError && error.status === 0;
  }

  private async countPending(eventId: string): Promise<void> {
    const waiting = (await this.pending(eventId).catch(() => [])).length;

    this.waiting.update((counts) => ({ ...counts, [eventId]: waiting }));
  }

  /** The list in memory, which is only ever the open door's: see `DoorOfflineStore.save`. */
  private showSavedList(): void {
    const saved = this.summary;

    this.listCount.set(saved?.count ?? 0);
    this.listUpdatedAt.set(saved ? new Date(saved.generated_at) : null);
  }
}
