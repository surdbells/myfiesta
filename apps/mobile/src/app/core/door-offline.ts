import { Injectable, inject, signal } from '@angular/core';
import { DoorOfflineStore, OfflineScan, ScanResult, scanId } from '@myfiesta/door';
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

  private syncing = false;

  /** Load what this phone already has for the event, and count it. */
  async prepare(eventId: string): Promise<void> {
    const saved = await this.load(eventId).catch(() => null);

    if (saved) {
      this.listCount.set(saved.count);
      this.listUpdatedAt.set(new Date(saved.generated_at));
    }

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
   */
  async decideAndQueue(eventId: string, code: string, party: number | null): Promise<ScanResult> {
    const outcome = await this.decide(code, party);

    const scan: OfflineScan = {
      client_id: scanId(),
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
   */
  async sync(eventId: string): Promise<boolean> {
    if (!this.supported || this.syncing) return true;

    const pending = await this.pending(eventId).catch(() => []);

    if (pending.length === 0) {
      this.pendingCount.set(0);

      return true;
    }

    this.syncing = true;

    try {
      for (let i = 0; i < pending.length; i += 200) {
        const batch = pending.slice(i, i + 200);
        const result = await this.api.syncScans(
          eventId,
          batch.map(({ event_id: _event, ...scan }) => scan),
        );

        await this.forget(result.data.map((row) => row.client_id));

        if (result.conflicts.length > 0) {
          this.conflicts.update((all) => [...all, ...result.conflicts]);
        }
      }

      this.connectionLost.set(false);

      return true;
    } catch (error) {
      if (this.lostConnection(error)) this.connectionLost.set(true);

      return false;
    } finally {
      this.syncing = false;
      await this.countPending(eventId);
    }
  }

  dismissConflicts(): void {
    this.conflicts.set([]);
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
