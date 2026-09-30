import { Component, booleanAttribute, input, model } from '@angular/core';
import { Check } from 'lucide-angular';
import { MfIcon } from './icon';

export interface MfChip {
  value: string;
  label: string;
  /** A count beside the label: how many are in this filter. */
  count?: number;
}

/**
 * A row of chips: quick filters above a list, or several-of-many.
 *
 * Single choice by default — the filter chips over a list of orders ("All",
 * "Paid", "Refunded") where one is always on. `multiple` turns them into
 * toggles for picking several at once, with a tick on each that is on.
 *
 * The row scrolls sideways rather than wrapping, so a long set of filters
 * never pushes the list itself down the screen. It draws no scrollbar while
 * it does (scroll-x, in styles.css); every chip is a button, so a keyboard
 * reaches the ones past the edge by moving to them.
 */
@Component({
  selector: 'mf-chips',
  imports: [MfIcon],
  template: `
    <div class="row scroll-x" role="group" [attr.aria-label]="ariaLabel()">
      @for (chip of options(); track chip.value) {
        <button
          type="button"
          class="chip"
          [class.on]="isOn(chip.value)"
          [attr.aria-pressed]="isOn(chip.value)"
          (click)="toggle(chip.value)"
        >
          @if (multiple() && isOn(chip.value)) {
            <mf-icon [icon]="tick" size="sm" />
          }
          {{ chip.label }}
          @if (chip.count !== undefined) {
            <span class="count">{{ chip.count }}</span>
          }
        </button>
      }
    </div>
  `,
  styles: `
    :host {
      display: block;
      min-width: 0;
    }

    .row {
      display: flex;
      gap: var(--space-2);
      /* Let the chips run to the screen edge and scroll from there. */
      margin: 0 calc(var(--space-5) * -1);
      padding: 2px var(--space-5);
    }

    .chip {
      flex: none;
      display: inline-flex;
      align-items: center;
      gap: var(--space-1);
      height: 36px;
      padding: 0 var(--space-4);
      border: 0;
      border-radius: var(--radius-full);
      background: var(--surface-raised);
      box-shadow: inset 0 0 0 1px var(--border);
      color: var(--text);
      font: inherit;
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
      white-space: nowrap;
      cursor: pointer;
      transition:
        background-color 140ms ease,
        color 140ms ease,
        transform 90ms ease;
    }

    .chip:active {
      transform: scale(0.96);
    }

    .chip.on {
      background: var(--text);
      color: var(--surface);
      box-shadow: none;
    }

    .count {
      font-variant-numeric: tabular-nums;
      opacity: 0.65;
    }
  `,
})
export class MfChips {
  readonly options = input.required<MfChip[]>();
  readonly ariaLabel = input<string | null>(null);
  readonly multiple = input(false, { transform: booleanAttribute });

  /** Single: the one that is on. Multiple: use `values`. */
  readonly value = model('');
  readonly values = model<string[]>([]);

  protected readonly tick = Check;

  protected isOn(value: string): boolean {
    return this.multiple() ? this.values().includes(value) : this.value() === value;
  }

  protected toggle(value: string): void {
    if (!this.multiple()) {
      this.value.set(value);
      return;
    }

    const now = this.values();
    this.values.set(now.includes(value) ? now.filter((v) => v !== value) : [...now, value]);
  }
}
