import { Component, computed, input, output, signal, effect } from '@angular/core';

/** Both ends in the currency's smallest unit (cents, kobo); null is open. */
export interface AmountRange {
  readonly min: number | null;
  readonly max: number | null;
}

/**
 * "Between $50 and $500": two amounts, typed in whole units and sent in the
 * smallest one.
 *
 * Typed as people say money — 50, 49.99, 1,250 — and held in minor units like
 * every amount in the product, so the server never sees a float. Applied when
 * a field is left or Enter is pressed rather than on every keystroke: "5"
 * on the way to "500" is not a filter anybody asked for. A range written the
 * wrong way round is turned the right way rather than refused.
 */
@Component({
  selector: 'ui-amount-range',
  template: `
    <div class="range" role="group" [attr.aria-label]="label()">
      <label class="range__end">
        <span class="range__prefix" aria-hidden="true">{{ symbol() }}</span>
        <input
          type="text"
          inputmode="decimal"
          autocomplete="off"
          placeholder="Min"
          [attr.aria-label]="'Lowest ' + label().toLowerCase()"
          [value]="minText()"
          (input)="minText.set($any($event.target).value)"
          (blur)="commit()"
          (keydown.enter)="commit()"
        />
      </label>
      <span class="range__to" aria-hidden="true">–</span>
      <label class="range__end">
        <span class="range__prefix" aria-hidden="true">{{ symbol() }}</span>
        <input
          type="text"
          inputmode="decimal"
          autocomplete="off"
          placeholder="Max"
          [attr.aria-label]="'Highest ' + label().toLowerCase()"
          [value]="maxText()"
          (input)="maxText.set($any($event.target).value)"
          (blur)="commit()"
          (keydown.enter)="commit()"
        />
      </label>
    </div>
  `,
  styles: `
    :host { display: block; min-width: 0; }
    .range { display: flex; align-items: center; gap: var(--space-2); }
    .range__end { position: relative; flex: 1 1 6rem; min-width: 0; }
    .range__prefix {
      position: absolute;
      left: var(--space-3);
      top: 50%;
      transform: translateY(-50%);
      font-size: var(--font-size-sm);
      color: var(--text-subtle);
      pointer-events: none;
    }
    .range__end input { padding-left: calc(var(--space-3) + 1.25em); font-variant-numeric: tabular-nums; }
    .range__to { color: var(--text-subtle); }
  `,
})
export class UiAmountRange {
  readonly value = input<AmountRange>({ min: null, max: null });
  /** ISO code, for the symbol in front of each field. */
  readonly currency = input<string>('CAD');
  /** What the amounts are: "Order total". */
  readonly label = input('Amount');

  readonly valueChange = output<AmountRange>();

  protected readonly minText = signal('');
  protected readonly maxText = signal('');

  protected readonly symbol = computed(() => {
    try {
      const parts = new Intl.NumberFormat('en', { style: 'currency', currency: this.currency(), currencyDisplay: 'narrowSymbol' }).formatToParts(0);
      return parts.find((p) => p.type === 'currency')?.value ?? '';
    } catch {
      return '';
    }
  });

  constructor() {
    // Follows the value from outside — a chip removed, a saved view applied.
    effect(() => {
      const { min, max } = this.value();
      this.minText.set(format(min));
      this.maxText.set(format(max));
    });
  }

  protected commit(): void {
    let min = parseAmount(this.minText());
    let max = parseAmount(this.maxText());

    if (min !== null && max !== null && min > max) [min, max] = [max, min];

    this.minText.set(format(min));
    this.maxText.set(format(max));

    const current = this.value();
    if (current.min !== min || current.max !== max) this.valueChange.emit({ min, max });
  }
}

/** "1,250.5" → 125050. Anything that is not an amount is an open end. */
export function parseAmount(text: string): number | null {
  const cleaned = text.replace(/[\s,]/g, '').replace(/^[^\d.]+/, '');
  if (cleaned === '' || !/^\d*(\.\d{0,2})?$/.test(cleaned) || cleaned === '.') return null;

  const [whole, fraction = ''] = cleaned.split('.');
  return Number(whole || '0') * 100 + Number((fraction + '00').slice(0, 2));
}

function format(minor: number | null): string {
  if (minor === null) return '';
  const whole = Math.floor(minor / 100);
  const cents = minor % 100;
  return cents === 0 ? String(whole) : `${whole}.${String(cents).padStart(2, '0')}`;
}
