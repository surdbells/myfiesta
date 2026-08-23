import { Component, input } from '@angular/core';

/**
 * A small piece of state: draft, sold out, refunded, moved.
 *
 * Never colour alone. The word is always present, because a status conveyed
 * only by hue is invisible to a colourblind reader and to anyone printing a
 * guest list in black and white at a venue.
 */
@Component({
  selector: 'ui-badge',
  template: `<span class="badge" [class]="'badge--' + tone()"><ng-content /></span>`,
  styles: `
    .badge {
      display: inline-flex;
      align-items: center;
      padding: 2px var(--space-2);
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-medium);
      letter-spacing: 0.01em;
      white-space: nowrap;
      border-radius: var(--radius-full);
    }
    .badge--neutral {
      color: var(--text-muted);
      background-color: var(--surface-inset);
    }
    .badge--brand {
      color: var(--primary-soft-text);
      background-color: var(--primary-soft);
    }
    .badge--success {
      color: var(--success);
      background-color: color-mix(in srgb, var(--success) 12%, transparent);
    }
    .badge--warning {
      color: var(--warning);
      background-color: color-mix(in srgb, var(--warning) 14%, transparent);
    }
    .badge--danger {
      color: var(--danger);
      background-color: color-mix(in srgb, var(--danger) 12%, transparent);
    }
  `,
})
export class UiBadge {
  readonly tone = input<'neutral' | 'brand' | 'success' | 'warning' | 'danger'>('neutral');
}
