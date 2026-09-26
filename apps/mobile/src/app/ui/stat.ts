import { Component, booleanAttribute, computed, input } from '@angular/core';

/**
 * A figure worth reading at a glance: sold, earned, through the door.
 *
 * The number in the display face, the label small above it, a line of
 * context under it — and optionally a bar for how much of something is gone,
 * which reads faster than "146 of 200" does when it is 2am.
 *
 * `lead` is the one figure on a screen that matters most, set larger and on
 * the brand wash; everything else is a plain tile beside it.
 */
@Component({
  selector: 'mf-stat',
  template: `
    <p class="label">{{ label() }}</p>
    <p class="figure">{{ value() }}</p>
    @if (portion() !== null) {
      <span class="bar" aria-hidden="true"><span [style.width.%]="percent()" [class.full]="percent() >= 100"></span></span>
    }
    @if (hint()) {
      <p class="hint">{{ hint() }}</p>
    }
  `,
  host: { '[class.lead]': 'lead()', '[class.quiet]': 'quiet()' },
  styles: `
    :host {
      display: grid;
      align-content: start;
      gap: var(--space-1);
      padding: var(--space-4);
      border-radius: var(--radius-xl);
      background: var(--surface-raised);
      box-shadow:
        inset 0 0 0 1px var(--border-subtle),
        var(--shadow-card);
      min-width: 0;
    }

    :host(.lead) {
      padding: var(--space-5);
      background:
        radial-gradient(120% 140% at 0% 0%, color-mix(in srgb, var(--primary) 16%, transparent), transparent 60%),
        var(--surface-raised);
      box-shadow:
        inset 0 0 0 1px color-mix(in srgb, var(--primary) 28%, var(--border-subtle)),
        var(--shadow-raised);
    }

    :host(.quiet) .figure {
      color: var(--text-muted);
    }

    .label {
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-semibold);
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--text-subtle);
    }

    .figure {
      font-family: var(--font-family-display);
      font-size: var(--font-size-xl);
      font-weight: var(--font-weight-semibold);
      letter-spacing: var(--font-tracking-tight);
      line-height: 1.1;
      font-variant-numeric: tabular-nums;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    :host(.lead) .figure {
      font-size: var(--font-size-3xl);
      color: var(--primary-text);
    }

    .bar {
      display: block;
      height: 5px;
      margin-top: var(--space-1);
      border-radius: var(--radius-full);
      background: var(--surface-inset);
      overflow: hidden;
    }

    .bar span {
      display: block;
      height: 100%;
      border-radius: inherit;
      background: var(--primary);
      transition: width 400ms var(--mf-ease-out);
    }

    .bar span.full {
      background: var(--accent);
    }

    .hint {
      font-size: var(--font-size-xs);
      color: var(--text-muted);
      line-height: var(--font-leading-snug);
    }
  `,
})
export class MfStat {
  readonly label = input.required<string>();
  readonly value = input.required<string>();
  readonly hint = input<string | null>(null);
  /** 0 to 1: how much of something is gone. Null draws no bar. */
  readonly portion = input<number | null>(null);
  readonly lead = input(false, { transform: booleanAttribute });
  readonly quiet = input(false, { transform: booleanAttribute });

  protected readonly percent = computed(() => Math.max(0, Math.min(100, Math.round((this.portion() ?? 0) * 100))));
}
