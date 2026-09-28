import { Component, computed, input } from '@angular/core';

/**
 * A fortnight in a glance: one bar a day, today on the right.
 *
 * Bars rather than a line: these are counts of tickets on days, and a line
 * drawn between Tuesday's three and Wednesday's none invents a Tuesday
 * evening that never happened. Today's bar is the brand colour and the rest
 * are muted, so the eye lands where the question is ("is it selling now?").
 * An empty day keeps a hairline so the fortnight still reads as fourteen days
 * rather than as a gap.
 *
 * Read aloud as one sentence (`label`), since a screen reader cannot look at
 * a shape.
 */
@Component({
  selector: 'ui-sparkline',
  template: `
    <svg
      class="spark"
      [attr.viewBox]="'0 0 ' + width() + ' ' + height()"
      [attr.width]="width()"
      [attr.height]="height()"
      role="img"
      [attr.aria-label]="label()"
      preserveAspectRatio="none"
    >
      @for (bar of bars(); track $index) {
        <rect
          [attr.x]="bar.x"
          [attr.y]="bar.y"
          [attr.width]="bar.w"
          [attr.height]="bar.h"
          rx="1"
          [class.spark__today]="bar.today"
          [class.spark__empty]="bar.empty"
        />
      }
    </svg>
  `,
  styles: `
    :host { display: inline-block; line-height: 0; }
    .spark rect { fill: color-mix(in srgb, var(--primary) 38%, transparent); }
    .spark rect.spark__today { fill: var(--primary); }
    .spark rect.spark__empty { fill: var(--border-strong); }
  `,
})
export class UiSparkline {
  readonly values = input.required<readonly number[]>();
  /** What the bars are, said in words: "23 tickets in the last 14 days". */
  readonly label = input.required<string>();
  readonly width = input(84);
  readonly height = input(24);

  protected readonly bars = computed(() => {
    const values = this.values();
    const count = Math.max(1, values.length);
    const gap = 2;
    const w = Math.max(1, (this.width() - gap * (count - 1)) / count);
    const top = Math.max(1, ...values);
    const height = this.height();

    return values.map((value, index) => {
      const h = value === 0 ? 1 : Math.max(2, (value / top) * height);

      return {
        x: index * (w + gap),
        y: height - h,
        w,
        h,
        today: index === count - 1 && value > 0,
        empty: value === 0,
      };
    });
  });
}
