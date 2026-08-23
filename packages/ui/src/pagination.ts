import { Component, computed, input, output } from '@angular/core';
import { UiButton } from './button';

/**
 * Paging controls for a server-paged list.
 *
 * Shows the range in words — "1–25 of 312" — before the buttons, because that
 * is the question somebody actually has when they reach the bottom of a table:
 * not "which page am I on" but "how much of this is there".
 *
 * Deliberately previous/next rather than a numbered strip. Numbered pages are
 * for jumping to a remembered position, which nobody does with an attendee
 * list; what they do is scan forward, and a page-number strip on 312 rows is
 * thirteen targets where two would do.
 */
@Component({
  selector: 'ui-pagination',
  imports: [UiButton],
  template: `
    @if (total() > 0) {
      <nav class="pager" [attr.aria-label]="'Pages of ' + noun()">
        <p class="pager__range" aria-live="polite">
          <strong>{{ first() }}–{{ last() }}</strong> of
          <strong>{{ total() }}</strong> {{ noun() }}
        </p>

        <div class="pager__buttons">
          <button
            uiButton
            variant="secondary"
            size="sm"
            type="button"
            [disabled]="page() <= 1"
            (click)="changed.emit(page() - 1)"
          >
            Previous
          </button>
          <button
            uiButton
            variant="secondary"
            size="sm"
            type="button"
            [disabled]="page() >= pages()"
            (click)="changed.emit(page() + 1)"
          >
            Next
          </button>
        </div>
      </nav>
    }
  `,
  styles: `
    .pager {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: var(--space-4);
      flex-wrap: wrap;
      padding: var(--space-3) var(--space-4);
      border-top: 1px solid var(--border-subtle);
    }
    .pager__range {
      margin: 0;
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      font-variant-numeric: tabular-nums;
    }
    .pager__range strong { color: var(--text); font-weight: var(--font-weight-medium); }
    .pager__buttons { display: flex; gap: var(--space-2); }
  `,
})
export class UiPagination {
  readonly page = input.required<number>();
  readonly perPage = input.required<number>();
  readonly total = input.required<number>();

  /** What is being counted, plural and lowercase: "attendees", "orders". */
  readonly noun = input('results');

  readonly changed = output<number>();

  readonly pages = computed(() => Math.max(1, Math.ceil(this.total() / this.perPage())));
  readonly first = computed(() => (this.page() - 1) * this.perPage() + 1);
  // Clamped, because the last page is short and "301–325 of 312" is a bug
  // people notice immediately.
  readonly last = computed(() => Math.min(this.page() * this.perPage(), this.total()));
}
