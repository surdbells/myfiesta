import { Component, booleanAttribute, input } from '@angular/core';

/**
 * A raised surface. The one container this app uses; lists are stacks of these.
 *
 * Elevated and rounded: the larger of the token radii, because on a phone a
 * card is something held rather than a region of a page, and a shadow that
 * comes from above, like light in a room. `quiet` drops the shadow for rows
 * in a long list, where twenty shadows is a grey page; `flush` drops the
 * padding for a card whose top is a photograph running to its edges.
 */
@Component({
  selector: 'mf-card',
  template: '<ng-content />',
  host: { '[class.tappable]': 'tappable()', '[class.quiet]': 'quiet()', '[class.flush]': 'flush()' },
  styles: `
    :host {
      display: block;
      padding: var(--space-5);
      border-radius: var(--radius-xl);
      background: var(--surface-raised);
      box-shadow:
        inset 0 0 0 1px var(--border-subtle),
        var(--shadow-raised);
    }

    :host(.flush) {
      padding: 0;
      overflow: hidden;
    }

    /* For rows in a long list, where twenty shadows is a grey page. */
    :host(.quiet) {
      box-shadow: inset 0 0 0 1px var(--border-subtle);
    }

    :host(.tappable) {
      cursor: pointer;
      transition:
        transform 120ms ease,
        box-shadow 160ms ease;
    }

    :host(.tappable:active) {
      transform: scale(0.985);
      box-shadow:
        inset 0 0 0 1px var(--border-subtle),
        var(--shadow-card);
    }
  `,
})
export class MfCard {
  readonly tappable = input(false, { transform: booleanAttribute });
  readonly quiet = input(false, { transform: booleanAttribute });
  readonly flush = input(false, { transform: booleanAttribute });
}
