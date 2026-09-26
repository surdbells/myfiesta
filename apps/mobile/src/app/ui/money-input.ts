import { Component, booleanAttribute, computed, effect, input, model, signal } from '@angular/core';
import { MfField } from './field';
import { currencySymbol, toMajor, toMinor } from '../core/money';

/**
 * A price, typed in major units and held in minor ones.
 *
 * Every amount in this app is an integer number of cents or kobo, because a
 * float is how 19.99 becomes 1998.9999. This is the one place a person types
 * money, so it is the one place that converts: the box shows "25.00" with the
 * currency in front, and the value bound to it is 2500.
 *
 * The text is left alone while it is being typed — reformatting "25." to
 * "25.00" under somebody's thumb moves the cursor — and tidied on blur.
 */
@Component({
  selector: 'mf-money',
  imports: [MfField],
  template: `
    <mf-field [label]="label()" [hint]="hint()" [error]="error()" [optional]="optional()" [prefix]="symbol()">
      <input
        type="text"
        inputmode="decimal"
        autocomplete="off"
        [placeholder]="placeholder()"
        [value]="text()"
        [disabled]="disabled()"
        (input)="typed($any($event.target).value)"
        (blur)="tidy()"
      />
    </mf-field>
  `,
  styles: `
    :host {
      display: block;
    }

    input {
      font-variant-numeric: tabular-nums;
    }
  `,
})
export class MfMoney {
  readonly label = input.required<string>();
  readonly currency = input.required<string>();
  readonly hint = input<string | null>(null);
  readonly error = input<string | null>(null);
  readonly placeholder = input('0.00');
  readonly optional = input(false, { transform: booleanAttribute });
  readonly disabled = input(false, { transform: booleanAttribute });

  /** Minor units. Null is nothing typed. */
  readonly value = model<number | null>(null);

  protected readonly symbol = computed(() => currencySymbol(this.currency()));
  protected readonly text = signal('');

  /** The last value this control set itself, so an outside change can be told apart. */
  private own: number | null | undefined = undefined;

  constructor() {
    // A value arriving from outside (the form loaded) is shown formatted; one
    // this control just set from typing is left as typed.
    effect(() => {
      const value = this.value();

      if (value !== this.own) this.text.set(toMajor(value));
    });
  }

  protected typed(raw: string): void {
    this.text.set(raw);
    this.own = toMinor(raw);
    this.value.set(this.own);
  }

  protected tidy(): void {
    this.text.set(toMajor(this.value()));
  }
}
