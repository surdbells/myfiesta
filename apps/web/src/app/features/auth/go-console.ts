import { Component, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { CONSOLE_URL } from '../../core/console-url';

/**
 * /register and /sign-in on the public site, forwarded to the console.
 *
 * Organizer accounts live on the console app; before this, both paths fell
 * through to the slug wildcard and a person who typed myfiesta.ca/register —
 * the most guessable URL an intending organizer has — was told "Event not
 * found". The route exists to catch that guess and put them where they meant
 * to go.
 */
@Component({
  selector: 'mf-go-console',
  standalone: true,
  template: `
    <section class="frame grid min-h-[40vh] place-items-center">
      <p class="muted" role="status">Taking you to the organizer console…</p>
    </section>
  `,
})
export class GoConsole {
  constructor() {
    const consoleUrl = inject(CONSOLE_URL);
    const path: string = inject(ActivatedRoute).snapshot.data['consolePath'] ?? '';

    // Guarded twice: for server-side rendering, where there is no window
    // to move — and for a missing console-url meta, where redirecting to
    // '' + '/register' would loop this page into itself forever.
    if (typeof window !== 'undefined' && consoleUrl) {
      window.location.replace(consoleUrl + path);
    }
  }
}
