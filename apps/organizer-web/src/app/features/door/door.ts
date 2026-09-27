import { Component, ElementRef, OnDestroy, computed, effect, inject, signal, viewChild } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { TimeoutError, firstValueFrom, timeout } from 'rxjs';
import {
  CameraRefusal,
  NotATicketNote,
  PageCamera,
  PartySize,
  RepeatReads,
  askingAbout,
  asksHowMany,
  canScanInPage,
  conflictsIn,
  fetchDecoderAhead,
  partOfParty,
  partyChoices,
  partyKey,
  partySize,
  queuedParty,
  ticketCode,
  unrecordedAdmission,
} from '@myfiesta/door';
import { UiButton } from '@myfiesta/ui';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { eventIdFrom } from '../../core/event-id';
import { Api } from '../../core/api';
import { OfflineScan, ScanResult, SyncResult } from '../../core/api.types';
import { DoorOffline, scanId } from '../../core/door-offline';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';
import { DoorPassStore } from '../../core/door-pass';
import { EventWorkspace } from '../events/event-workspace';
import { DoorPasses } from './door-passes';

/** Why the camera did not start, ending where every one of them ends: the code box. */
const CAMERA_REFUSED: Record<CameraRefusal, string> = {
  permission: 'The camera was refused. Type the code instead, or allow the camera for this site in the browser’s settings.',
  'no-camera': 'No camera was found. Type the code instead — it works exactly the same.',
  unavailable: 'The camera could not start. Type the code instead — it works exactly the same.',
};

