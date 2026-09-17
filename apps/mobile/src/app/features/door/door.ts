import { Component, ElementRef, OnDestroy, computed, inject, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Haptics, ImpactStyle, NotificationType } from '@capacitor/haptics';
import { Capacitor } from '@capacitor/core';
import { DoorOffline } from '../../core/door-offline';
import { Api, ApiError, ScanResult } from '../../core/api';
import { SessionStore } from '../../core/session';
import { Scanner } from '../../core/scanner';
import { MfBadge, MfButton, MfCard, MfField, MfScreen, ToastStore } from '../../ui';

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
  imports: [FormsModule, MfScreen, MfCard, MfField, MfButton, MfBadge],
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
          The preview stays in the page whether or not it is running: the
          browser scanner reads frames off this element, so a camera that is
          only created once scanning starts can never start. Folded away rather
          than removed when idle.
        -->
        <section class="camera" [class.native]="nativePreview" [class.idle]="!scanning()">
          <video #preview class="preview" [class.hidden]="nativePreview" muted playsinline></video>
          <div class="reticle" aria-hidden="true"></div>

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

          <button mfButton type="submit" size="lg" block label="Checking…" [loading]="busy()" [disabled]="!code().trim()">
            Check in
          </button>
        </form>

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

    /* Folded away, not removed: the element has to exist for the browser
       scanner to read frames off it. */
    .camera.idle {
      position: absolute;
      width: 1px;
      height: 1px;
      margin: 0;
      opacity: 0;
      pointer-events: none;
    }

    /* Natively the camera is behind the page, so this frame is a window
       rather than a container with a picture in it. */
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

  readonly locked = computed(() => this.session.locked());

  /** A door pass names its event; an organizer arrives with one in the address. */
  readonly eventId = computed(
    () => this.session.session()?.eventId ?? this.route.snapshot.queryParamMap.get('event'),
  );

  readonly eventTitle = computed(
    () => this.session.session()?.eventTitle ?? this.route.snapshot.queryParamMap.get('title'),
  );

  readonly last = computed(() => this.outcomes()[0] ?? null);
  readonly recent = computed(() => this.outcomes().slice(1, 6));
  readonly scanned = computed(() => this.outcomes().length);
  readonly admitted = computed(() =>
    this.outcomes().reduce((total, outcome) => total + outcome.result.admitted, 0),
  );

  private next = 0;
  private timers: ReturnType<typeof setInterval>[] = [];

  // --- when the signal goes --------------------------------------------------

  readonly offlineReady = computed(() => this.offline.supported && this.offline.listCount() > 0);
  readonly offlineNow = this.offline.connectionLost;
  readonly waiting = this.offline.pendingCount;
  readonly listUpdatedAt = this.offline.listUpdatedAt;
  readonly conflicts = this.offline.conflicts;

  // --- the camera ------------------------------------------------------------

  readonly scanning = this.scanner.running;
  /** Null until the question has been asked; a button that flickers in is worse than one that waits. */
  readonly cameraReady = signal<boolean | null>(null);
  readonly nativePreview = Capacitor.isNativePlatform();

  /** The last code the camera read, so one ticket held up is one request. */
  private lastRead = { code: '', at: 0 };

  readonly cameraNote = computed(() => {
    if (this.cameraReady() === false && this.scanner.refusal() === null) {
      return 'No camera this app can read codes with. Type the code from the ticket.';
    }

    switch (this.scanner.refusal()) {
      case 'permission':
        return 'The camera was refused. Type the code, or allow the camera in settings.';
      case 'unsupported':
        return 'This phone cannot scan. Type the code from the ticket.';
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
   */
  private async prepareForNoSignal(): Promise<void> {
    const eventId = this.eventId();

    if (!eventId || !this.offline.supported) return;

    await this.offline.prepare(eventId);
    await this.offline.sync(eventId);
    await this.offline.refreshList(eventId);

    this.timers.push(setInterval(() => void this.offline.sync(eventId), 15_000));
    this.timers.push(setInterval(() => void this.offline.refreshList(eventId), 3 * 60_000));
  }

  async startCamera(): Promise<void> {
    await this.scanner.start((code) => void this.read(code), this.preview()?.nativeElement);
  }

  dismissConflicts(): void {
    this.offline.dismissConflicts();
  }

  async stopCamera(): Promise<void> {
    await this.scanner.stop();
  }

  /**
   * A code the camera saw.
   *
   * The same ticket is read many times a second while it is in frame, and a
   * scan is not free: it admits somebody. So a code is acted on once, and the
   * same one is ignored for a few seconds afterwards — long enough for the
   * guest to walk through and the next to step up.
   */
  private async read(code: string): Promise<void> {
    const now = Date.now();

    if (this.busy()) return;
    if (code === this.lastRead.code && now - this.lastRead.at < 4000) return;

    this.lastRead = { code, at: now };
    this.code.set(code);

    await this.submit();
  }

  ngOnDestroy(): void {
    this.timers.forEach(clearInterval);
    this.timers = [];

    // Leaving the screen with the camera running is a phone that stays warm in
    // somebody's pocket all night, and on the native path a page that has lost
    // its background.
    void this.scanner.stop();
  }

  async submit(): Promise<void> {
    const code = this.code().trim().toUpperCase();
    const eventId = this.eventId();

    if (!code || !eventId || this.busy()) return;

    this.busy.set(true);
    this.error.set(null);

    try {
      let result: ScanResult;

      try {
        result = await this.api.scan(eventId, code);
        this.offline.connectionLost.set(false);

        // Counted on the phone too, so losing signal a minute from now does
        // not make it think a ticket it just admitted is still unused.
        if (result.accepted) await this.offline.admitLocally(code, null, result.ticket?.admitted_count);

        // Anything waiting goes now that the server is answering.
        if (this.offline.pendingCount() > 0) void this.offline.sync(eventId);
      } catch (error) {
        // Only the connection. A refusal is the server speaking, and a door
        // must not fall back to its own judgement because the server said no.
        if (!this.offline.lostConnection(error) || !this.offline.supported) throw error;

        this.offline.connectionLost.set(true);
        result = await this.offline.decideAndQueue(eventId, code, null);
      }

      this.outcomes.update((all) => [{ id: this.next++, at: new Date(), code, result }, ...all].slice(0, 40));
      this.code.set('');

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
