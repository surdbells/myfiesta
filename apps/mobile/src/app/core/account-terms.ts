import { Injectable, inject, signal } from '@angular/core';
import type { TermsStanding } from '@myfiesta/api-types';
import { Api } from './api';
import { messageOf } from './errors';
import { SessionStore } from './session';

/**
 * Whether the signed-in organizer has agreed to the terms in force, and
 * agreeing from the phone.
 *
 * Signing up asks, and so does buying. An organizer whose account is older
 * than that box, or came over from the previous platform, or who never buys
 * a ticket, was never asked — so Manage asks, the way the console does.
 * Once a run of the app: the server is asked the first time Manage opens,
 * and "Not now" holds until the app is next started, because the words
 * still need an answer but not in the middle of somebody's evening.
 *
 * Kept here rather than in the card, which is made again on every visit to
 * Manage and would otherwise ask again each time.
 */
@Injectable({ providedIn: 'root' })
export class AccountTerms {
  private readonly api = inject(Api);
  private readonly session = inject(SessionStore);

  /** What the server said, for the account in `about`. Null until it has. */
  readonly standing = signal<TermsStanding | null>(null);
  readonly putOff = signal(false);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);

  /** Whose account the above is about, so somebody signing in after is asked afresh. */
  private about: string | null = null;

  /** Ask the server, once for each account. Organizers only: Manage is where it is shown. */
  async check(): Promise<void> {
    if (!this.session.canSeeSales()) return;

    const who = this.session.session()?.email ?? null;

    if (who === this.about) return;

    this.about = who;
    this.standing.set(null);
    this.putOff.set(false);
    this.error.set(null);

    try {
      const standing = await this.api.terms();

      if (this.about === who) this.standing.set(standing);
    } catch {
      // Asked again on the next visit to Manage. A prompt that fails loudly
      // is worse than one that waits.
      if (this.about === who) this.about = null;
    }
  }

  /** The box, ticked. True when it is saved. */
  async accept(): Promise<boolean> {
    if (this.saving()) return false;

    this.saving.set(true);
    this.error.set(null);

    try {
      this.standing.set(await this.api.acceptTerms());

      return true;
    } catch (error) {
      this.error.set(messageOf(error, 'That did not save. Try again in a moment.'));

      return false;
    } finally {
      this.saving.set(false);
    }
  }

  later(): void {
    this.putOff.set(true);
  }
}
