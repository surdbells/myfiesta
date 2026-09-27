import { Component, computed, inject } from '@angular/core';
import { EmailVerification } from '../../core/email-verification';
import { SessionStore } from '../../core/session';
import { MfButton, MfCard } from '../../ui';

/**
 * "Confirm your email address", at the top of Manage while it is unproved.
 *
 * Gentle, because most of what an organizer does works without it: building
 * a night, the team, the door. What waits is putting one on sale, asking to
 * be paid and changing where payouts go — said here before somebody presses
 * the button, with the way to get another link beside it.
 */
@Component({
  selector: 'mf-verify-email',
  imports: [MfCard, MfButton],
  template: `
    @if (verification.verified() === false) {
      <mf-card class="verify">
        <p class="title">Confirm your email address</p>
        <p class="muted">
          Open the link we sent to {{ email() }}. Until then you can build events, but not put one on sale, ask to be
          paid or change where payouts go.
        </p>
        <button mfButton class="again" size="sm" variant="secondary" [loading]="verification.sending()" (click)="verification.resend()">
          Send the link again
        </button>
        @if (verification.answer(); as said) {
          <p class="answer" role="status">{{ said }}</p>
        }
      </mf-card>
    }
  `,
  styles: `
    :host {
      display: block;
    }

    .verify {
      display: block;
      margin-bottom: var(--space-4);
    }

    .title {
      font-weight: var(--font-weight-semibold);
    }

    .muted {
      margin-top: var(--space-1);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      overflow-wrap: anywhere;
    }

    .again {
      margin-top: var(--space-3);
    }

    .answer {
      margin-top: var(--space-3);
      font-size: var(--font-size-sm);
      overflow-wrap: anywhere;
    }
  `,
})
export class MfVerifyEmail {
  readonly verification = inject(EmailVerification);
  private readonly session = inject(SessionStore);

  readonly email = computed(() => this.session.session()?.email ?? 'your email address');

  constructor() {
    // Asked on every visit to Manage: the link is usually opened somewhere
    // else, and coming back here is when the banner should notice.
    void this.verification.check();
  }
}
