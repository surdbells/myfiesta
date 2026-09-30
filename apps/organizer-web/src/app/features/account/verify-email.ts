import { Component, HostListener, computed, effect, inject, signal, untracked } from '@angular/core';
import { DOCUMENT } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
// Drawn by the shell: the kit a file at a time, as app.ts explains.
import { UiAlert } from '@myfiesta/ui/alert';
import { UiButton } from '@myfiesta/ui/button';
import { ConfirmDialog } from '@myfiesta/ui/confirm';
import { UiModal } from '@myfiesta/ui/modal';
import { ToastStore } from '@myfiesta/ui/toast';
import { Api } from '../../core/api';
import { EmailVerification } from '../../core/email-verification';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';

/**
 * "Confirm your email address", with the button that sends the link again.
 *
 * Two forms of the same prompt, mounted once in the shell. A banner above
 * every screen while the address is unproved, which is gentle because most
 * of the console works without it. And, when a publish, a payout request or
 * new payout details has just been refused for it, the same words in a
 * dialog on top of whatever was open — a payout form is itself a dialog, and
 * a banner behind it is one nobody sees.
 *
 * Never for a staff session: it is not anybody's own account, and the
 * endpoints behind this refuse it.
 */
@Component({
  selector: 'app-verify-email',
  imports: [UiAlert, UiButton, UiModal],
  template: `
    @if (showBanner()) {
      <div class="verify-email mb-6">
        <ui-alert tone="info" title="Confirm your email address">
          <p class="text-pretty">
            Open the link we sent to <strong class="font-medium">{{ session.user()?.email }}</strong>. Until then you
            can build events and bring in your team, but not put an event on sale, ask to be paid or change where
            payouts go.
          </p>
          <div class="mt-3 flex flex-wrap items-center gap-3">
            <button uiButton type="button" size="sm" variant="secondary" [loading]="sending()" (click)="resend()">
              Send the link again
            </button>
            @if (answer(); as said) {
              <span class="verify-email__answer text-sm">{{ said }}</span>
            }
          </div>
        </ui-alert>
      </div>
    }

    <ui-modal [open]="prompting()" heading="Confirm your email address" (dismissed)="close()">
      <p class="verify-email__refusal text-pretty text-sm">{{ store.refusal() }}</p>
      @if (answer(); as said) {
        <p class="verify-email__answer mt-3 text-pretty text-sm text-text-muted" role="status">{{ said }}</p>
      }
      <ng-container modalActions>
        <button uiButton type="button" variant="secondary" (click)="close()">{{ answer() ? 'Close' : 'Not now' }}</button>
        <button uiButton type="button" [loading]="sending()" (click)="resend(true)">Send the link again</button>
      </ng-container>
    </ui-modal>
  `,
})
export class VerifyEmail {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  private readonly confirmDialog = inject(ConfirmDialog);
  private readonly document = inject(DOCUMENT);
  readonly session = inject(SessionStore);
  readonly store = inject(EmailVerification);

  readonly sending = signal(false);

  /** What the server said to the last "send it again": which inbox, or how long to wait. */
  readonly answer = signal<string | null>(null);

  private readonly ownAccount = computed(() => this.session.signedIn() && this.session.impersonation() === null);

  readonly showBanner = computed(() => this.ownAccount() && this.store.verified() === false);
  readonly prompting = computed(() => this.ownAccount() && this.store.refusal() !== null);

  /** Whose address was last asked about, so a sign-in as somebody else asks again. */
  private askedFor: string | null = null;

  constructor() {
    effect(() => {
      const email = this.ownAccount() ? (this.session.user()?.email ?? null) : null;

      untracked(() => this.start(email));
    });

    // A fresh refusal starts a fresh prompt: an answer the banner had from an
    // earlier "send it again" is not about this one.
    effect(() => {
      if (this.store.refusal() !== null) untracked(() => this.answer.set(null));
    });
  }

  /**
   * Back from the mail app with the link opened: the banner goes without a
   * reload. Only asked while there is something to find out.
   */
  @HostListener('document:visibilitychange')
  returned(): void {
    if (this.document.visibilityState === 'visible' && this.showBanner()) this.ask();
  }

  private start(email: string | null): void {
    if (email === this.askedFor) return;

    this.askedFor = email;
    this.store.forget();
    this.answer.set(null);

    if (email) this.ask();
  }

  private ask(): void {
    this.api.me().subscribe({
      next: (me) => {
        // An API from before the field existed says nothing, and nothing is
        // shown rather than a banner nobody can clear.
        if (typeof me.email_verified === 'boolean') this.store.learn(me.email_verified);
      },
      // A refused token is handled where every other one is.
      error: () => undefined,
    });
  }

  /**
   * Send the link again. From the banner it asks first; the dialog on top
   * of a refused action is already that question, with that button, so it
   * says it has been asked.
   */
  async resend(asked = false): Promise<void> {
    if (this.sending()) return;

    if (!asked) {
      const sure = await this.confirmDialog.confirm({
        title: 'Send the link again?',
        body: `A new email goes to ${this.session.user()?.email ?? 'your address'} with a link to confirm it.`,
        consequences: ['Check spam if it has not arrived in a few minutes.'],
        confirmLabel: 'Send the link',
        tone: 'default',
      });

      if (!sure || this.sending()) return;
    }

    this.sending.set(true);
    this.answer.set(null);

    this.api.resendVerification().subscribe({
      next: ({ message, verified }) => {
        this.sending.set(false);

        // Proved already, from a link opened somewhere else.
        if (verified) {
          this.store.learn(true);
          this.toasts.show(message, 'success');
          return;
        }

        this.answer.set(message);
      },
      error: (response: unknown) => {
        this.sending.set(false);

        // A 429 here is the server's own sentence — which inbox, and how long
        // to wait — and is worth more than a generic "too many attempts".
        const said = response instanceof HttpErrorResponse ? response.error?.message : null;

        this.answer.set(
          response instanceof HttpErrorResponse && response.status === 429 && typeof said === 'string'
            ? said
            : messageFor(response, 'The link could not be sent. Try again in a moment.'),
        );
      },
    });
  }

  close(): void {
    this.store.dismiss();
    this.answer.set(null);
  }
}
