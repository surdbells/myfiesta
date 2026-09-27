import { Injectable, effect, inject, signal, untracked } from '@angular/core';
import { Api } from './api';
import { messageOf } from './errors';
import { SessionStore } from './session';
import { Dialogs, ToastStore } from '../ui';

/**
 * Whether the signed-in account's address is proved, and the prompt for when
 * it is not.
 *
 * Putting a night on sale, asking to be paid and changing where payouts go
 * wait for a proved address. GET /api/auth/me says so up front, for the
 * banner at the top of Manage; a 403 with the code `email_unverified` says so
 * after somebody pressed the button, wherever they were. That refusal is
 * answered here, once, with a sheet that offers to send the link again —
 * rather than by each screen in a toast that is gone in three seconds with
 * nothing to do about it.
 *
 * Constructed with the shell, so the refusal is heard on every screen.
 */
@Injectable({ providedIn: 'root' })
export class EmailVerification {
  private readonly api = inject(Api);
  private readonly session = inject(SessionStore);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  /** Null until the server has said either way. */
  readonly verified = signal<boolean | null>(null);
  readonly sending = signal(false);

  /** What the server said to the last "send it again": which inbox, or how long to wait. */
  readonly answer = signal<string | null>(null);

  /** Whose address the above is about. */
  private about: string | null = this.session.session()?.email ?? null;

  constructor() {
    // Somebody else signed in, or nobody: what was known was about them.
    // First, so a refusal heard in the same moment is not then forgotten.
    effect(() => {
      const email = this.session.session()?.email ?? null;

      untracked(() => {
        if (email === this.about) return;

        this.about = email;
        this.verified.set(null);
        this.answer.set(null);
      });
    });

    effect(() => {
      const refusal = this.api.unverified();

      if (refusal) untracked(() => void this.prompt(refusal.message));
    });
  }

  /** Ask the account. Organizers only: nothing an attendee does waits for it. */
  async check(): Promise<void> {
    if (!this.session.canSeeSales()) return;

    try {
      const me = await this.api.me();

      // An API from before the field existed says nothing, and nothing is
      // shown rather than a banner nobody can clear.
      if (typeof me.email_verified === 'boolean') this.verified.set(me.email_verified);
    } catch {
      // Without signal, the banner waits for the next visit.
    }
  }

  async resend(): Promise<void> {
    if (this.sending()) return;

    this.sending.set(true);
    this.answer.set(null);

    try {
      const { message, verified } = await this.api.resendVerification();

      if (verified) {
        // Proved already, from a link opened somewhere else.
        this.verified.set(true);
        this.toasts.show(message, 'success');

        return;
      }

      this.answer.set(message);
    } catch (error) {
      // A 429 carries the server's own sentence — which inbox, how long to wait.
      this.answer.set(messageOf(error, 'The link could not be sent. Try again in a moment.'));
    } finally {
      this.sending.set(false);
    }
  }

  private async prompt(message: string): Promise<void> {
    this.verified.set(false);

    const again = await this.dialogs.confirm({
      title: 'Confirm your email address',
      message,
      confirm: 'Send the link again',
      cancel: 'Not now',
    });

    if (!again) return;

    await this.resend();

    const answer = this.answer();
    if (answer) this.toasts.show(answer, 'neutral', 6000);
  }
}
