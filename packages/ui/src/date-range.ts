import { Component, ElementRef, computed, inject, input, model, signal, viewChild } from '@angular/core';
import { UiIcon } from './icon';
import { CalendarDays, ChevronDown } from 'lucide-angular';

/** A window of days, either end open. */
export interface DateRange {
  /** Inclusive, as yyyy-mm-dd. Null means "from the beginning". */
  from: string | null;
  /** Inclusive, as yyyy-mm-dd. Null means "until now". */
  to: string | null;
}

interface Preset {
  key: string;
  label: string;
  /** Days back from today, or null for the whole of time. */
  days: number | null;
}

/**
 * "When" as a filter, which every list of money eventually needs.
 *
 * Presets first and a custom range behind them, because the honest split of
 * what anybody actually asks a sales table is: today, this week, this month —
 * and then, rarely, the exact fortnight of a festival. Making the rare case
 * the only case is how a filter ends up being two date fields nobody fills in.
 *
 * Dates, not timestamps. An organizer thinking about Friday is not thinking
 * about 00:00:00Z, and the server treats both ends as whole days in the
 * event's own zone.
 */
@Component({
  selector: 'ui-date-range',
  imports: [UiIcon],
  host: {
    '(document:click)': 'outside($event)',
    '(document:keydown.escape)': 'dismiss()',
  },
  template: `
    <button
      #trigger
      class="trigger"
      type="button"
      aria-haspopup="dialog"
      [attr.aria-expanded]="open()"
      (click)="open.set(!open())"
    >
      <ui-icon [icon]="calendarIcon" size="sm" />
      <span class="trigger__label">{{ label() }}</span>
      <ui-icon class="trigger__chevron" [icon]="chevronIcon" size="sm" />
    </button>

    @if (open()) {
      <div class="panel" role="dialog" [attr.aria-label]="ariaLabel()">
        <ul class="presets">
          @for (preset of presets; track preset.key) {
            <li>
              <button type="button" [class.on]="chosen() === preset.key" (click)="choose(preset)">{{ preset.label }}</button>
            </li>
          }
        </ul>

        <div class="custom">
          <label>
            <span>From</span>
            <input type="date" [value]="value().from ?? ''" [max]="value().to ?? today" (change)="edit('from', $any($event.target).value)" />
          </label>
          <label>
            <span>To</span>
            <input type="date" [value]="value().to ?? ''" [min]="value().from ?? ''" [max]="today" (change)="edit('to', $any($event.target).value)" />
          </label>
        </div>
      </div>
    }
  `,
  styles: `
    :host {
      position: relative;
      display: inline-block;
    }

    .trigger {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      height: 44px;
      padding: 0 var(--space-3);
      font: inherit;
      font-size: var(--font-size-sm);
      color: var(--text);
      background-color: var(--surface);
      border: 1px solid var(--field-border);
      border-radius: var(--radius-md);
      cursor: pointer;
      white-space: nowrap;
    }
    .trigger:hover {
      border-color: var(--border-strong);
    }
    .trigger__chevron {
      color: var(--text-subtle);
    }

    .panel {
      position: absolute;
      z-index: 20;
      top: calc(100% + var(--space-2));
      left: 0;
      display: grid;
      gap: var(--space-3);
      width: max-content;
      min-width: 16rem;
      padding: var(--space-3);
      background-color: var(--surface-raised);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      /* Above the table it covers, and clearly above it. */
      box-shadow: var(--shadow-floating);
    }

    .presets {
      display: grid;
      gap: 2px;
      margin: 0;
      padding: 0;
      list-style: none;
    }
    .presets button {
      display: block;
      width: 100%;
      padding: var(--space-2) var(--space-3);
      font: inherit;
      font-size: var(--font-size-sm);
      text-align: left;
      color: var(--text);
      background: none;
      border: 0;
      border-radius: var(--radius-sm);
      cursor: pointer;
    }
    .presets button:hover {
      background-color: var(--surface-hover);
    }
    .presets button.on {
      color: var(--primary-soft-text);
      background-color: var(--primary-soft);
      font-weight: var(--font-weight-semibold);
    }

    .custom {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-2);
      padding-top: var(--space-3);
      border-top: 1px solid var(--border-subtle);
    }
    .custom label {
      display: grid;
      gap: 4px;
      font-size: var(--font-size-xs);
      color: var(--text-muted);
    }
    .custom input {
      height: 38px;
      padding: 0 var(--space-2);
      font: inherit;
      font-size: var(--font-size-sm);
      color: var(--text);
      background-color: var(--surface);
      border: 1px solid var(--field-border);
      border-radius: var(--radius-sm);
    }
  `,
})
export class UiDateRange {
  private readonly host = inject(ElementRef<HTMLElement>);

  private readonly trigger = viewChild.required<ElementRef<HTMLButtonElement>>('trigger');

  readonly value = model<DateRange>({ from: null, to: null });

  /** What this range is about — "Paid", "Created". Shown when nothing is set. */
  readonly noun = input('Any time');

  readonly ariaLabel = input('Choose a date range');

  readonly open = signal(false);

  readonly today = new Date().toISOString().slice(0, 10);

  protected readonly calendarIcon = CalendarDays;
  protected readonly chevronIcon = ChevronDown;

  protected readonly presets: Preset[] = [
    { key: 'all', label: 'Any time', days: null },
    { key: 'today', label: 'Today', days: 0 },
    { key: '7', label: 'Last 7 days', days: 6 },
    { key: '30', label: 'Last 30 days', days: 29 },
    { key: '90', label: 'Last 90 days', days: 89 },
  ];

  /** Which preset the current value happens to be, if any. */
  readonly chosen = computed(() => {
    const { from, to } = this.value();

    if (!from && !to) return 'all';
    if (to !== this.today) return null;

    return this.presets.find((preset) => preset.days !== null && this.daysAgo(preset.days) === from)?.key ?? null;
  });

  readonly label = computed(() => {
    const { from, to } = this.value();

    if (!from && !to) return this.noun();

    const preset = this.presets.find((p) => p.key === this.chosen());
    if (preset && preset.key !== 'all') return preset.label;

    const show = (value: string | null) =>
      value ? new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short' }).format(new Date(value)) : null;

    if (from && to) return `${show(from)} – ${show(to)}`;

    return from ? `From ${show(from)}` : `Until ${show(to)}`;
  });

  choose(preset: Preset): void {
    this.value.set(preset.days === null ? { from: null, to: null } : { from: this.daysAgo(preset.days), to: this.today });
    this.dismiss();
  }

  /**
   * Close, and put focus back where it came from.
   *
   * The panel is removed from the page when it closes, so whatever was
   * focused inside it goes with it and the browser drops focus on <body>.
   * For anybody navigating by keyboard that is the top of the document: they
   * pick a date range and are thrown back to the site header.
   */
  dismiss(): void {
    if (!this.open()) return;

    this.open.set(false);
    this.trigger().nativeElement.focus();
  }

  edit(end: 'from' | 'to', raw: string): void {
    this.value.set({ ...this.value(), [end]: raw || null });
  }

  /** A click anywhere else closes it, the way every other popover behaves. */
  outside(event: MouseEvent): void {
    if (this.open() && !this.host.nativeElement.contains(event.target as Node)) this.open.set(false);
  }

  private daysAgo(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() - days);

    return date.toISOString().slice(0, 10);
  }
}
