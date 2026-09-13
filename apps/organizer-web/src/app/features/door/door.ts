import { Component, OnDestroy, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { TimeoutError, firstValueFrom, timeout } from 'rxjs';
import { UiButton } from '@myfiesta/ui';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { eventIdFrom } from '../../core/event-id';
import { Api } from '../../core/api';
import { ScanResult, SyncResult } from '../../core/api.types';
import { DoorOffline, scanId } from '../../core/door-offline';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';
import { DoorPassStore } from '../../core/door-pass';
import { EventWorkspace } from '../events/event-workspace';
import { DoorPasses } from './door-passes';

/**
 * The door.
 *
 * The scan endpoint, partial admission, ticket_scans and the ledger all existed
 * and were tested; there was no screen to scan with, so none of it could be
 * used on a night.
 *
 * Two ways in, and the manual one is not a fallback for form's sake. Camera
 * scanning uses BarcodeDetector, which Chrome and Android have and Safari does
 * not — and a door with a flat battery, a cracked lens or a guest whose screen
 * will not brighten still has to work. Typing the code is the path that never
 * fails, so it is always visible rather than hidden behind "having trouble?".
 */
@Component({
  selector: 'app-door',
  imports: [FormsModule, UiButton, DoorPasses],
  templateUrl: './door.html',
})
export class Door implements OnDestroy {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  readonly session = inject(SessionStore);

  readonly eventId = eventIdFrom(this.route);

  /** The event, from the workspace frame around this screen — saved on the phone, so it holds offline. */
  private readonly workspace = inject(EventWorkspace, { optional: true });

  /** Or, on a phone working the door with a pass, from the pass — which carries the same rule. */
  private readonly doorPass = inject(DoorPassStore);

  private readonly rules = computed(() => this.workspace?.event() ?? this.doorPass.for(this.eventId)?.event ?? null);

  /**
   * Whether to offer door passes here: inside the console, to somebody who
   * both works the door and runs the event. Never on a phone scanning with a
   * pass, which could not make one anyway.
   */
  readonly canIssuePasses = computed(() => !!this.workspace && this.session.canScan() && this.session.canEditEvents());

  readonly eventTimezone = computed(() => this.workspace?.event()?.timezone ?? 'UTC');

  /**
   * The age and ID rule, said where the checking happens.
   *
   * The event has carried a minimum age and an ID requirement since it was
   * created, shown to buyers and on their tickets — and not to the one person
   * who has to enforce them. Whoever is on the door was left to remember.
   */
  readonly admissionRule = computed(() => {
    const event = this.rules();

    if (!event || (!event.min_age && !event.id_required)) return null;

    if (event.min_age && event.id_required) return `${event.min_age}+ — check photo ID for everyone`;
    if (event.min_age) return `${event.min_age}+ — ask for ID if in doubt`;

    return 'Photo ID required for everyone';
  });

  /** Short, for the verdict panel, where there is no room for a sentence. */
  readonly admissionCheck = computed(() => {
    const event = this.rules();

    if (!event) return null;
    if (event.min_age && event.id_required) return `Check ID · ${event.min_age}+`;
    if (event.id_required) return 'Check ID';
    if (event.min_age) return `${event.min_age}+`;

    return null;
  });

  // --- working without signal ----------------------------------------------

  private readonly offline = inject(DoorOffline);

  /** Whether this phone can decide offline at all (secure context, IndexedDB). */
  readonly offlineSupported = this.offline.supported;

  /** The saved list: how many tickets, and when it was last fresh. */
  readonly listCount = signal<number | null>(null);
  readonly listUpdatedAt = signal<Date | null>(null);
  readonly refreshingList = signal(false);

  /** Scans made offline and not yet sent. */
  readonly pendingCount = signal(0);
  readonly syncing = signal(false);

  /** What the last sync found the door had got wrong while offline. */
  readonly conflicts = signal<SyncResult['conflicts']>([]);

  /**
   * Whether the browser believes it has a network.
   *
   * Believed, not trusted: navigator.onLine is true on a venue wifi that has
   * no route out, so every request still has its own timeout. What this is
   * good for is noticing the moment signal comes back.
   */
  readonly online = signal(typeof navigator === 'undefined' ? true : navigator.onLine);

  /** Set when a request actually failed for want of a connection. */
  readonly connectionLost = signal(false);

  readonly workingOffline = computed(() => !this.online() || this.connectionLost());

  /** Ticks every 30 seconds so "updated 4 min ago" stays true without a reload. */
  private readonly now = signal(Date.now());

  readonly listAge = computed(() => {
    const at = this.listUpdatedAt();

    if (!at) return null;

    const minutes = Math.floor((this.now() - at.getTime()) / 60_000);

    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes} min ago`;

    return `${Math.floor(minutes / 60)} h ago`;
  });

  private timers: ReturnType<typeof setInterval>[] = [];

  private readonly onOnline = () => {
    this.online.set(true);
    void this.syncAndRefresh();
  };

  private readonly onOffline = () => this.online.set(false);

  constructor() {
    if (typeof window !== 'undefined') {
      window.addEventListener('online', this.onOnline);
      window.addEventListener('offline', this.onOffline);
    }

    void this.startOffline();
  }

  /**
   * Load what this phone already saved, then freshen it while there is signal.
   *
   * The saved list is loaded first so a phone that opens this screen with no
   * connection — reopened in a basement — is ready immediately instead of
   * waiting on a download that will never arrive.
   */
  private async startOffline(): Promise<void> {
    if (!this.offlineSupported) return;

    const saved = await this.offline.load(this.eventId).catch(() => null);

    if (saved) {
      this.listCount.set(saved.count);
      this.listUpdatedAt.set(new Date(saved.generated_at));
    }

    await this.updatePending();

    // Scans still waiting means this phone was offline when it last scanned.
    // Assumed still offline until a request succeeds, rather than until one
    // fails: navigator.onLine is true on a wifi with no route out, and the
    // failure can take the whole timeout to arrive — long enough to tell a
    // door "ready" while nothing is getting through.
    if (this.pendingCount() > 0) this.connectionLost.set(true);

    await this.syncAndRefresh();

    // Sending waits for signal; so does the list. Both retried on a timer
    // rather than only on the browser's "online" event, which does not fire
    // when a wifi with no internet quietly starts working again.
    this.timers.push(setInterval(() => void this.sync(), 15_000));
    this.timers.push(setInterval(() => void this.refreshList(), 3 * 60_000));
    this.timers.push(setInterval(() => this.now.set(Date.now()), 30_000));
  }

  private async syncAndRefresh(): Promise<void> {
    const sent = await this.sync();

    // Refreshed only once the queue is clear: a list fetched before the
    // server has heard about offline admissions would not know about them.
    if (sent) await this.refreshList();
  }

  async refreshList(): Promise<void> {
    if (!this.offlineSupported || this.refreshingList() || this.pendingCount() > 0) return;

    this.refreshingList.set(true);

    try {
      const list = await firstValueFrom(this.api.doorList(this.eventId).pipe(timeout(20_000)));
      const saved = await this.offline.save(list);

      this.listCount.set(saved.count);
      this.listUpdatedAt.set(new Date(saved.generated_at));
      this.connectionLost.set(false);
    } catch (error) {
      if (this.isConnectionFailure(error)) this.connectionLost.set(true);
    } finally {
      this.refreshingList.set(false);
    }
  }

  /**
   * Send whatever was scanned offline. Returns whether the queue is now clear.
   *
   * Oldest first, in batches, and removed from the phone only once the server
   * has answered — a sync whose response is lost is simply sent again, and
   * the scan ids make that harmless.
   */
  async sync(): Promise<boolean> {
    if (!this.offlineSupported) return true;
    if (this.syncing()) return false;

    const pending = await this.offline.pending(this.eventId);

    if (pending.length === 0) {
      this.pendingCount.set(0);

      return true;
    }

    this.syncing.set(true);

    try {
      for (let i = 0; i < pending.length; i += 200) {
        const batch = pending.slice(i, i + 200);
        const result = await firstValueFrom(
          this.api
            .syncScans(
              this.eventId,
              batch.map(({ event_id: _event, ...scan }) => scan),
            )
            .pipe(timeout(15_000)),
        );

        await this.offline.forget(result.data.map((row) => row.client_id));

        if (result.conflicts.length > 0) {
          this.conflicts.update((all) => [...all, ...result.conflicts]);
        }
      }

      this.connectionLost.set(false);

      return true;
    } catch (error) {
      if (this.isConnectionFailure(error)) this.connectionLost.set(true);

      return false;
    } finally {
      this.syncing.set(false);
      await this.updatePending();
    }
  }

  dismissConflicts(): void {
    this.conflicts.set([]);
  }

  private async updatePending(): Promise<void> {
    this.pendingCount.set((await this.offline.pending(this.eventId).catch(() => [])).length);
  }

  /**
   * No connection, as opposed to the server saying no.
   *
   * Status 0 is a request that never got an answer; a timeout is one that
   * took too long to be useful to a queue; 502–504 are what a proxy returns
   * when the connection behind it dropped. A 4xx is the server answering, and
   * falling back to the phone's list would override a real refusal.
   */
  private isConnectionFailure(error: unknown): boolean {
    if (error instanceof TimeoutError) return true;

    if (error instanceof HttpErrorResponse) {
      return error.status === 0 || error.status === 502 || error.status === 503 || error.status === 504;
    }

    return false;
  }

  readonly code = signal('');
  readonly party = signal('');
  readonly busy = signal(false);
  readonly outcome = signal<ScanResult | null>(null);
  readonly error = signal<string | null>(null);

  /** Running count for this session, so a door can sanity-check itself. */
  readonly admittedHere = signal(0);
  readonly scannedHere = signal(0);

  readonly scanning = signal(false);
  readonly cameraError = signal<string | null>(null);

  private stream: MediaStream | null = null;
  private detector: unknown = null;
  private stopping = false;

  /** Whether this browser can read a QR without a library. */
  readonly cameraSupported = typeof window !== 'undefined' && 'BarcodeDetector' in window;

  /**
   * The colour and words the person on the door reacts to.
   *
   * Deliberately coarse: at a door, in the dark, the only question is let them
   * in or do not. The detail underneath is for the conversation that follows a
   * refusal.
   */
  readonly verdict = computed<'in' | 'partial' | 'no' | null>(() => {
    const outcome = this.outcome();

    if (!outcome) return null;
    if (!outcome.accepted) return 'no';

    return outcome.remaining > 0 ? 'partial' : 'in';
  });

  submit(): void {
    const code = this.code().trim().toUpperCase();

    if (!code || this.busy()) return;

    void this.send(code);
  }

  /**
   * Scan online if the connection answers in time; otherwise decide from the
   * phone's list and send the scan later.
   *
   * Six seconds, not the browser's thirty. A queue at a door will not stand
   * still for thirty seconds per guest, and a connection that slow is, for
   * the purposes of a door, no connection. The scan carries an id either way,
   * so if the slow request did reach the server after all, the queued copy
   * is recognised as the same scan rather than a second person.
   */
  private async send(code: string): Promise<void> {
    this.busy.set(true);
    this.error.set(null);

    const partyNumber = Number(this.party());
    const party = partyNumber > 0 ? partyNumber : null;
    const clientId = scanId();

    let outcome: ScanResult;

    try {
      outcome = await firstValueFrom(
        this.api.scan(this.eventId, code, party ?? undefined, clientId).pipe(timeout(6_000)),
      );

      this.connectionLost.set(false);

      // Mirrored onto the saved list, so if the signal goes a minute from now
      // this phone already knows the ticket was used.
      if (outcome.accepted && outcome.ticket) {
        void this.offline.admitLocally(code, party, outcome.ticket.admitted_count);
      }
    } catch (error) {
      if (!this.isConnectionFailure(error)) {
        this.busy.set(false);
        this.outcome.set(null);
        this.error.set(messageFor(error, 'That scan could not be sent.'));

        return;
      }

      this.connectionLost.set(true);

      if (!this.offlineSupported || !this.offline.ready) {
        this.busy.set(false);
        this.outcome.set(null);
        this.error.set(
          this.offlineSupported
            ? 'No connection, and this phone has no saved ticket list yet. Open the door screen once with signal so it can download one.'
            : 'No connection, and this browser cannot check tickets offline. Use the console over https.',
        );

        return;
      }

      outcome = await this.offline.decide(code, party);

      // Every offline scan is queued, refusals included: the server needs to
      // know who was turned away as much as who went in.
      await this.offline.enqueue({
        client_id: clientId,
        event_id: this.eventId,
        code,
        party,
        offline_result: outcome.result,
        scanned_at: new Date().toISOString(),
      });

      await this.updatePending();
    }

    this.busy.set(false);
    this.outcome.set(outcome);
    this.scannedHere.update((n) => n + 1);
    this.admittedHere.update((n) => n + outcome.admitted);

    // Cleared so the next guest can be scanned without a delete. The party
    // size is cleared too — it belongs to one ticket, and carrying it over
    // would silently admit four people on the next single ticket.
    this.code.set('');
    this.party.set('');
  }

  // --- the camera ---------------------------------------------------------

  async startCamera(): Promise<void> {
    if (this.scanning() || !this.cameraSupported) return;

    this.cameraError.set(null);

    try {
      this.stream = await navigator.mediaDevices.getUserMedia({
        // The back camera. Without this a phone opens the selfie camera and
        // somebody has to hold the guest's ticket behind their own head.
        video: { facingMode: { ideal: 'environment' } },
      });
    } catch {
      this.cameraError.set(
        'No camera permission. Type the code instead — it works exactly the same.',
      );

      return;
    }

    const video = document.getElementById('door-camera') as HTMLVideoElement | null;

    if (!video) return;

    video.srcObject = this.stream;
    await video.play().catch(() => undefined);

    const Detector = (window as unknown as Record<string, new (o: object) => unknown>)[
      'BarcodeDetector'
    ];
    this.detector = new Detector({ formats: ['qr_code'] });

    this.scanning.set(true);
    this.stopping = false;
    void this.readLoop(video);
  }

  /**
   * Read frames until something is found or the camera is stopped.
   *
   * Polled on a timer rather than every animation frame: a door phone runs for
   * hours on one charge, and decoding sixty frames a second drains a battery
   * long before the queue is through.
   */
  private async readLoop(video: HTMLVideoElement): Promise<void> {
    const detector = this.detector as { detect(source: unknown): Promise<{ rawValue: string }[]> };

    while (!this.stopping) {
      try {
        const found = await detector.detect(video);

        if (found.length > 0 && !this.busy()) {
          const value = found[0].rawValue.trim().toUpperCase();

          this.code.set(value);
          void this.send(value);

          // A pause after a hit, so one ticket held in front of the lens is not
          // scanned six times while the door reads the result.
          await new Promise((r) => setTimeout(r, 1800));
        }
      } catch {
        // A dropped frame is not worth stopping for.
      }

      await new Promise((r) => setTimeout(r, 250));
    }
  }

  stopCamera(): void {
    this.stopping = true;
    this.scanning.set(false);
    this.stream?.getTracks().forEach((track) => track.stop());
    this.stream = null;
  }

  ngOnDestroy(): void {
    // Leaving the camera on after navigating away keeps the phone's indicator
    // light burning and the battery draining, which reads as spyware.
    this.stopCamera();

    this.timers.forEach(clearInterval);

    if (typeof window !== 'undefined') {
      window.removeEventListener('online', this.onOnline);
      window.removeEventListener('offline', this.onOffline);
    }
  }
}
