import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';

/** One step in the trail. The last one has no link — it is where you are. */
export interface Crumb {
  readonly label: string;
  readonly link?: unknown[] | string;
}

/**
 * Where this screen sits.
 *
 * The console nests three deep — organization, event, then a tab within it —
 * and without a trail the only way back up is the browser's back button, which
 * takes you to wherever you came from rather than to the level above.
 *
 * The current page is included and marked `aria-current`, not omitted: it is
 * the answer to "where am I", which is half the question the trail answers.
 *
 * Separators are CSS, not text nodes, so a screen reader announces "Events,
 * Summer Fest, Attendees" rather than "Events slash Summer Fest slash".
 */
@Component({
  selector: 'ui-breadcrumb',
  imports: [RouterLink],
  template: `
    <nav class="crumbs" aria-label="Breadcrumb">
      <ol class="crumbs__list">
        @for (crumb of crumbs(); track crumb.label; let last = $last) {
          <li class="crumbs__item">
            @if (crumb.link && !last) {
              <a class="crumbs__link" [routerLink]="crumb.link">{{ crumb.label }}</a>
            } @else {
              <span class="crumbs__here" aria-current="page">{{ crumb.label }}</span>
            }
          </li>
        }
      </ol>
    </nav>
  `,
  styles: `
    .crumbs__list {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      margin: 0;
      padding: 0;
      list-style: none;
      font-size: var(--font-size-sm);
      /* A long event name should not wrap the trail onto two lines and push
         the page heading down. */
      overflow-x: auto;
      scrollbar-width: none;
      white-space: nowrap;
    }
    .crumbs__list::-webkit-scrollbar { display: none; }
    .crumbs__item { display: flex; align-items: center; gap: var(--space-2); }
    .crumbs__item + .crumbs__item::before {
      content: '/';
      color: var(--text-subtle);
    }
    .crumbs__link { color: var(--text-muted); text-decoration: none; }
    .crumbs__link:hover { color: var(--text); text-decoration: underline; }
    .crumbs__here {
      color: var(--text);
      font-weight: var(--font-weight-medium);
      /* The one that can be long. Truncated rather than allowed to push the
         rest of the trail out of view. */
      max-width: 28ch;
      overflow: hidden;
      text-overflow: ellipsis;
    }
  `,
})
export class UiBreadcrumb {
  readonly crumbs = input.required<readonly Crumb[]>();
}
