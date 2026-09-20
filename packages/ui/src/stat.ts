import { Component, computed, input } from '@angular/core';

/**
 * One number, said properly.
 *
 * A label, the figure in the display face, and a line underneath saying what
 * it is against — because a number on its own is trivia. "412" is trivia;
 * "412 tickets, 83% of the room" is something an organizer does something
 * about.
 *
 * The bar is optional and deliberately plain: a proportion of a known total,
 * never a decoration. Where there is no known total — an unlimited tier — it
 * is simply absent rather than drawn full.
 */
@Component({
  selector: 'ui-stat',
  template: `
    <p class="label">{{ label() }}</p>

    <p class="value figure" [class.value--muted]="muted()">
      <ng-content>{{ value() }}</ng-content>
    </p>

    @if (portion() !== null) {
      <span class="bar" [attr.aria-hidden]="true">
        <span class="bar__fill" [class.bar__fill--full]="portion()! >= 1" [style.width.%]="Math.min(100, portion()! * 100)"></span>
      </span>
    }

    @if (hint(); as text) {
      <p class="hint">{{ text }}</p>
    }
  `,
  styles: `
    :host {
      display: grid;
      align-content: start;
      gap: 6px;
      min-width: 0;
    }

    .label {
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-medium);
      letter-spacing: var(--font-tracking-wide);
      text-transform: uppercase;
      color: var(--text-subtle);
    }

    .value {
      font-size: var(--font-size-2xl);
      color: var(--text);
    }
    .value--muted {
      color: var(--text-subtle);
    }

    .bar {
      display: block;
      height: 4px;
      margin-top: 2px;
      overflow: hidden;
      background-color: var(--surface-inset);
      border-radius: var(--radius-full);
    }
    .bar__fill {
      display: block;
      height: 100%;
      background-color: var(--primary);
      border-radius: inherit;
      transition: width var(--motion-slow) var(--motion-ease);
    }
    /* Sold out is worth seeing at a glance rather than reading. */
    .bar__fill--full {
      background-color: var(--accent);
    }

    .hint {
      font-size: var(--font-size-xs);
      color: var(--text-muted);
    }
  `,
})
export class UiStat {
  readonly label = input.required<string>();

  /** The figure, when it is not projected as content. */
  readonly value = input<string | number>('');

  readonly hint = input<string | null>(null);

  /** 0–1. Absent where there is no total to be a portion of. */
  readonly portion = input<number | null>(null);

  /** For a figure that is nothing yet: zero sold reads as absence, not as news. */
  readonly muted = input(false);

  protected readonly Math = Math;

  /** Kept so a host can ask what it would draw without reading the DOM. */
  readonly percent = computed(() => (this.portion() === null ? null : Math.round(this.portion()! * 100)));
}
