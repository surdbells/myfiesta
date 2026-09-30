import { Component, computed, input, output, signal, type Signal } from '@angular/core';
import { X } from 'lucide-angular';
import { UiIcon } from './icon';

/**
 * The rows somebody has ticked in a table.
 *
 * Holds ids, not rows, so a page reloading its data does not lose the ticks
 * on rows that are still there. The screen clears it whenever the set of
 * rows changes meaning — another filter, another page — because a tick on a
 * row nobody can see any more is how a bulk action lands on something the
 * person did not mean.
 */
export class Selection {
  private readonly chosen = signal<ReadonlySet<string>>(new Set());

  readonly ids: Signal<readonly string[]> = computed(() => [...this.chosen()]);
  readonly count = computed(() => this.chosen().size);

  has(id: string): boolean {
    return this.chosen().has(id);
  }

  toggle(id: string): void {
    const next = new Set(this.chosen());
    if (next.has(id)) next.delete(id);
    else next.add(id);
    this.chosen.set(next);
  }

  /** The heading's box: ticks every row shown, or unticks them all if it already had. */
  toggleAll(ids: readonly string[]): void {
    this.chosen.set(this.all(ids) ? new Set() : new Set(ids));
  }

  all(ids: readonly string[]): boolean {
    return ids.length > 0 && ids.every((id) => this.chosen().has(id));
  }

  some(ids: readonly string[]): boolean {
    return !this.all(ids) && ids.some((id) => this.chosen().has(id));
  }

  clear(): void {
    if (this.chosen().size > 0) this.chosen.set(new Set());
  }

  /** Keep only the ticks on rows that still exist, after a reload. */
  keep(ids: readonly string[]): void {
    const present = new Set(ids);
    const next = [...this.chosen()].filter((id) => present.has(id));
    if (next.length !== this.chosen().size) this.chosen.set(new Set(next));
  }
}

/**
 * What can be done to the ticked rows, floating over the bottom of the table.
 *
 * Appears only when something is ticked, says how many, and keeps the way to
 * untick them all beside the actions — a bulk bar with no count is how a
 * refund meant for three orders goes to thirty.
 */
@Component({
  selector: 'ui-bulk-bar',
  imports: [UiIcon],
  template: `
    @if (count() > 0) {
      <div class="bulk" role="region" [attr.aria-label]="count() + ' ' + said() + ' selected'">
        <p class="bulk__count" aria-live="polite">
          <strong>{{ count() }}</strong> {{ said() }} selected
        </p>
        <div class="bulk__actions">
          <ng-content />
        </div>
        <button type="button" class="bulk__clear" aria-label="Clear the selection" (click)="cleared.emit()">
          <ui-icon [icon]="clearIcon" size="sm" />
        </button>
      </div>
    }
  `,
  styles: `
    :host {
      position: sticky;
      bottom: var(--space-4);
      z-index: 5;
      display: flex;
      justify-content: center;
      pointer-events: none;
    }
    .bulk {
      pointer-events: auto;
      display: flex;
      align-items: center;
      gap: var(--space-4);
      margin-top: var(--space-3);
      padding: var(--space-2) var(--space-2) var(--space-2) var(--space-4);
      color: var(--text-inverse);
      background: var(--text);
      border-radius: var(--radius-overlay);
      box-shadow: var(--shadow-floating);
      animation: ui-rise var(--motion-base) var(--motion-ease) both;
    }
    .bulk__count {
      font-size: var(--font-size-sm);
      white-space: nowrap;
    }
    .bulk__actions {
      display: flex;
      align-items: center;
      gap: var(--space-2);
    }
    .bulk__clear {
      display: grid;
      place-items: center;
      width: 32px;
      height: 32px;
      padding: 0;
      color: inherit;
      background: transparent;
      border: 0;
      border-radius: var(--radius-control);
      cursor: pointer;
      opacity: 0.8;
    }
    .bulk__clear:hover {
      opacity: 1;
      background: color-mix(in srgb, var(--text-inverse) 14%, transparent);
    }
  `,
})
export class UiBulkBar {
  readonly count = input.required<number>();
  /** "orders", "codes": what is selected. */
  readonly noun = input('rows');
  /** One of them: "order", "code". Without it, the noun less its final "s". */
  readonly one = input<string | null>(null);
  readonly cleared = output<void>();

  /** "1 guest selected", not "1 guests selected". */
  protected readonly said = computed(() =>
    this.count() === 1 ? (this.one() ?? this.noun().replace(/s$/, '')) : this.noun(),
  );

  protected readonly clearIcon = X;
}
