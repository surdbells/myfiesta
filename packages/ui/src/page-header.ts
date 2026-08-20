import { Component, input } from '@angular/core';

/**
 * The top of a screen: what this is, and the one action it is for.
 *
 * Every screen had its own header markup with its own spacing. Being one
 * component is what stops the console feeling like eight separate products.
 */
@Component({
  selector: 'ui-page-header',
  template: `
    <header class="head">
      <div class="head__text">
        @if (eyebrow()) {
          <p class="eyebrow">{{ eyebrow() }}</p>
        }
        <h1>{{ title() }}</h1>
        @if (subtitle()) {
          <p class="head__sub">{{ subtitle() }}</p>
        }
      </div>
      <div class="head__actions">
        <ng-content />
      </div>
    </header>
  `,
  styles: `
    .head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: var(--space-5);
      flex-wrap: wrap;
      margin-bottom: var(--space-6);
    }
    .head__text {
      display: grid;
      gap: var(--space-1);
      min-width: 0;
    }
    .head__sub {
      max-width: 60ch;
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }
    .head__actions {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      flex-wrap: wrap;
    }
  `,
})
export class UiPageHeader {
  readonly title = input.required<string>();
  readonly eyebrow = input<string | null>(null);
  readonly subtitle = input<string | null>(null);
}
