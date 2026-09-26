import { Component, ElementRef, OnDestroy, computed, inject, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Haptics, ImpactStyle, NotificationType } from '@capacitor/haptics';
import {
  NotATicketNote,
  RepeatReads,
  partyKey,
  partySize,
  scanId,
  ticketCode,
  unrecordedAdmission,
} from '@myfiesta/door';
import { DoorOffline } from '../../core/door-offline';
import { Api, ApiError, ScanResult } from '../../core/api';
import { SessionStore } from '../../core/session';
import { Scanner } from '../../core/scanner';
import { MfBadge, MfButton, MfCard, MfField, MfScreen, ToastStore } from '../../ui';
import { DoorSell } from './door-sell';

interface Outcome {
  id: number;
  at: Date;
  code: string;
  result: ScanResult;
}

/**
 * The door: a camera, a box to type into, and a verdict the size of the screen.
 *
 * The verdict is what the screen is for. At a dark door, at arm's length, with
 * a queue behind, the only question is whether this person walks in — so it is
 * two words and a colour, and everything else on the screen is smaller than it.
 *
 * Typing is always available rather than hidden behind "having trouble?". A
 * cracked lens, a flat battery and a guest whose screen will not brighten all
 * end at the same place, and at that moment nobody is hunting for a link.
 *
 * The phone buzzes on every decision. In a room loud enough that nobody hears
 * anything, the wrist is the channel that still works.
 */
