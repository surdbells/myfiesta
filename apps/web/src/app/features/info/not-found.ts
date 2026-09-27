import { Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Seo } from '../../core/seo';

/**
 * A path nothing on this site answers to.
 *
 * Single-segment paths are event slugs and get the event page's own "not
 * found"; this catches everything longer — a half-copied link, an old
 * platform's path, a scanner guessing. Before it existed the router failed to
 * match, the server gave up on rendering, and the visitor got Express's bare
 * "Cannot GET" in plain text.
 *
 * Answers 404 on the server, and is kept out of search, for the same reasons
 * as a missing event.
 */
@Component({
  selector: 'mf-not-found',
  imports: [RouterLink],
  template: `
    <section class="wrap mx-auto max-w-[1120px] px-6 pb-24 pt-8">
      <h1>Page not found</h1>
      <p class="mt-2 text-text-muted">
        Nothing lives at this address. If somebody sent you the link, part of it may be missing.
      </p>
      <p class="mt-6 flex flex-wrap gap-x-6 gap-y-2">
        <a class="font-semibold text-primary-text" routerLink="/events">See what&#39;s on</a>
        <a class="font-semibold text-primary-text" routerLink="/tickets">Find my tickets</a>
      </p>
    </section>
  `,
})
export class NotFound {
  constructor() {
    inject(Seo).notFound('Page not found');
  }
}
