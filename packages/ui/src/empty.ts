import { Component, input } from '@angular/core';

/**
 * Nothing here yet.
 *
 * An empty screen is a moment where somebody decides whether the product works.
 * "No results" answers nothing; every use of this says what would be here and
 * how to put something in it.
 */
@Component({
  selector: 'ui-empty',
  template: `
    <div class="empty">
      <p class="empty__title">{{ title() }}</p>
      @if (hint()) {
        <p class="empty__hint">{{ hint() }}</p>
      }
      <ng-content />
    </div>
  `,
  styles: `
    .empty {
      display: grid;
      justify-items: center;
      gap: var(--space-3);
      padding: var(--space-7) var(--space-5);
      text-align: center;
      background: var(--surface-raised);
      border: 1px dashed var(--border-strong);
      border-radius: var(--radius-lg);
    }
    .empty__title {
      font-weight: var(--font-weight-semibold);
    }
    .empty__hint {
      max-width: 46ch;
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }
  `,
})
export class UiEmpty {
  readonly title = input.required<string>();
  readonly hint = input<string | null>(null);
}
