import {
  Component,
  ElementRef,
  Injector,
  afterNextRender,
  booleanAttribute,
  computed,
  inject,
  input,
  model,
  output,
  viewChild,
} from '@angular/core';
import { UiIcon } from './icon';
import { Search, X } from 'lucide-angular';

/** One filter that is currently on, as the person reading it would say it. */
export interface FilterChip {
  /** What the host removes when this one is dismissed. */
  key: string;
  /** What it filters — "Event", "Status". */
  label: string;
  /** What it is set to — "Afro Fest", "Paid". */
  value: string;
}

/**
 * The controls above a table.
 *
 * Every list in this console was growing its own arrangement of a search box
 * and two selects, and they had drifted: different heights, different
 * placeholder wording, and no two of them agreeing on what happens when three
 * filters are on at once. This is that row, once.
 *
 * What makes it production-grade rather than decorative is the bottom half.
 * A filtered table that looks exactly like an unfiltered one is how somebody
 * exports four hundred rows believing they exported everything, or reads a
 * total for last week as the total for the year. So every filter that is on
 * says so as a chip that can be taken off by itself, there is one way to clear
 * the lot, and the count is always the count of what is being shown against
 * what exists.
 */
@Component({
  selector: 'ui-filter-bar',
  imports: [UiIcon],
  template: `
    <div class="bar">
      <div class="controls">
        @if (!searchHidden()) {
          <label class="search">
            <ui-icon class="search__icon" [icon]="searchIcon" size="sm" />
            <input
              #searchBox
              type="search"
              [attr.aria-label]="searchLabel()"
              [placeholder]="searchPlaceholder()"
              [value]="search()"
              (input)="search.set($any($event.target).value)"
            />
          </label>
        }

        <!-- Whatever this table filters by: selects, a date range, a toggle. -->
        <ng-content />

        <span class="actions">
          <ng-content select="[bar-actions]" />
        </span>
      </div>

      @if (chips().length > 0 || summary()) {
        <div class="state">
          @if (chips().length > 0) {
            <ul #chipList class="chips">
              @for (chip of chips(); track chip.key) {
                <li>
                  <span class="chip__label">{{ chip.label }}</span>
                  <span class="chip__value">{{ chip.value }}</span>
                  <button type="button" [attr.aria-label]="'Remove ' + chip.label + ' filter'" (click)="drop(chip.key, $index)">
                    <ui-icon [icon]="clearIcon" size="sm" />
                  </button>
                </li>
              }
            </ul>

            <button class="clear" type="button" (click)="dropAll()">Clear all</button>
          }

          @if (summary(); as text) {
            <!-- Always the shown count against the real one. A filtered table
                 that reads like an unfiltered one is how a partial export
                 becomes a full one in somebody's head. -->
            <p class="summary" aria-live="polite">{{ text }}</p>
          }
        </div>
      }
    </div>
  `,
  styles: `
    .bar {
      display: grid;
      gap: var(--space-3);
      padding: var(--space-4);
      background-color: var(--surface-raised);
      border: 1px solid var(--border);
      border-radius: var(--radius-card);
      box-shadow: var(--shadow-card);
    }

    .controls {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: var(--space-3);
    }

    .actions {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      margin-left: auto;
    }

    /* The search takes the room, because it is the one anybody uses. */
    .search {
      display: flex;
      flex: 1 1 18rem;
      min-width: 0;
      align-items: center;
      gap: var(--space-2);
      height: 44px;
      padding: 0 var(--space-3);
      background-color: var(--surface);
      border: 1px solid var(--field-border);
      border-radius: var(--radius-control);
      transition:
        border-color var(--motion-fast) var(--motion-ease),
        box-shadow var(--motion-fast) var(--motion-ease);
    }
    .search:focus-within {
      border-color: var(--primary);
      box-shadow: var(--focus-ring);
    }
    .search__icon {
      flex-shrink: 0;
      color: var(--text-subtle);
    }
    .search input {
      flex: 1;
      min-width: 0;
      height: 100%;
      padding: 0;
      font: inherit;
      font-size: var(--font-size-sm);
      color: var(--text);
      background: none;
      border: 0;
      border-radius: 0;
    }
    .search input:focus {
      outline: none;
      box-shadow: none;
    }

    .state {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: var(--space-2) var(--space-3);
      padding-top: var(--space-3);
      border-top: 1px solid var(--border-subtle);
    }

    .chips {
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-2);
      margin: 0;
      padding: 0;
      list-style: none;
    }
    .chips li {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      padding: 4px 4px 4px var(--space-3);
      font-size: var(--font-size-xs);
      background-color: var(--primary-soft);
      color: var(--primary-soft-text);
      border-radius: var(--radius-full);
    }
    .chip__label {
      color: inherit;
      opacity: 0.75;
    }
    .chip__value {
      font-weight: var(--font-weight-semibold);
    }
    .chips button {
      display: grid;
      place-items: center;
      width: 20px;
      height: 20px;
      color: inherit;
      background: color-mix(in srgb, currentColor 12%, transparent);
      border: 0;
      border-radius: var(--radius-full);
      cursor: pointer;
    }
    .chips button:hover {
      background: color-mix(in srgb, currentColor 22%, transparent);
    }

    .clear {
      padding: 0;
      font: inherit;
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-medium);
      color: var(--text-muted);
      background: none;
      border: 0;
      text-decoration: underline;
      text-underline-offset: 2px;
      cursor: pointer;
    }
    .clear:hover {
      color: var(--text);
    }

    .summary {
      margin-left: auto;
      font-size: var(--font-size-xs);
      color: var(--text-muted);
      font-variant-numeric: tabular-nums;
    }
  `,
})
export class UiFilterBar {
  /** What is typed in the search box. Two-way: the host debounces and queries. */
  readonly search = model('');

  readonly searchPlaceholder = input('Search');
  readonly searchLabel = input('Search');

  /** For a table whose only filters are selects. */
  readonly searchHidden = input(false, { transform: booleanAttribute });

  readonly chips = input<readonly FilterChip[]>([]);

  /** "12 of 340 orders" — the host words it, because only it knows the noun. */
  readonly summary = input<string | null>(null);

  readonly removed = output<string>();
  readonly clearedAll = output<void>();

  protected readonly searchIcon = Search;
  protected readonly clearIcon = X;

  /** Whether anything is on, for a host that wants to say so elsewhere. */
  readonly active = computed(() => this.chips().length > 0);

  private readonly injector = inject(Injector);
  private readonly chipList = viewChild<ElementRef<HTMLUListElement>>('chipList');
  private readonly searchBox = viewChild<ElementRef<HTMLInputElement>>('searchBox');

  /**
   * Take one filter off, and leave focus somewhere.
   *
   * The button that was pressed is gone the moment the host re-renders, and
   * a removed button takes focus to <body> with it — so somebody clearing
   * three filters by keyboard is thrown to the top of the page twice. Focus
   * lands on the chip that took this one's place, or the last one if this was
   * the last, or the search box when the row empties.
   */
  drop(key: string, index: number): void {
    this.removed.emit(key);
    this.settle(index);
  }

  dropAll(): void {
    this.clearedAll.emit();
    this.settle(0);
  }

  private settle(index: number): void {
    afterNextRender(
      () => {
        const buttons = Array.from(this.chipList()?.nativeElement.querySelectorAll('button') ?? []);

        if (buttons.length > 0) {
          (buttons[Math.min(index, buttons.length - 1)] as HTMLButtonElement).focus();
          return;
        }

        this.searchBox()?.nativeElement.focus();
      },
      { injector: this.injector },
    );
  }
}
