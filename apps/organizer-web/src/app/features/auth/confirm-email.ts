import { Component, computed, inject, signal } from '@angular/core';
import { UiAlert, UiButton } from '@myfiesta/ui';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';

/**
 * Moving an account to a new address, from the link sent to it.
 *
 * The token arrives in the query string, like a reset link's, and is the whole
 * credential — this is usually opened from a mail app on a phone where nobody
 * is signed in to anything, so the page asks for nothing else.
 *
 * It waits for a tap rather than confirming as it loads. Mail scanners open
 * links to check them before anybody reads the message, and some run the
 * page's script while they are at it; a change that happened on load would
 * happen for them.
 */
@Component({
  selector: 'app-confirm-email',
  imports: [RouterLink, UiButton, UiAlert],
  templateUrl: './confirm-email.html',
})
export class ConfirmEmail {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  readonly session = inject(SessionStore);

  readonly token = this.route.snapshot.queryParamMap.get('token') ?? '';

  readonly busy = signal(false);
  readonly error = signal<string | null>(null);
  readonly done = signal<{ email: string; message: string } | null>(null);

  /** A link that arrived without its token is not worth showing a button for. */
  readonly usable = computed(() => this.token !== '');

  confirm(): void {
    if (this.busy()) return;

    this.busy.set(true);
    this.error.set(null);

    this.api.confirmEmailChange(this.token).subscribe({
      next: ({ email, message }) => {
        this.busy.set(false);
        // Deliberately not signing anybody in, or touching a session already
        // open here: it may belong to somebody else, and if it was this
        // account's it may be one of the devices just signed out.
        this.done.set({ email, message });
      },
      error: (response) => {
        this.busy.set(false);
        this.error.set(messageFor(response, 'That link has expired or has already been used. Ask for a new one from your account.'));
      },
    });
  }
}