/**
 * The door.
 *
 * The scan endpoint, partial admission, ticket_scans and the ledger all existed
 * and were tested; there was no screen to scan with, so none of it could be
 * used on a night.
 *
 * Two ways in, and the manual one is not a fallback for form's sake. The
 * camera reads with the browser's own BarcodeDetector where it has one that
 * reads QR codes, and with ZXing compiled to WebAssembly everywhere else —
 * every iPhone, Firefox, Chrome on Windows — through the same camera the phone
 * app uses on an iPhone. But a door with a flat battery, a cracked lens or a
 * guest whose screen will not brighten still has to work. Typing the code is
 * the path that never fails, so it is always visible rather than hidden behind
 * "having trouble?".
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
  /** Whether the screen has been left, for work started before then to stop — see `startOffline`. */
  private left = false;

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

    // The decoder, fetched while there is signal, for the same reason as the
    // ticket list: the service worker keeps it only once it has been asked
    // for, and a camera first started in the basement has nobody to ask.
    if (this.cameraSupported) void fetchDecoderAhead();

    // The question is put where the verdict goes, at the top. On a laptop the
    // camera is further down the page, and a door looking at the preview
    // would otherwise not see that it has been asked anything.
    effect(() => this.chooser()?.nativeElement.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' }));
  }

  /**
   * Load what this phone already saved, then freshen it while there is signal.
   *
   * The saved list is loaded first so a phone that opens this screen with no
   * connection — reopened in a basement — is ready immediately instead of
   * waiting on a download that will never arrive.
   *
   * Each step stops if the screen has been left while it was waiting, as the
   * phone app's door does. The first sync can take a while on a slow wifi,
   * and a screen left before it finished used to go on and fetch the list,
   * then set the timers going after they had been cleared, with nothing left
   * to stop them: this event's scans sent and its list fetched all night by a
   * door nobody could see, whichever event's door was open.
   */
  private async startOffline(): Promise<void> {
    if (!this.offlineSupported) return;

    const saved = await this.offline.load(this.eventId).catch(() => null);

    if (this.left) return;

    if (saved) {
      this.listCount.set(saved.count);
      this.listUpdatedAt.set(new Date(saved.generated_at));
    }

    // Kept on the phone, not only on this screen: see `unrecorded`.
    this.unrecorded.set(await this.offline.unrecordedAdmissions(this.eventId).catch(() => []));
    await this.updatePending();

    // Scans still waiting means this phone was offline when it last scanned.
    // Assumed still offline until a request succeeds, rather than until one
    // fails: navigator.onLine is true on a wifi with no route out, and the
    // failure can take the whole timeout to arrive — long enough to tell a
    // door "ready" while nothing is getting through.
    if (this.pendingCount() > 0) this.connectionLost.set(true);

    await this.syncAndRefresh();
    if (this.left) return;

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
    if (sent && !this.left) await this.refreshList();
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
   *
   * What the door got wrong is read out of the answer by `conflictsIn`. The
   * server names it, including for a scan that reached it online and was
   * queued when its answer did not come back — it compares the door's decision
   * with its own — and `conflictsIn` compares on the door only for an answer
   * that carries no decision to compare.
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

    let batch: OfflineScan[] = [];

    try {
      for (let i = 0; i < pending.length; i += 200) {
        batch = pending.slice(i, i + 200);
        const result = await firstValueFrom(
          this.api
            .syncScans(
              this.eventId,
              batch.map(({ event_id: _event, ...scan }) => scan),
            )
            .pipe(timeout(15_000)),
        );

        await this.offline.forget(result.data.map((row) => row.client_id));

        const conflicts = conflictsIn(batch, result);

        if (conflicts.length > 0) {
          this.conflicts.update((all) => [...all, ...conflicts]);
        }
      }

      this.connectionLost.set(false);

      return true;
    } catch (error) {
      if (this.isConnectionFailure(error)) this.connectionLost.set(true);
      else await this.dropUnsendable(batch, error).catch(() => undefined);

      return false;
    } finally {
      this.syncing.set(false);
      await this.updatePending();
    }
  }

  /**
   * Stop sending the scans the server says it can never accept.
   *
   * The server refuses a batch whole if one scan in it does not validate, so a
   * single scan it will never take — a code longer than any ticket's, or a
   * party of 80 — would hold every scan behind it on this phone all night.
   * Which ones may go is decided in `@myfiesta/door`, the same for the phone
   * app: only those whose code or party the server named. The rest go with
   * the next sync. The ones that let somebody in stay on the phone, unsent,
   * and are said on the door (`unrecorded`).
   */
  private async dropUnsendable(batch: OfflineScan[], error: unknown): Promise<void> {
    if (!(error instanceof HttpErrorResponse) || error.status !== 422) return;

    const body = error.error as { errors?: Record<string, unknown> } | null;
    const admissions = await this.offline.dropUnsendable(batch, body?.errors);
    const kept = new Set(admissions.map((scan) => scan.client_id));

    if (admissions.length > 0) {
      this.unrecorded.update((all) => [...all.filter((scan) => !kept.has(scan.client_id)), ...admissions]);
    }
  }

  /**
   * People let in offline on scans the server could not take.
   *
   * Never sent again, because they never can be as they are — but nobody else
   * knows those people went in, and the ticket still reads as unused. Said on
   * the door until somebody dismisses it, and kept on the phone until then
   * too: this screen does not outlive a reload, a tab the browser discarded in
   * the background, or somebody leaving the door screen, and the admission
   * would be on nobody's record at all. Read back when the door opens again.
   */
  readonly unrecorded = signal<OfflineScan[]>([]);

  readonly unrecordedMessage = unrecordedAdmission;

  dismissConflicts(): void {
    this.conflicts.set([]);
  }

  /** Read by door staff: off the screen, and off the phone. */
  dismissUnrecorded(): void {
    const read = this.unrecorded().map((scan) => scan.client_id);

    this.unrecorded.set([]);

    if (read.length > 0) void this.offline.forget(read).catch(() => undefined);
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
  /** Between the tap and a live preview: the permission question, and on first use the decoder loading. */
  readonly cameraStarting = signal(false);
  readonly cameraError = signal<string | null>(null);

  private readonly camera = new PageCamera();

  /**
   * The preview, which is in the page whether or not the camera is running:
   * frames are read off this element, so a camera whose video is only created
   * once it is running can never start. Folded away rather than removed.
   */
  private readonly preview = viewChild<ElementRef<HTMLVideoElement>>('preview');

  /** What the camera has already acted on, so one ticket held up is one scan. */
  private readonly reads = new RepeatReads();

  /** Whether the camera has just been shown a QR code that is not a ticket's. */
  readonly notATicket = signal(false);

  /** When to say so and when to stop, by the phone's rules — see `NotATicketNote`. */
  private readonly notATicketNote = new NotATicketNote((showing) => this.notATicket.set(showing));

  /**
   * Whether this browser can read a QR code with its camera: a camera it is
   * allowed to ask for, and the engine's own detector or WebAssembly to decode
   * with. Every current browser has the second, iPhones included.
   */
  readonly cameraSupported = canScanInPage();

  /**
   * Browsers only offer the camera over https, so a console opened over plain
   * http — typed into a phone at a venue — has none to ask for. Said as that,
   * because it is the one reason somebody at a door can do something about.
   */
  readonly cameraNeedsHttps = typeof window !== 'undefined' && !window.isSecureContext;

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

  /** "2 of 4 in — 2 still to come", for a table let in part-way. */
  readonly partOfParty = partOfParty;

  // --- how many are here -----------------------------------------------------

  /**
   * A ticket for more than one, scanned with nothing in "How many": the code,
   * what the server (or this phone's list) said about it, and the id the scan
   * went with.
   *
   * Nobody has gone in. It used to let in everyone the ticket had left, so a
   * table's ticket shown by the first of its guests counted the whole table in,
   * and the rest walked in later past a door that had already counted them.
   * Now the door says how many are standing there, with the camera paused so
   * the next ticket in the queue is not read over the question, and the scan
   * goes again with that number — under the same id: see `choose`.
   */
  readonly asking = signal<{ code: string; result: ScanResult; clientId: string } | null>(null);

  /** "All 4 here", and each smaller number or a stepper — see `partyChoices`. */
  readonly choices = computed(() => partyChoices(this.asking()?.result.remaining ?? 0));

  /** Whose ticket, what it is and how many are in, beside the question. */
  readonly askingAbout = computed(() => {
    const asked = this.asking();

    return asked ? askingAbout(asked.result) : '';
  });

  /** The stepper's number, for a table too big for a button each. */
  readonly stepped = signal(1);

  private readonly chooser = viewChild<ElementRef<HTMLElement>>('chooser');

  /**
   * Let this many of the party in.
   *
   * Sent straight away, never back through the camera: the ticket was read a
   * moment ago and is very likely still held up, and the camera would take a
   * read of it now for the same scan again and hold it back. See
   * `RepeatReads.again`.
   *
   * Under the id of the scan that asked. Nothing is recorded for a question,
   * so the id is free when the server asked it. When this phone asked, from
   * its list after the server took too long, the server may have let that
   * scan in on the one place left and the answer never came back: sent under
   * a new id, the answer was refused as a second person on a spent ticket,
   * or synced as a conflict, for the guest the server had just counted in.
   */
  choose(party: number): void {
    const asked = this.asking();

    if (!asked || this.busy()) return;

    this.asking.set(null);
    this.reads.again(asked.code);

    void this.send(asked.code, party, asked.clientId).finally(() => {
      this.reads.answered(asked.code);
      this.camera.resume();
    });
  }

  step(by: 1 | -1): void {
    const most = this.choices().upTo ?? 1;

    this.stepped.update((n) => Math.min(most, Math.max(1, n + by)));
  }

  /**
   * Nobody after all — the guest went back for the others, or it was the wrong
   * ticket. Nothing was recorded, so there is nothing to undo; the camera reads
   * again, and the same ticket once it has been out of sight for a moment.
   */
  notNow(): void {
    const asked = this.asking();

    if (!asked) return;

    this.asking.set(null);
    this.reads.answered(asked.code);
    this.camera.resume();
  }

  private ask(code: string, result: ScanResult, clientId: string): void {
    // The last guest's verdict comes down: left up, its green "Let them in"
    // reads as the answer for the table in front of the door now.
    this.outcome.set(null);
    this.stepped.set(1);
    this.asking.set({ code, result, clientId });
    this.camera.pause();
    this.notATicketNote.clear();
  }

  submit(): void {
    if (!this.code().trim() || this.busy()) return;

    // Typed while the door was asking: this is the next scan, and the
    // question is dropped rather than left on screen answering nothing.
    this.notNow();

    // Checked before it goes anywhere, typed or not: a code no ticket could
    // have, saved while offline, would hold every scan queued behind it.
    const code = ticketCode(this.code());

    if (!code) {
      this.outcome.set(null);
      this.error.set(
        'That is not a ticket code. Ticket codes are letters and numbers, like WFY7-F77K4EJW.',
      );

      return;
    }

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
   *
   * @param chosen how many the door said are here, when it was asked; what
   *               is in "How many" otherwise.
   * @param clientId the id of the scan that asked, for the scan answering it —
   *                 see `choose`. A new one otherwise.
   */
  private async send(code: string, chosen?: number, clientId: string = scanId()): Promise<void> {
    const size: PartySize = chosen === undefined ? this.partyTyped() : { ok: true, party: chosen };

    // Said before anything is sent or decided, camera or typed: offline, a
    // party of 1.5 would be decided, and then hold the queue it sits in.
    if (!size.ok) {
      this.outcome.set(null);
      this.error.set(size.message);

      return;
    }

    this.busy.set(true);
    this.error.set(null);

    const party = size.party;

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
      // know who was turned away as much as who went in. Not the question of
      // how many are here, which let nobody in and turned nobody away — the
      // scan that answers it is queued instead, with the number that went in.
      if (!asksHowMany(outcome)) {
        await this.offline.enqueue({
          client_id: clientId,
          event_id: this.eventId,
          code,
          party: queuedParty(party, outcome),
          offline_result: outcome.result,
          scanned_at: new Date().toISOString(),
        });

        await this.updatePending();
      }
    }

    this.busy.set(false);

    // Not a verdict, so not counted as a scan: the one that answers it is.
    if (asksHowMany(outcome)) {
      this.ask(code, outcome, clientId);
      this.code.set('');

      return;
    }

    this.outcome.set(outcome);
    this.scannedHere.update((n) => n + 1);
    this.admittedHere.update((n) => n + outcome.admitted);

    // Cleared so the next guest can be scanned without a delete. The party
    // size is cleared too — it belongs to one ticket, and carrying it over
    // would silently admit four people on the next single ticket.
    this.code.set('');
    this.party.set('');
  }

  /** The "How many" box, asked whether the browser could read what was typed into it. */
  private readonly partyBox = viewChild<ElementRef<HTMLInputElement>>('partyBox');

  /**
   * "How many", as a scan sends it — see `partySize`. A box the browser could
   * not read reports itself empty, which would send what somebody typed as no
   * number at all, so it is refused as what it is instead.
   */
  private partyTyped() {
    return partySize(this.partyBox()?.nativeElement.validity?.badInput ? NaN : this.party());
  }

  /** Keeps the box to digits as they are typed; what is pasted is checked on the way out. */
  wholeNumbersOnly(event: KeyboardEvent): void {
    if (!partyKey(event.key, event.ctrlKey || event.metaKey)) event.preventDefault();
  }

  // --- the camera ---------------------------------------------------------

  /**
   * The camera inside the page, from `@myfiesta/door` — the same one the phone
   * app reads with on an iPhone, which reads a few frames a second rather than
   * sixty: a door phone runs for hours on one charge.
   */
  async startCamera(): Promise<void> {
    const video = this.preview()?.nativeElement;

    if (this.scanning() || this.cameraStarting() || !this.cameraSupported || !video) return;

    this.cameraError.set(null);
    this.cameraStarting.set(true);

    try {
      const refusal = await this.camera.start(video, (code) => this.read(code), {
        onLive: () => this.scanning.set(true),
        // The phone locked, or the tab went to the background: back to the
        // button rather than a black frame that never reads.
        onEnded: () => {
          this.scanning.set(false);
          this.notATicketNote.clear();
        },
      });

      if (refusal) this.cameraError.set(CAMERA_REFUSED[refusal]);
    } finally {
      this.cameraStarting.set(false);
    }
  }

  /**
   * A code the camera saw.
   *
   * Only a ticket's, and only once while it is held up: the camera reads
   * whatever is in front of it several times a second, and a scan admits
   * somebody. See `ticketCode` and `RepeatReads` for why each rule is what it
   * is.
   *
   * A QR code that is not a ticket's is not a scan at all, but it is said over
   * the preview, as the phone app says it. This door used to stay quiet, on
   * the grounds that such a code is usually a poster behind the guest — and a
   * door shown a guest's payment code stared at a camera that did nothing.
   * The poster is still not mentioned while a ticket is in view beside it:
   * see `NotATicketNote`.
   */
  private read(raw: string): void {
    // Paused while the door is asked how many are here; a read already on its
    // way when it paused goes nowhere either.
    if (this.asking()) return;

    const code = ticketCode(raw);
    const now = Date.now();

    if (!code) {
      this.notATicketNote.sawSomethingElse(now);

      return;
    }

    this.notATicketNote.sawTicket(now);

    if (!this.reads.take(code, this.busy(), now)) return;

    this.code.set(code);
    void this.send(code).finally(() => this.reads.answered(code));
  }

  stopCamera(): void {
    this.camera.stop();
    this.scanning.set(false);
    this.notATicketNote.clear();
  }

  ngOnDestroy(): void {
    // Leaving the camera on after navigating away keeps the phone's indicator
    // light burning and the battery draining, which reads as spyware.
    this.stopCamera();

    this.left = true;
    this.timers.forEach(clearInterval);
    this.timers = [];

    if (typeof window !== 'undefined') {
      window.removeEventListener('online', this.onOnline);
      window.removeEventListener('offline', this.onOffline);
    }
  }
}
