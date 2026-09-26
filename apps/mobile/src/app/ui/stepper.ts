import { Component, booleanAttribute, computed, input, model } from '@angular/core';
import { Minus, Plus } from 'lucide-angular';
import { MfIcon } from './icon';

/**
 * A whole number, nudged up and down.
 *
 * For small counts that are adjusted rather than typed — how many tickets,
 * how many per order, how many people a table admits. Two big buttons either
 * side of the number, because a thumb on a phone held one-handed at a door
 * hits a 48px button and misses a spin-box arrow.
 *
 * Holding at the limit does nothing rather than wrapping: going from 10 back
 * to 1 by pressing "+" is a surprise nobody wants with money attached.
 */
@Component({
  selector: 'mf-stepper',
  imports: [MfIcon],
  template: `
    <div class="row">
      @if (label()) {
        <span class="text">
          <span class="label">{{ label() }}</span>
          @if (hint()) {
            <span class="hint">{{ hint() }}</span>
          }
        </span>
      }

      <div class="control" role="group" [attr.aria-label]="label() || null">
        <button
          type="button"
          class="step"
          [disabled]="disabled() || atMin()"
          [attr.aria-label]="'Fewer' + (label() ? ': ' + label() : '')"
          (click)="nudge(-1)"
        >
          <mf-icon [icon]="minus" size="sm" />
        </button>

        <output class="value" aria-live="polite">{{ value() }}</output>

        <button
          type="button"
          class="step"
          [disabled]="disabled() || atMax()"
          [attr.aria-label]="'More' + (label() ? ': ' + label() : '')"
          (click)="nudge(1)"
        >
          <mf-icon [icon]="plus" size="sm" />
        </button>
      </div>
    </div>
  `,
  styles: `
    :host {
      display: block;
    }

    .row {
      display: flex;
      align-items: center;
      gap: var(--space-4);
    }

    .text {
      flex: 1;
      display: grid;
      gap: 2px;
      min-width: 0;
    }

    .label {
      font-weight: var(--font-weight-medium);
    }

    .hint {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .control {
      display: inline-flex;
      align-items: center;
      gap: 2px;
      padding: 3px;
      border-radius: var(--radius-full);
      background: var(--surface-inset);
      box-shadow: inset 0 0 0 1px var(--border);
    }

    .step {
      display: grid;
      place-items: center;
      width: 42px;
      height: 42px;
      border: 0;
      border-radius: var(--radius-full);
      background: var(--surface-raised);
      color: var(--text);
      box-shadow: var(--shadow-card);
      cursor: pointer;
      transition: transform 90ms ease;
    }

    .step:active:not(:disabled) {
      transform: scale(0.9);
    }

    .step:disabled {
      background: transparent;
      box-shadow: none;
      color: var(--text-subtle);
      cursor: default;
    }

    .value {
      min-width: 2.75rem;
      text-align: center;
      font-family: var(--font-family-display);
      font-size: var(--font-size-lg);
      font-weight: var(--font-weight-semibold);
      font-variant-numeric: tabular-nums;
    }
  `,
})
export class MfStepper {
  readonly label = input('');
  readonly hint = input<string | null>(null);
  readonly min = input(0);
  readonly max = input<number | null>(null);
  readonly step = input(1);
  readonly disabled = input(false, { transform: booleanAttribute });

  readonly value = model(0);

  protected readonly minus = Minus;
  protected readonly plus = Plus;

  protected readonly atMin = computed(() => this.value() <= this.min());
  protected readonly atMax = computed(() => {
    const max = this.max();
    return max !== null && this.value() >= max;
  });

  protected nudge(direction: 1 | -1): void {
    const max = this.max();
    let next = this.value() + direction * this.step();

    next = Math.max(this.min(), next);
    if (max !== null) next = Math.min(max, next);

    this.value.set(next);
  }
}
