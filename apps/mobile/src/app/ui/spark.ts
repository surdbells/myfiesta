import { Component, computed, input } from '@angular/core';

/**
 * A month of days as bars: how sales have been going, at a glance.
 *
 * Not a chart to read values off — the figures sit beside it for that — but
 * the shape: steady, a spike after an announcement, a dead week. Days with
 * nothing are drawn as a stub rather than left out, because a gap reads as
 * missing data and a stub reads as a quiet day, which is what it was. The
 * last bar is today, in the stronger colour.
 */
@Component({
  selector: 'mf-spark',
  template: `
    <svg [attr.viewBox]="'0 0 ' + width + ' ' + height" preserveAspectRatio="none" role="img" [attr.aria-label]="label()">
      @for (bar of bars(); track $index) {
        <rect
          [attr.x]="bar.x"
          [attr.y]="bar.y"
          [attr.width]="bar.w"
          [attr.height]="bar.h"
          rx="1.5"
          [class.today]="$last"
          [class.quiet]="bar.zero"
        />
      }
    </svg>
  `,
  styles: `
    :host {
      display: block;
    }

    svg {
      display: block;
      width: 100%;
      height: var(--mf-spark-h);
    }

    rect {
      fill: color-mix(in srgb, var(--primary) 45%, transparent);
    }

    rect.today {
      fill: var(--primary);
    }

    rect.quiet {
      fill: var(--surface-inset);
    }
  `,
})
export class MfSpark {
  readonly values = input.required<number[]>();
  readonly label = input('Sales by day');

  protected readonly width = 300;
  protected readonly height = 56;

  protected readonly bars = computed(() => {
    const values = this.values();
    const max = Math.max(1, ...values);
    const slot = this.width / Math.max(1, values.length);
    const w = Math.max(2, slot * 0.66);

    return values.map((value, i) => {
      const zero = value <= 0;
      const h = zero ? 3 : Math.max(4, (value / max) * (this.height - 2));

      return { x: i * slot + (slot - w) / 2, y: this.height - h, w, h, zero };
    });
  });
}
