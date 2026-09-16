import { Component, booleanAttribute, input } from '@angular/core';

/** A raised surface. The one container this app uses; lists are stacks of these. */
@Component({
  selector: 'mf-card',
  template: '<ng-content />',
  host: { '[class.tappable]': 'tappable()', '[class.quiet]': 'quiet()' },
  styles: `
    :host {
      display: block;
      padding: var(--space-5);
      border-radius: var(--radius-lg);
      background: var(--surface-raised);
      box-shadow: var(--mf-shadow-raised);
    }

    /* For rows in a long list, where twenty shadows is a grey page. */
    :host(.quiet) {
      box-shadow: none;
      border: 1px solid var(--border-subtle);
    }

    :host(.tappable) {
      cursor: pointer;
      transition: transform 90ms ease;
    }

    :host(.tappable:active) {
      transform: scale(0.99);
    }
  `,
})
export class MfCard {
  readonly tappable = input(false, { transform: booleanAttribute });
  readonly quiet = input(false, { transform: booleanAttribute });
}
