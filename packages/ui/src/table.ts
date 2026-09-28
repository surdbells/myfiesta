import { Component, computed, input, output } from '@angular/core';
import { ChevronDown, ChevronUp, ChevronsUpDown } from 'lucide-angular';
import { UiIcon, type LucideIconData } from './icon';

/** Which way a column is sorted, or not. */
export type SortDirection = 'asc' | 'desc' | null;

/** The column and direction a table is sorted by. */
export interface Sort {
  readonly column: string;
  readonly direction: Exclude<SortDirection, null>;
}

/**
 * The chrome around a table.
 *
 * Deliberately *not* a column-configuration API. Every table in this console
 * has a cell that wants a badge, a currency, a link, or two lines stacked —
 * and a config-driven table answers that with a cell-template escape hatch per
 * column, which is more machinery than writing the `<td>`.
 *
 * So the caller writes real table markup and this supplies the four things
 * every one of those screens was reimplementing:
 *
 *   - a horizontal scroll container, so a wide table scrolls itself instead of
 *     the page
 *   - a sticky header that survives that scroll
 *   - the loading state, as skeleton rows rather than a spinner that collapses
 *     the layout and makes the page jump when data lands
 *   - the empty state, in the table's own body rather than replacing it, so
 *     the column headings stay readable and say what would have been here
 */
@Component({
  selector: 'ui-table',
  template: `
    <div class="table__scroll" [attr.aria-busy]="loading() ? 'true' : null">
      <table class="table">
        <caption class="sr-only">{{ caption() }}</caption>
        <ng-content select="[tableHead]" />

        @if (loading()) {
          <tbody class="table__loading">
            @for (row of skeletonRows(); track row) {
              <tr>
                @for (cell of skeletonCells(); track cell) {
                  <td><span class="table__skeleton"></span></td>
                }
              </tr>
            }
          </tbody>
        } @else {
          <ng-content />
        }
      </table>
    </div>

    @if (!loading() && empty()) {
      <div class="table__empty"><ng-content select="[tableEmpty]" /></div>
    }
  `,
  styles: `
    .table__scroll {
      overflow-x: auto;
      /* The header sticks to the top of this box, so it needs to be the thing
         that scrolls vertically too when the caller constrains the height. */
      max-height: inherit;
      border-radius: var(--radius-card);
    }
    .table { width: 100%; border-collapse: collapse; }
    .table__loading td { padding: var(--space-3) var(--space-4); }
    .table__skeleton {
      display: block;
      height: 1em;
      border-radius: var(--radius-sm);
      background-color: var(--surface-inset);
      animation: table-pulse 1.4s ease-in-out infinite;
    }
    /* Varying the widths stops six identical grey bars reading as a rendering
       fault rather than as data on its way. */
    .table__loading tr td:first-child .table__skeleton { width: 70%; }
    .table__loading tr td:last-child .table__skeleton { width: 40%; }
    @keyframes table-pulse {
      50% { opacity: 0.45; }
    }
    @media (prefers-reduced-motion: reduce) {
      .table__skeleton { animation: none; opacity: 0.7; }
    }
    .table__empty { padding: var(--space-6) var(--space-4); }
  `,
})
export class UiTable {
  /** Describes the table to a screen reader. Not shown. */
  readonly caption = input.required<string>();

  readonly loading = input(false);

  /** Whether there is nothing to show. The caller knows; the table cannot. */
  readonly empty = input(false);

  /**
   * How many skeleton rows to draw.
   *
   * Defaults to a page rather than to one, because a single grey row followed
   * by twenty real ones is a worse transition than twenty followed by twenty.
   */
  readonly loadingRows = input(8);
  readonly loadingColumns = input(5);

  readonly skeletonRows = computed(() =>
    Array.from({ length: this.loadingRows() }, (_, i) => i),
  );
  readonly skeletonCells = computed(() =>
    Array.from({ length: this.loadingColumns() }, (_, i) => i),
  );
}

/**
 * A sortable column heading.
 *
 * The whole heading is the button, not a small arrow next to it — the arrow is
 * a 12px target and the heading is the thing people aim at.
 *
 * `aria-sort` goes on the `<th>` and is what a screen reader announces; the
 * arrow is decorative and hidden from it.
 */
@Component({
  selector: 'th[uiSort]',
  imports: [UiIcon],
  host: {
    '[attr.aria-sort]': 'ariaSort()',
    scope: 'col',
  },
  template: `
    <button type="button" class="sort" (click)="toggle()">
      <span>{{ label() }}</span>
      <ui-icon class="sort__mark" [class.is-sorted]="isSorted()" [icon]="mark()" size="sm" />
    </button>
  `,
  styles: `
    :host { padding: 0; }
    .sort {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      width: 100%;
      padding: var(--space-3) var(--space-4);
      font: inherit;
      color: inherit;
      text-align: inherit;
      background: none;
      border: 0;
      cursor: pointer;
    }
    .sort:hover { color: var(--text); background-color: var(--surface-inset); }
    .sort__mark {
      /* Always present, so turning sorting on does not shift every other
         heading sideways — and faded until this is the sorted column, so it
         reads as an invitation rather than as a claim. */
      color: var(--text-subtle);
      opacity: 0.5;
    }
    .sort:hover .sort__mark { opacity: 0.9; }
    .sort__mark.is-sorted {
      color: var(--primary-text);
      opacity: 1;
    }
  `,
})
export class UiSortHeader {
  readonly label = input.required<string>();

  /** The key sent to the API for this column. */
  readonly uiSort = input.required<string>();

  /** The table's current sort, whichever column it is on. */
  readonly sort = input<Sort | null>(null);

  readonly sorted = output<Sort>();

  private readonly direction = computed<SortDirection>(() => {
    const sort = this.sort();
    return sort && sort.column === this.uiSort() ? sort.direction : null;
  });

  readonly ariaSort = computed(() => {
    const direction = this.direction();
    return direction === 'asc'
      ? 'ascending'
      : direction === 'desc'
        ? 'descending'
        : 'none';
  });

  /**
   * The glyph, including one for "sortable, but not sorted".
   *
   * The unsorted state used to render nothing, which cost two things: the
   * heading shifted sideways the moment somebody sorted it, and a column gave
   * no sign it could be sorted until it already had been. A faded double
   * chevron answers both — it holds the space and it is the conventional
   * invitation.
   */
  readonly mark = computed<LucideIconData>(() => {
    const direction = this.direction();

    return direction === 'asc' ? ChevronUp : direction === 'desc' ? ChevronDown : ChevronsUpDown;
  });

  /** Whether this column is the one being sorted by. Drives the fade. */
  readonly isSorted = computed(() => this.direction() !== null);

  toggle(): void {
    this.sorted.emit({
      column: this.uiSort(),
      // First click on an unsorted column sorts ascending. Clicking the sorted
      // column reverses it; there is no third click back to unsorted, because
      // "no order" is not a state anybody is trying to reach.
      direction: this.direction() === 'asc' ? 'desc' : 'asc',
    });
  }
}
