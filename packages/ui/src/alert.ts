import { Component, computed, input } from '@angular/core';
import { CircleAlert, CircleCheck, Info, TriangleAlert } from 'lucide-angular';
import { UiIcon, type LucideIconData } from './icon';

/** The four things an alert can be telling you. */
export type AlertTone = 'success' | 'danger' | 'warning' | 'info';

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
  imports: [UiIcon],
  template: `
    <div class="alert" [class]="'alert--' + tone()" [attr.role]="tone() === 'danger' ? 'alert' : 'status'">
      <ui-icon class="alert__mark" [icon]="mark()" />
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
      border-radius: var(--radius-control);
    }
    .alert__mark {
      /* Nudged down to sit on the first line of the text rather than above it:
         an icon aligned to the box centres itself against a two-line message
         and floats away from the sentence it belongs to. */
      margin-top: 1px;
    }
    .alert__body { min-width: 0; }
    .alert__title {
      font-weight: var(--font-weight-semibold);
    }
    /* Tinted from the semantic token rather than a second set of hard-coded
       colours, so these follow the theme without a dark-mode block. */
    .alert--success {
      color: var(--success);
      background-color: color-mix(in srgb, var(--success) 10%, transparent);
      border-color: color-mix(in srgb, var(--success) 28%, transparent);
    }
    .alert--danger {
      color: var(--danger);
      background-color: color-mix(in srgb, var(--danger) 9%, transparent);
      border-color: color-mix(in srgb, var(--danger) 26%, transparent);
    }
    .alert--warning {
      color: var(--warning);
      background-color: color-mix(in srgb, var(--warning) 10%, transparent);
      border-color: color-mix(in srgb, var(--warning) 28%, transparent);
    }
    .alert--info {
      color: var(--info);
      background-color: color-mix(in srgb, var(--info) 9%, transparent);
      border-color: color-mix(in srgb, var(--info) 26%, transparent);
    }
  `,
})
export class UiAlert {
  readonly tone = input<AlertTone>('info');
  readonly title = input<string | null>(null);

  /**
   * Keyed by the tone union, so a new tone cannot be added without a mark.
   *
   * Danger and warning get different glyphs now, which typing them as a
   * bare exclamation mark could not do — a circle reads as a stop and a
   * triangle as a caution, and that distinction is the whole reason the two
   * tones exist.
   */
  private static readonly marks: Record<AlertTone, LucideIconData> = {
    success: CircleCheck,
    danger: CircleAlert,
    warning: TriangleAlert,
    info: Info,
  };

  readonly mark = computed(() => UiAlert.marks[this.tone()]);
}
