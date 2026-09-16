import { Component, inject, input, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Api, ApiError } from '../../core/api';
import { SessionStore } from '../../core/session';
import { longEventTime } from '../../core/event-time';
import { MfButton, MfCard, MfScreen, MfSkeleton } from '../../ui';

/**
 * Where a door link lands.
 *
 * One tap, and it has to be a tap. A link that claimed itself on open would be
 * spent by whatever previewed it in the chat it arrived in, and a door pass
 * works exactly once.
 *
 * Nothing here needs an account: the link is the whole credential, which is
 * the point — venue staff hired for one night should not be given a login to
 * the organization that hired them.
 */
@Component({
  selector: 'mf-door-pass',
  imports: [MfScreen, MfCard, MfButton, MfSkeleton],
  template: `
    <mf-screen title="Door pass">
      @if (loading()) {
        <mf-card><mf-skeleton height="6rem" /></mf-card>
      } @else if (refused(); as message) {
        <mf-card>
          <h2>This link does not work</h2>
          <p class="muted">{{ message }}</p>
          <button mfButton variant="secondary" class="mt" (click)="signIn()">Sign in instead</button>
        </mf-card>
      } @else if (pass(); as details) {
        <mf-card>
          <p class="label">{{ details.label }}</p>
          <h2>{{ details.event.title }}</h2>
          <p class="muted">{{ when(details.event.starts_at, details.event.timezone) }}</p>
          @if (details.event.venue) {
            <p class="muted">{{ details.event.venue }}</p>
          }

          @if (details.event.min_age || details.event.id_required) {
            <p class="rule">
              @if (details.event.min_age) {
                {{ details.event.min_age }}+ ·
              }
              {{ details.event.id_required ? 'Photo ID required' : 'Ask for ID if in doubt' }}
            </p>
          }
        </mf-card>

        <p class="note muted">
          This turns this phone into the scanner for this event. It works once — open it on the
          phone that will work the door.
        </p>

        <button mfButton size="lg" block label="Opening…" [loading]="opening()" (click)="claim()">
          Start scanning on this phone
        </button>
      }
    </mf-screen>
  `,
  styles: `
    .label {
      font-size: var(--font-size-xs);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--text-subtle);
    }

    .rule {
      margin-top: var(--space-3);
      padding: var(--space-2) var(--space-3);
      border-radius: var(--radius-md);
      background: color-mix(in srgb, var(--warning) 12%, transparent);
      color: var(--warning);
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-semibold);
    }

    .note {
      margin: var(--space-5) 0;
      font-size: var(--font-size-sm);
      line-height: var(--font-leading-snug);
    }

    .mt {
      margin-top: var(--space-4);
    }
  `,
})
export class DoorPassOpen {
  private readonly api = inject(Api);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);

  /** From the route: the secret in the link. */
  readonly secret = input.required<string>();

  readonly pass = signal<Awaited<ReturnType<Api['doorPass']>> | null>(null);
  readonly loading = signal(true);
  readonly opening = signal(false);
  readonly refused = signal<string | null>(null);

  constructor() {
    queueMicrotask(() => void this.look());
  }

  private async look(): Promise<void> {
    try {
      const pass = await this.api.doorPass(this.secret());

      if (pass.state !== 'waiting') {
        this.refused.set(
          pass.state === 'active'
            ? 'This link is already open on another phone. Each phone needs its own.'
            : 'This pass has expired or was taken back. Ask for a new link.',
        );
      }

      this.pass.set(pass);
    } catch (error) {
      this.refused.set(error instanceof ApiError ? error.message : 'That link is not valid.');
    } finally {
      this.loading.set(false);
    }
  }

  async claim(): Promise<void> {
    if (this.opening()) return;

    this.opening.set(true);

    try {
      const claimed = await this.api.claimDoorPass(this.secret());

      await this.session.startFromDoorPass(claimed);
      await this.router.navigate(['/door'], { replaceUrl: true });
    } catch (error) {
      this.refused.set(error instanceof ApiError ? error.message : 'That link could not be opened.');
      this.opening.set(false);
    }
  }

  when(iso: string, timezone: string): string {
    return longEventTime(iso, timezone);
  }

  signIn(): void {
    void this.router.navigate(['/sign-in'], { replaceUrl: true });
  }
}
