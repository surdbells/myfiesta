import { Component, input, model } from '@angular/core';

export interface MfSegment {
  value: string;
  label: string;
}

/**
 * Two or three views of the same thing, side by side.
 *
 * Tabs across the top of a phone are a stretch for a thumb and easy to catch
 * by accident while scrolling; a segmented control sits in the content where
 * the eye already is, and reads as a filter rather than as navigation — which
 * is what it is.
 */
@Component({
  selector: 'mf-segmented',
  template: `
    <div class="track" role="tablist" [attr.aria-label]="ariaLabel()">
      @for (segment of segments(); track segment.value) {
        <button
          type="button"
          role="tab"
          class="segment"
          [class.on]="segment.value === value()"
          [attr.aria-selected]="segment.value === value()"
          (click)="value.set(segment.value)"
        >
          {{ segment.label }}
        </button>
      }
    </div>
  `,
  styles: `
    .track {
      display: grid;
      grid-auto-flow: column;
      grid-auto-columns: 1fr;
      gap: 2px;
      padding: 3px;
      border-radius: var(--radius-full);
      background: var(--surface-inset);
    }

    .segment {
      min-height: 40px;
      border: 0;
      border-radius: var(--radius-full);
      background: transparent;
      color: var(--text-muted);
      font-family: inherit;
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
      cursor: pointer;
      transition:
        background-color 140ms ease,
        color 140ms ease;
    }

    .segment.on {
      background: var(--surface-raised);
      color: var(--text);
      font-weight: var(--font-weight-semibold);
      box-shadow: var(--shadow-card);
    }
  `,
})
export class MfSegmented {
  readonly segments = input.required<MfSegment[]>();
  readonly ariaLabel = input<string | null>(null);
  readonly value = model.required<string>();
}
