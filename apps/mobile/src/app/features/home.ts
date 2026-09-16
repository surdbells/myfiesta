import { Component, effect, inject } from '@angular/core';
import { Router } from '@angular/router';
import { SessionStore } from '../core/session';

/**
 * The first screen, which is never itself.
 *
 * Where somebody lands depends on what the server granted: a door pass goes to
 * the scanner, an organizer to their events, a ticket holder to their tickets,
 * and anybody else to sign in. Deciding it here rather than in each screen
 * means there is one place to read the rule.
 */
@Component({
  selector: 'mf-home',
  template: '',
})
export class Home {
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);

  constructor() {
    effect(() => {
      const session = this.session.session();

      if (!session) {
        void this.router.navigate(['/sign-in'], { replaceUrl: true });

        return;
      }

      const destination =
        session.scope === 'door' ? '/door' : session.scope === 'organizer' ? '/events' : '/tickets';

      void this.router.navigate([destination], { replaceUrl: true });
    });
  }
}
