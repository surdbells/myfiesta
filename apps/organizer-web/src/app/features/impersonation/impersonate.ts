import { Component, inject, signal } from '@angular/core';
import { DOCUMENT, Location } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { UiAlert, UiButton } from '@myfiesta/ui';
import { Api } from '../../core/api';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';

/**
 * Where the admin panel's "Open as organization" link lands, and where a
 * staff session goes when it ends.
 *
 * The link carries a one-minute, single-use code in its fragment — never the
 * token, and never anything a server log would keep. The code is taken off
 * the address bar before anything else happens, so it is not left in history
 * for the back button or a screen share, and traded once for the token, which
 * lives only in this tab.
 *
 * No confirmation button, unlike a door link: this one was opened by the
 * staff member's own click a second ago, not by a chat app drawing a preview,
 * and the code would lapse while somebody read a page about it.
 */
@Component({
  selector: 'app-impersonate',
  imports: [UiAlert, UiButton],
  templateUrl: './impersonate.html',
})
export class Impersonate {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly location = inject(Location);
  private readonly session = inject(SessionStore);
  private readonly document = inject(DOCUMENT);

  /** 'ended' when the route is the one a finished session is sent to. */
  readonly ended = this.route.snapshot.data['ended'] === true;

  /** Why it ended, when this tab knows: 'expired' from the countdown. */
  readonly why = this.route.snapshot.queryParamMap.get('why');

  readonly opening = signal(!this.ended);
  readonly error = signal<string | null>(null);

  constructor() {
    if (this.ended) return;

    const code = new URLSearchParams(this.route.snapshot.fragment ?? '').get('code');

    // Off the address bar first, whatever happens next.
    this.location.replaceState('/impersonate');

    if (!code) {
      this.fail('This staff link is incomplete. Start a new session from the admin panel.');
      return;
    }

    this.api.exchangeImpersonation(code).subscribe({
      next: (staff) => {
        this.session.startImpersonation(staff);
        void this.router.navigate(['/'], { replaceUrl: true });
      },
      error: (response) =>
        this.fail(
          messageFor(
            response,
            'This staff link could not be opened. Start a new session from the admin panel.',
          ),
        ),
    });
  }

  /** Only closes a tab a script opened — which the admin panel's was. */
  close(): void {
    this.document.defaultView?.close();
  }

  private fail(message: string): void {
    this.opening.set(false);
    this.error.set(message);
  }
}
