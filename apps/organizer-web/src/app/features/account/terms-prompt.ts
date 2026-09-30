import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
// Drawn by the shell: the kit a file at a time, as app.ts explains.
import { UiAlert } from '@myfiesta/ui/alert';
import { UiButton } from '@myfiesta/ui/button';
import { ToastStore } from '@myfiesta/ui/toast';
import { Api } from '../../core/api';
import type { TermsStanding } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';
import { SITE_URL } from '../../core/site-url';

/** Where "Not now" is kept: this tab only, so the next visit asks again. */
const LATER_KEY = 'myfiesta.console.terms-later';

/**
 * "Accept the terms", for an account that never has, or not these words.
 *
 * Signing up asks, and so does buying. An organizer whose account is older
 * than that box, or came over from the previous platform, or who runs events
 * and never buys a ticket, was never asked at all — and new words are asked
 * about again. So the console asks: once per visit, above every screen,
 * without getting in the way of reading. A dialog would hold the console
 * hostage to three pages of legal text on the night of a show; this can be
 * left for later and nothing stops working meanwhile.
 *
 * The box starts unticked, like every other one: a box ticked for somebody
 * is not somebody agreeing. The pages are the public site's and open in a
 * tab of their own.
 *
 * Never for a staff session: the account behind it is the staff member's,
 * and the endpoints refuse it.
 */
@Component({
  selector: 'app-terms-prompt',
  imports: [UiAlert, UiButton],
  template: `
    @if (asking(); as standing) {
      <div class="terms-prompt mb-6">
        <ui-alert tone="info" [title]="standing.accepted_version ? 'Our terms have changed' : 'Please accept our terms'">
          <p class="text-pretty">
            @if (standing.accepted_version) {
              The terms, the privacy policy or the refund policy have changed since you last accepted them.
            } @else {
              Your account has not yet accepted the terms, the privacy policy and the refund policy.
            }
            Read them when it suits you: everything here keeps working in the meantime.
          </p>

          <!-- A link inside the label follows the link rather than ticking the box. -->
          <label class="terms-prompt__box mt-3 flex cursor-pointer items-start gap-3 text-text">
            <input
              class="check mt-0.5"
              type="checkbox"
              name="accept_terms"
              [checked]="ticked()"
              (change)="ticked.set($any($event.target).checked)"
            />
            <span>
              I accept the
              <a class="text-primary-text" [href]="site + '/terms'" target="_blank" rel="noopener">terms</a>,
              the <a class="text-primary-text" [href]="site + '/privacy'" target="_blank" rel="noopener">privacy policy</a>
              and the <a class="text-primary-text" [href]="site + '/refunds'" target="_blank" rel="noopener">refund policy</a>.
            </span>
          </label>

          @if (error(); as said) {
            <p class="terms-prompt__error mt-2 text-pretty text-danger" role="alert">{{ said }}</p>
          }

          <div class="mt-3 flex flex-wrap items-center gap-3">
            <button uiButton type="button" size="sm" [loading]="saving()" [disabled]="!ticked() || saving()" (click)="accept()">
              Accept
            </button>
            <button uiButton type="button" size="sm" variant="ghost" [disabled]="saving()" (click)="later()">Not now</button>
          </div>
        </ui-alert>
      </div>
    }
  `,
})
export class TermsPrompt {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  private readonly session = inject(SessionStore);
  readonly site = inject(SITE_URL).replace(/\/+$/, '');

  /** What the server said about this account, while it has not agreed. */
  readonly standing = signal<TermsStanding | null>(null);
  readonly ticked = signal(false);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  private readonly setAside = signal(false);

  private readonly ownAccount = computed(() => this.session.signedIn() && this.session.impersonation() === null);

  readonly asking = computed(() => {
    const standing = this.standing();

    return this.ownAccount() && standing && !standing.accepted && !this.setAside() ? standing : null;
  });

  /** Whose account was last asked about, so a sign-in as somebody else asks again. */
  private askedFor: string | null = null;

  constructor() {
    effect(() => {
      const email = this.ownAccount() ? (this.session.user()?.email ?? null) : null;

      untracked(() => this.start(email));
    });
  }

  private start(email: string | null): void {
    if (email === this.askedFor) return;

    this.askedFor = email;
    this.standing.set(null);
    this.ticked.set(false);
    this.error.set(null);
    this.setAside.set(false);

    if (!email) return;

    this.api.terms().subscribe({
      next: (standing) => {
        // Answered for somebody who has since signed out or been replaced.
        if (this.askedFor !== email) return;

        this.setAside.set(TermsPrompt.laterFor() === TermsPrompt.laterKey(email, standing.current));
        this.standing.set(standing);
      },
      // Asked again on the next visit. A prompt that fails loudly is worse
      // than one that waits.
      error: () => undefined,
    });
  }

  accept(): void {
    if (!this.ticked() || this.saving()) return;

    this.saving.set(true);
    this.error.set(null);

    this.api.acceptTerms().subscribe({
      next: (standing) => {
        this.saving.set(false);
        this.standing.set(standing);
        this.toasts.show('Thank you. Your acceptance is saved.');
      },
      error: (response: unknown) => {
        this.saving.set(false);
        this.error.set(messageFor(response, 'That did not save. Try again in a moment.'));
      },
    });
  }

  /** Put off until the next visit, not for good: the words still need an answer. */
  later(): void {
    const standing = this.standing();
    const email = this.askedFor;

    this.setAside.set(true);

    if (!standing || !email) return;

    try {
      sessionStorage.setItem(LATER_KEY, TermsPrompt.laterKey(email, standing.current));
    } catch {
      // Blocked storage: put off until this page reloads.
    }
  }

  private static laterKey(email: string, version: string): string {
    return `${email.toLowerCase()}|${version}`;
  }

  private static laterFor(): string | null {
    try {
      return sessionStorage.getItem(LATER_KEY);
    } catch {
      return null;
    }
  }
}
