import { Component, input } from '@angular/core';

/**
 * A message about what just happened, or what is about to.
 *
 * Replaces the ad-hoc `.notice` and `.error` paragraphs that had accumulated in
 * eight different screens with eight slightly different sets of colours.
 *
 * The role is chosen from the tone rather than hardcoded: a failure is an alert
 * and interrupts a screen reader, a confirmation is a status and waits its
 * turn. Announcing "Saved." over the top of what somebody is reading is how
 * assistive technology becomes something people switch off.
 */
@Component({
  selector: 'ui-alert',
  template: `
    <div class="alert" [class]="'alert--' + tone()" [attr.role]="tone() === 'danger' ? 'alert' : 'status'">
      <span class="alert__mark" aria-hidden="true">{{ mark() }}</span>
      <div class="alert__body">
        @if (title()) {
          <p class="alert__title">{{ title() }}</p>
        }
        <ng-content />
      </div>
    </div>
  `,
  styles: `
    .alert {
      display: flex;
      gap: var(--space-3);
      padding: var(--space-3) var(--space-4);
      font-size: var(--font-size-sm);
      border: 1px solid;
      border-radius: var(--radius-md);
    }
    .alert__mark {
      font-weight: var(--font-weight-bold);
      line-height: var(--font-leading-normal);
    }
    .alert__body { min-width: 0; }
    .alert__title {
      font-weight: var(--font-weight-semibold);
    }
    /* Tinted from the semantic token rather than a second set of hard-coded
       colours, so these follow the theme without a dark-mode block. */
    .alert--success {
      color: var(--success);
      background: color-mix(in srgb, var(--success) 10%, transparent);
      border-color: color-mix(in srgb, var(--success) 28%, transparent);
    }
    .alert--danger {
      color: var(--danger);
      background: color-mix(in srgb, var(--danger) 9%, transparent);
      border-color: color-mix(in srgb, var(--danger) 26%, transparent);
    }
    .alert--warning {
      color: var(--warning);
      background: color-mix(in srgb, var(--warning) 10%, transparent);
      border-color: color-mix(in srgb, var(--warning) 28%, transparent);
    }
    .alert--info {
      color: var(--info);
      background: color-mix(in srgb, var(--info) 9%, transparent);
      border-color: color-mix(in srgb, var(--info) 26%, transparent);
    }
  `,
})
export class UiAlert {
  readonly tone = input<'success' | 'danger' | 'warning' | 'info'>('info');
  readonly title = input<string | null>(null);

  mark(): string {
    return { success: '✓', danger: '!', warning: '!', info: 'i' }[this.tone()];
  }
}