@Component({
  selector: 'mf-door',
  imports: [FormsModule, MfScreen, MfCard, MfField, MfButton, MfBadge, DoorSell],
  template: `
    <mf-screen title="Door" [subtitle]="eventTitle()">
      <button mfButton variant="ghost" size="sm" screenActions (click)="leave()">
        {{ locked() ? 'End shift' : 'Done' }}
      </button>

      @if (!eventId()) {
        <mf-card>
          <h2>No event on this phone</h2>
          <p class="muted">
            Open the door link you were sent, or pick an event from your list and tap Scan.
          </p>
        </mf-card>
      } @else {
        <!--
          The camera, when this phone has one. Above everything else because a
          door scans far more often than it types, and the frame is deliberately
          large: a small preview is one somebody holds at the wrong distance.
        -->
        <!--
          The preview stays in the page whether or not it is running: on an
          iPhone, and in any browser, the scanner reads frames off this
          element, so a camera that is only created once scanning starts can
          never start. Folded away rather than removed when idle.
        -->
        <section class="camera" [class.native]="nativePreview()" [class.idle]="!scanning()">
          <video #preview class="preview" [class.hidden]="nativePreview()" muted playsinline autoplay></video>
          <div class="reticle" aria-hidden="true"></div>

          @if (scanning() && notATicket()) {
            <p class="not-a-ticket" role="status">That QR code is not a ticket. Ask to see the ticket itself.</p>
          }

          @if (scanning()) {
            <button mfButton class="stop" variant="secondary" size="sm" (click)="stopCamera()">
              Stop the camera
            </button>
          }
        </section>

        @if (offlineNow()) {
          <!-- Said plainly, because it changes what the answers mean: this
               phone is deciding from a list, and a ticket bought in the last
               few minutes is not on it. -->
          <p class="no-signal" role="status">
            <strong>No signal.</strong>
            @if (offlineReady()) {
              Deciding from the list saved on this phone.
              @if (waiting() > 0) {
                {{ waiting() }} {{ waiting() === 1 ? 'scan is' : 'scans are' }} waiting to be sent.
              }
            } @else {
              There is no list saved on this phone, so nothing can be checked until signal is back.
            }
          </p>
        } @else if (waiting() > 0) {
          <p class="no-signal" role="status">
            Sending {{ waiting() }} {{ waiting() === 1 ? 'scan' : 'scans' }} made while offline…
          </p>
        }

        @if (conflicts().length > 0) {
          <!-- The server disagreed with what this phone decided. Somebody was
               let in who should not have been, or turned away who should not
               have been, and door staff have to hear about it while the person
               is still in the room. -->
          <div class="clash" role="alert">
            <p>
              <strong>
                The server disagreed with
                {{ conflicts().length === 1 ? 'a scan' : conflicts().length + ' scans' }}
                made offline
              </strong>
            </p>
            @for (clash of conflicts(); track clash.client_id) {
              <p class="why">{{ clash.message }}</p>
            }
            <button mfButton variant="secondary" size="sm" (click)="dismissConflicts()">Got it</button>
          </div>
        }

        @if (unrecorded().length > 0) {
          <!-- People let in offline on a scan the server could not take. The
               scan can never be sent, so this phone is the only record that
               they went in: kept on it until somebody taps Got it. -->
          <div class="clash" role="alert">
            <p>
              <strong>
                {{ unrecorded().length === 1 ? 'A scan' : unrecorded().length + ' scans' }}
                made offline could not be recorded
              </strong>
            </p>
            @for (scan of unrecorded(); track scan.client_id) {
              <p class="why">{{ unrecordedMessage(scan) }}</p>
            }
            <button mfButton variant="secondary" size="sm" (click)="dismissUnrecorded()">Got it</button>
          </div>
        }

        <div class="counts">
          <mf-card>
            <p class="figure tabular">{{ admitted() }}</p>
            <p class="label">admitted here</p>
          </mf-card>
          <mf-card>
            <p class="figure tabular">{{ scanned() }}</p>
            <p class="label">scanned</p>
          </mf-card>
        </div>

        @if (last(); as outcome) {
          <section
            class="verdict"
            [class.in]="outcome.result.accepted"
            [class.out]="!outcome.result.accepted"
            role="status"
            aria-live="assertive"
          >
            <p class="word">{{ outcome.result.accepted ? 'Let them in' : 'Do not admit' }}</p>
            <p class="why">{{ outcome.result.message }}</p>
            @if (outcome.result.ticket?.holder_name) {
              <p class="who">{{ outcome.result.ticket?.holder_name }}</p>
            }
            <!-- Only for a table that was let in part-way: how many are
                 still to come. On a refusal it reads as a reason to let
                 somebody in. -->
            @if (outcome.result.accepted && outcome.result.remaining > 0) {
              <p class="who">{{ outcome.result.remaining }} of the party still outside</p>
            }

            <!-- What this person answered at checkout: the name to check
                 against an ID, the table they are on, what they need. Never
                 on an offline decision — the saved list carries hashes and a
                 name, never the answers. -->
            @for (answer of outcome.result.ticket?.answers ?? []; track answer.label) {
              <p class="answer"><span class="answer__label">{{ answer.label }}</span> {{ answer.value }}</p>
            }
          </section>
        }

        @if (!scanning()) {
          @if (cameraReady()) {
            <button mfButton class="scan" variant="secondary" size="lg" block (click)="startCamera()">
              Scan with the camera
            </button>
          }

          <!-- Said before it is tried as well as after: a door that cannot
               scan should learn that from the screen, not from a button that
               is not there. -->
          @if (cameraNote(); as why) {
            <p class="camera-note">{{ why }}</p>
          }
        }

        <form class="entry" (ngSubmit)="submit()">
          <mf-field label="Ticket code" [error]="error()">
            <input
              #control
              name="code"
              class="code"
              autocomplete="off"
              autocapitalize="characters"
              autocorrect="off"
              spellcheck="false"
              enterkeyhint="go"
              placeholder="WFY7-F77K4EJW"
              [ngModel]="code()"
              (ngModelChange)="code.set($event)"
            />
          </mf-field>

          <!-- For a table arriving in two groups. Whole people only: step 1
               and a numeric keypad, and the keys a number box allows that no
               party needs are kept out as they are typed. -->
          <mf-field
            label="How many"
            hint="Leave blank for an ordinary ticket. For a table arriving in two groups, how many are going in now."
            [error]="partyError()"
          >
            <input
              #partyBox
              name="party"
              type="number"
              inputmode="numeric"
              min="1"
              max="50"
              step="1"
              enterkeyhint="go"
              placeholder="All"
              [ngModel]="party()"
              (ngModelChange)="party.set($event); partyError.set(null)"
              (keydown)="wholeNumbersOnly($event)"
            />
          </mf-field>

          <button mfButton type="submit" size="lg" block label="Checking…" [loading]="busy()" [disabled]="!code().trim()">
            Check in
          </button>
        </form>

        <!--
          Walk-ups. Under the scanner and the code box rather than beside
          them: most people at a door already hold a ticket, and the two
          things that admit them come first.
        -->
        <button mfButton class="sell" block variant="secondary" size="lg" (click)="openSell()">
          Sell a ticket
        </button>

        <mf-door-sell
          [open]="selling()"
          [eventId]="eventId() ?? ''"
          (closed)="sellingClosed($event)"
          (admitted)="admitSold($event)"
        />

        @if (recent().length > 0) {
          <h2 class="section">Last few</h2>
          <ul class="recent">
            @for (outcome of recent(); track outcome.id) {
              <li>
                <span class="time tabular">{{ time(outcome.at) }}</span>
                <span class="code-small tabular">{{ outcome.code }}</span>
                <mf-badge [tone]="outcome.result.accepted ? 'success' : 'danger'">
                  {{ outcome.result.accepted ? 'In' : 'No' }}
                </mf-badge>
              </li>
            }
          </ul>
        }
      }
    </mf-screen>
  `,
  styles: `
    .camera {
      position: relative;
      margin-bottom: var(--space-4);
      border-radius: var(--radius-lg);
      overflow: hidden;
      background: #000;
      aspect-ratio: 4 / 3;
    }

    /* Folded away, not removed: the element has to exist for the scanner to
       read frames off it. */
    .camera.idle {
      position: absolute;
      width: 1px;
      height: 1px;
      margin: 0;
      opacity: 0;
      pointer-events: none;
    }

    /* ML Kit's camera is behind the page, so this frame is a window rather
       than a container with a picture in it. */
    .camera.native {
      background: transparent;
      box-shadow: 0 0 0 2px var(--border-strong);
    }

    .preview {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .preview.hidden {
      display: none;
    }

    .reticle {
      position: absolute;
      inset: 18% 22%;
      border: 3px solid rgb(255 255 255 / 0.85);
      border-radius: var(--radius-md);
      box-shadow: 0 0 0 100vmax rgb(0 0 0 / 0.28);
    }

    .camera.native .reticle {
      box-shadow: none;
    }

    .stop {
      position: absolute;
      left: 50%;
      bottom: var(--space-3);
      transform: translateX(-50%);
    }

    .not-a-ticket {
      position: absolute;
      top: var(--space-3);
      left: var(--space-3);
      right: var(--space-3);
      margin: 0;
      padding: var(--space-2) var(--space-3);
      border-radius: var(--radius-md);
      background: rgb(0 0 0 / 0.72);
      color: #fff;
      font-size: var(--font-size-sm);
      line-height: var(--font-leading-snug);
      text-align: center;
    }

    .scan {
      margin-top: var(--space-4);
    }

    .camera-note {
      margin-top: var(--space-4);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .no-signal {
      margin: 0 0 var(--space-4);
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-md);
      background: var(--surface-inset);
      color: var(--text);
      font-size: var(--font-size-sm);
      line-height: var(--font-leading-snug);
    }

    .clash {
      display: grid;
      gap: var(--space-2);
      margin: 0 0 var(--space-4);
      padding: var(--space-4);
      border-radius: var(--radius-md);
      background: color-mix(in srgb, var(--danger) 12%, transparent);
      color: var(--danger-text);
      font-size: var(--font-size-sm);
      line-height: var(--font-leading-snug);
    }

    .clash p {
      margin: 0;
    }

    .clash button {
      justify-self: start;
    }

    .counts {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-3);
    }

    .figure {
      font-size: var(--font-size-3xl);
      font-weight: var(--font-weight-bold);
      line-height: 1;
      color: var(--primary-text);
    }

    .label {
      margin-top: var(--space-1);
      font-size: var(--font-size-xs);
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--text-muted);
    }

    .verdict {
      display: grid;
      gap: var(--space-2);
      margin-top: var(--space-4);
      padding: var(--space-6) var(--space-5);
      border-radius: var(--radius-lg);
      border: 2px solid;
      text-align: center;
    }

    .verdict.in {
      border-color: var(--success);
      background: color-mix(in srgb, var(--success) 12%, transparent);
      color: var(--success);
    }

    .verdict.out {
      border-color: var(--danger);
      background: color-mix(in srgb, var(--danger) 10%, transparent);
      color: var(--danger-text);
    }

    .word {
      font-size: var(--font-size-2xl);
      font-weight: var(--font-weight-bold);
      line-height: var(--font-leading-tight);
    }

    .why {
      font-size: var(--font-size-base);
    }

    .answer {
      margin: var(--space-2) 0 0;
      font-size: var(--font-size-lg);
      font-weight: var(--font-weight-semibold);
    }

    .answer__label {
      display: block;
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-regular);
      text-transform: uppercase;
      letter-spacing: 0.06em;
      opacity: 0.75;
    }

    .who {
      font-size: var(--font-size-sm);
      opacity: 0.85;
    }

    .entry {
      display: grid;
      gap: var(--space-4);
      margin-top: var(--space-5);
    }

    .code {
      font-family: var(--font-family-mono);
      font-size: var(--font-size-lg);
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }

    .section {
      margin: var(--space-6) 0 var(--space-2);
      font-size: var(--font-size-xs);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--text-subtle);
    }

    .recent {
      display: grid;
      gap: var(--space-2);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .recent li {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      padding: var(--space-2) var(--space-3);
      border-radius: var(--radius-md);
      background: var(--surface-raised);
      font-size: var(--font-size-sm);
    }

    .time {
      color: var(--text-subtle);
    }

    .code-small {
      flex: 1;
      font-family: var(--font-family-mono);
      overflow: hidden;
      text-overflow: ellipsis;
    }
  `,
})
export class Door implements OnDestroy {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly toasts = inject(ToastStore);
  private readonly scanner = inject(Scanner);
  private readonly offline = inject(DoorOffline);
  readonly session = inject(SessionStore);

