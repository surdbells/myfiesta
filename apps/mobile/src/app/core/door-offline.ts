import { Injectable, inject, signal } from '@angular/core';
import { DoorOfflineStore, OfflineScan, ScanResult, conflictsIn, scanId } from '@myfiesta/door';
import { Api, ApiError } from './api';

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
 */
@Injectable({ providedIn: 'root' })
export class DoorOffline extends DoorOfflineStore {
  private readonly api = inject(Api);

  /** How many scans are waiting to be sent. */
  readonly pendingCount = signal(0);

  /** How many tickets are on the saved list, and when it was fetched. */
  readonly listCount = signal(0);
  readonly listUpdatedAt = signal<Date | null>(null);

  /** True once a request has failed for want of a connection. */
  readonly connectionLost = signal(false);

  /** Where the phone's answer and the server's later disagreed. */
  readonly conflicts = signal<(ScanResult & { client_id: string })[]>([]);

  /**
   * People let in offline on scans the server could not take.
   *
   * Never sent again, because they never can be as they are — see `sync` —
   * and on no record but this phone's: the ticket still reads as unused on
   * the server. Kept on the phone, not only on the screen, until door staff
   * dismiss them, and read back by `prepare`, so a reload or the app being
   * killed before anybody looked does not lose them.
   */
  readonly unrecorded = signal<OfflineScan[]>([]);

  private syncing = false;

  /** Load what this phone already has for the event, and count it. */
  async prepare(eventId: string): Promise<void> {
    const saved = await this.load(eventId).catch(() => null);

    if (saved) {
      this.listCount.set(saved.count);
      this.listUpdatedAt.set(new Date(saved.generated_at));
    }

    this.unrecorded.set(await this.unrecordedAdmissions(eventId).catch(() => []));
    await this.countPending(eventId);
  }

  /**
   * Fetch the list again.
   *
   * Not while scans are waiting: a list fetched before the server has heard
   * about offline admissions would not know about them, and this phone would
   * forget it had already let those people in.
   */
  async refreshList(eventId: string): Promise<void> {
    if (!this.supported || this.pendingCount() > 0) return;

    try {
      const saved = await this.save(await this.api.doorList(eventId));

      this.listCount.set(saved.count);
      this.listUpdatedAt.set(new Date(saved.generated_at));
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
   * who was turned away and why is this phone's alone.
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

    const scan: OfflineScan = {
      client_id: clientId,
      event_id: eventId,
      code,
      party,
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
   * What the server got wrong is compared there too (`conflictsIn`): a scan
   * that reached the server online and was queued when its answer did not
   * come back is one the server answers without comparing.
   */
  async sync(eventId: string): Promise<boolean> {
    if (!this.supported || this.syncing) return true;

    const pending = await this.pending(eventId).catch(() => []);

    if (pending.length === 0) {
      this.pendingCount.set(0);

      return true;
    }

    this.syncing = true;

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
          this.conflicts.update((all) => [...all, ...conflicts]);
        }
      }

      this.connectionLost.set(false);

      return true;
    } catch (error) {
      if (this.lostConnection(error)) this.connectionLost.set(true);
      else if (error instanceof ApiError && error.status === 422) {
        const admissions = await this.dropUnsendable(batch, error.fields).catch(() => []);
        const kept = new Set(admissions.map((scan) => scan.client_id));

        if (admissions.length > 0) {
          this.unrecorded.update((all) => [...all.filter((scan) => !kept.has(scan.client_id)), ...admissions]);
        }
      }

      return false;
    } finally {
      this.syncing = false;
      await this.countPending(eventId);
    }
  }

  dismissConflicts(): void {
    this.conflicts.set([]);
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
    this.pendingCount.set((await this.pending(eventId).catch(() => [])).length);
  }
}
