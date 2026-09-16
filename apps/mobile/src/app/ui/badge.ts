import { Component, input } from '@angular/core';

/** A word about state: Paid, Used, Waiting. Colour plus the word, never colour alone. */
@Component({
  selector: 'mf-badge',
  template: '<ng-content />',
  host: { '[class]': 'tone()' },
  styles: `
    :host {
      display: inline-flex;
      align-items: center;
      padding: 2px var(--space-2);
      border-radius: var(--radius-full);
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-semibold);
      letter-spacing: 0.01em;
      white-space: nowrap;
    }

    :host(.neutral) {
      background: var(--surface-inset);
      color: var(--text-muted);
    }

    :host(.success) {
      background: var(--primary-soft);
      color: var(--primary-soft-text);
    }

    :host(.warning) {
      background: color-mix(in srgb, var(--warning) 14%, transparent);
      color: var(--warning);
    }

    :host(.danger) {
      background: color-mix(in srgb, var(--danger) 14%, transparent);
      color: var(--danger-text);
    }
  `,
})
export class MfBadge {
  readonly tone = input<'neutral' | 'success' | 'warning' | 'danger'>('neutral');
}