  private readonly preview = viewChild<ElementRef<HTMLVideoElement>>('preview');

  readonly code = signal('');
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);
  readonly outcomes = signal<Outcome[]>([]);

  /**
   * "How many": blank, or the number Angular reads out of the box. Blank lets
   * in everyone still outstanding on the ticket — see `partySize`.
   */
  readonly party = signal<string | number | null>('');
  readonly partyError = signal<string | null>(null);

  /** The "How many" box, asked whether the browser could read what was typed into it. */
  private readonly partyBox = viewChild<ElementRef<HTMLInputElement>>('partyBox');

  readonly locked = computed(() => this.session.locked());

  /** A door pass names its event; an organizer arrives with one in the address. */
  readonly eventId = computed(
    () => this.session.session()?.eventId ?? this.route.snapshot.queryParamMap.get('event'),
  );

  readonly eventTitle = computed(
    () => this.session.session()?.eventTitle ?? this.route.snapshot.queryParamMap.get('title'),
  );

  /**
   * Whether the verdict on screen answers the last scan tried.
   *
   * Taken down when a scan goes nowhere — refused before it is sent, or
   * failed — as the console's is. Left up, the last guest's green "Let them
   * in" reads as the answer for the ticket held up now, and the only sign that
   * nothing was sent is a line of small print under a box further down.
   */
  private readonly verdictStands = signal(true);

  readonly last = computed(() => (this.verdictStands() ? (this.outcomes()[0] ?? null) : null));
  /** The scans before the verdict's, and that one too once its verdict is down. */
  readonly recent = computed(() => {
    const from = this.last() ? 1 : 0;

    return this.outcomes().slice(from, from + 5);
  });
  readonly scanned = computed(() => this.outcomes().length);
  readonly admitted = computed(() =>
    this.outcomes().reduce((total, outcome) => total + outcome.result.admitted, 0),
  );

  private next = 0;
  private timers: ReturnType<typeof setInterval>[] = [];
  /** Whether the screen has been left, for work started before then to stop — see `prepareForNoSignal`. */
  private left = false;

  // --- when the signal goes --------------------------------------------------

  readonly offlineReady = computed(() => this.offline.supported && this.offline.listCount() > 0);
  readonly offlineNow = this.offline.connectionLost;
  readonly waiting = this.offline.pendingCount;
  readonly listUpdatedAt = this.offline.listUpdatedAt;
  readonly conflicts = this.offline.conflicts;
  readonly unrecorded = this.offline.unrecorded;
  readonly unrecordedMessage = unrecordedAdmission;

  // --- the camera ------------------------------------------------------------

  readonly scanning = this.scanner.running;
  /** Null until the question has been asked; a button that flickers in is worse than one that waits. */
  readonly cameraReady = signal<boolean | null>(null);
  /**
   * Whether the camera is ML Kit's, behind the page, rather than a video in
   * it. Asked of the scanner rather than of the platform: an iPhone is native
   * and still reads with the camera inside the page.
   */
  readonly nativePreview = computed(() => this.scanner.engine() === 'mlkit');

  /** What the camera has already acted on, so one ticket held up is one scan. */
  private readonly reads = new RepeatReads();

  /** Whether the camera has just been shown a QR code that is not a ticket's. */
  readonly notATicket = signal(false);

  /**
   * When to say so and when to stop, out of `@myfiesta/door` so the console's
   * door says it on the same timing — see `NotATicketNote`.
   */
  private readonly notATicketNote = new NotATicketNote((showing) => this.notATicket.set(showing));

  readonly cameraNote = computed(() => {
    if (this.cameraReady() === false && this.scanner.refusal() === null) {
      return 'This phone cannot scan codes. Type the code from the ticket.';
    }

    switch (this.scanner.refusal()) {
      case 'permission':
        return 'The camera was refused. Type the code, or allow the camera in settings.';
      case 'unsupported':
        return 'This phone cannot scan codes. Type the code from the ticket.';
      case 'no-camera':
        return 'No camera was found. Type the code from the ticket.';
      case 'unavailable':
        return 'The camera could not start. Type the code from the ticket.';
      default:
        return null;
    }
  });

  constructor() {
    void this.scanner.supported().then((ready) => this.cameraReady.set(ready));
    void this.prepareForNoSignal();
  }

  /**
   * Get ready to work without the server.
   *
   * The list is fetched on arrival and every few minutes after, and the queue
   * is sent on a timer rather than only when the browser says it is online —
   * a venue wifi that quietly starts working again fires no such event.
   *
   * Each step stops if the screen has been left while it was waiting. The
   * first sync can take a while on a slow wifi, and a screen left before it
   * finished used to go on and set the timers anyway, after they had been
   * cleared, with nothing left to stop them: this event's list fetched every
   * three minutes all night, replacing the one another event's door was
   * deciding from.
   */
  private async prepareForNoSignal(): Promise<void> {
    const eventId = this.eventId();

    if (!eventId || !this.offline.supported) return;

    await this.offline.prepare(eventId);
    if (this.left) return;

    await this.offline.sync(eventId);
    if (this.left) return;

    await this.offline.refreshList(eventId);
    if (this.left) return;

    this.timers.push(setInterval(() => void this.offline.sync(eventId), 15_000));
    this.timers.push(setInterval(() => void this.offline.refreshList(eventId), 3 * 60_000));
  }

  async startCamera(): Promise<void> {
    await this.scanner.start((code) => this.read(code), this.preview()?.nativeElement);
  }

  dismissConflicts(): void {
    this.offline.dismissConflicts();
  }

  dismissUnrecorded(): void {
    void this.offline.dismissUnrecorded();
  }

  async stopCamera(): Promise<void> {
    await this.scanner.stop();
    this.notATicketNote.clear();
  }

  /**
   * A code the camera saw.
   *
   * Only a ticket's, and only once while it is held up, by the same rules as
   * the console's door, out of `@myfiesta/door`. This door used to act on any
   * QR in view — offline, one too long for the server was queued, and the
   * server then refused every sync with it in, so nothing else scanned without
   * signal got through. And it ignored the same code for four seconds from the
   * first sighting only: a ticket held up through a slow answer was sent again,
   * with the party size already cleared, which on a table's ticket lets in
   * everyone still outside. See `ticketCode` and `RepeatReads`.
   *
   * A QR code that is not a ticket's is not a scan, but it is said over the
   * preview for a few seconds — not while a ticket is in view beside it, when
   * it is only something behind. See `NotATicketNote`.
   */
  private read(raw: string): void {
    const code = ticketCode(raw);
    const now = Date.now();

    if (!code) {
      this.notATicketNote.sawSomethingElse(now);

      return;
    }

    this.notATicketNote.sawTicket(now);

    if (!this.reads.take(code, this.busy(), now)) return;

    this.code.set(code);
    void this.submit().finally(() => this.reads.answered(code));
  }

  /** Keeps "How many" to digits as they are typed; what is pasted is checked on the way out. */
  wholeNumbersOnly(event: KeyboardEvent): void {
    if (!partyKey(event.key, event.ctrlKey || event.metaKey)) event.preventDefault();
  }

  ngOnDestroy(): void {
    this.left = true;
    this.timers.forEach(clearInterval);
    this.timers = [];
    this.notATicketNote.clear();

    // Leaving the screen with the camera running is a phone that stays warm in
    // somebody's pocket all night, and on the native path a page that has lost
    // its background.
    void this.scanner.stop();
  }

  /** Whether the sell sheet is up. */
  readonly selling = signal(false);

  openSell(): void {
    // The camera and a sheet over it is a phone doing two things with one
    // lens; the scanner stops while money is being taken and starts again
    // after, which is also what a person does.
    void this.scanner.stop();
    this.scanning.set(false);
    this.selling.set(true);
  }

  async sellingClosed(sold: boolean): Promise<void> {
    this.selling.set(false);

    // A sale changed the count on the door list this phone decides from when
    // the signal goes, so it is refreshed rather than left a ticket short.
    const eventId = this.eventId();

    if (sold && eventId && this.offline.supported) await this.offline.refreshList(eventId);
  }

  /** Straight from selling to admitting, on the same phone. */
  admitSold(code: string): void {
    this.selling.set(false);
    this.code.set(code);
    void this.submit();
  }

  async submit(): Promise<void> {
    const eventId = this.eventId();

    if (!this.code().trim() || !eventId || this.busy()) return;

    // Checked before it goes anywhere, typed or read: a code no ticket could
    // have, queued while offline, would hold every scan behind it.
    const code = ticketCode(this.code());

    if (!code) {
      this.verdictStands.set(false);
      this.error.set('That is not a ticket code. Ticket codes are letters and numbers, like WFY7-F77K4EJW.');
      await this.buzz(false);

      return;
    }

    // And the number, before anything is sent or decided: offline, a party of
    // 1.5 would be decided like any other, then refused with its whole batch.
    // A box the browser could not read reports itself empty, which would let
    // the whole table in, so it is refused as what it is instead. Buzzed like
    // any refusal: a camera held up to a table's ticket otherwise does
    // nothing that anybody looking at the guest would notice.
    const size = partySize(this.partyBox()?.nativeElement.validity?.badInput ? NaN : this.party());

    if (!size.ok) {
      this.verdictStands.set(false);
      this.error.set(null);
      this.partyError.set(size.message);
      await this.buzz(false);

      return;
    }

    const party = size.party;
    // The scan's own id, the same online and queued: see `Api.scan`.
    const clientId = scanId();

    this.busy.set(true);
    this.error.set(null);
    this.partyError.set(null);

    try {
      let result: ScanResult;

      try {
        result = await this.api.scan(eventId, code, party, clientId);
        this.offline.connectionLost.set(false);

        // Counted on the phone too, so losing signal a minute from now does
        // not make it think a ticket it just admitted is still unused.
        if (result.accepted) await this.offline.admitLocally(code, party, result.ticket?.admitted_count);

        // Anything waiting goes now that the server is answering.
        if (this.offline.pendingCount() > 0) void this.offline.sync(eventId);
      } catch (error) {
        // Only the connection. A refusal is the server speaking, and a door
        // must not fall back to its own judgement because the server said no.
        if (!this.offline.lostConnection(error) || !this.offline.supported) throw error;

        this.offline.connectionLost.set(true);
        result = await this.offline.decideAndQueue(eventId, code, party, clientId);
      }

      this.outcomes.update((all) => [{ id: this.next++, at: new Date(), code, result }, ...all].slice(0, 40));
      this.verdictStands.set(true);
      this.code.set('');
      // The number belongs to one ticket. Carried over, the next guest's
      // ticket would be scanned with somebody else's party size.
      this.party.set('');

      await this.buzz(result.accepted);
    } catch (error) {
      const message = error instanceof ApiError ? error.message : 'That did not work.';

      if (error instanceof ApiError && error.status === 401) {
        // A door pass that has been taken back, or a night that is over.
        this.toasts.show('This pass has stopped working. Ask for a new link.', 'danger');
        await this.session.clear();
        await this.router.navigate(['/sign-in'], { replaceUrl: true });

        return;
      }

      this.verdictStands.set(false);
      this.error.set(message);
      await this.buzz(false);
    } finally {
      this.busy.set(false);
    }
  }

  /**
   * The wrist, because the room is too loud for anything else.
   *
   * A refusal is a heavier pattern than an admission on purpose: the two have
   * to be told apart without looking, since whoever is scanning is looking at
   * the guest, not the phone.
   */
  private async buzz(accepted: boolean): Promise<void> {
    try {
      if (accepted) await Haptics.impact({ style: ImpactStyle.Light });
      else await Haptics.notification({ type: NotificationType.Error });
    } catch {
      // No haptics on this device, or in a browser. The verdict is on screen.
    }
  }

  time(at: Date): string {
    return new Intl.DateTimeFormat('en-CA', { hour: 'numeric', minute: '2-digit' }).format(at);
  }

  async leave(): Promise<void> {
    await this.scanner.stop();

    if (this.locked()) {
      await this.session.signOut();
      await this.router.navigate(['/sign-in'], { replaceUrl: true });

      return;
    }

    await this.router.navigate(['/events']);
  }
}
