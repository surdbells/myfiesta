import { Component, OnDestroy, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Haptics, ImpactStyle, NotificationType } from '@capacitor/haptics';
import { Api, ApiError, ScanResult } from '../../core/api';
import { SessionStore } from '../../core/session';
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
            @if (outcome.result.remaining > 0) {
              <p class="who">{{ outcome.result.remaining }} of the party still outside</p>
            }
          </section>
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
  readonly session = inject(SessionStore);

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

  ngOnDestroy(): void {
    // Nothing to tear down yet; the camera lands here when the scanner plugin
    // does, and this is where it will be stopped.
  }

  async submit(): Promise<void> {
    const code = this.code().trim().toUpperCase();
    const eventId = this.eventId();

    if (!code || !eventId || this.busy()) return;

    this.busy.set(true);
    this.error.set(null);

    try {
      const result = await this.api.scan(eventId, code);

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
    if (this.locked()) {
      await this.session.signOut();
      await this.router.navigate(['/sign-in'], { replaceUrl: true });

      return;
    }

    await this.router.navigate(['/events']);
  }
}
