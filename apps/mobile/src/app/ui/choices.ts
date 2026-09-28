import { Component, input, model } from '@angular/core';
import { MfIcon, type LucideIconData } from './icon';

export interface MfChoice {
  value: string;
  label: string;
  hint?: string;
  icon?: LucideIconData;
  disabled?: boolean;
}

/**
 * One of a few, with room to say what each one means.
 *
 * Radio buttons, drawn as rows or as cards. For choices that need their
 * consequences spelled out — who a campaign goes to, how a code discounts,
 * whether a question is asked once or of every guest — where a select would
 * hide the explanation behind a tap and a segmented control has no room for it.
 * Three to five options; more than that is a select.
 */
@Component({
  selector: 'mf-choices',
  imports: [MfIcon],
  template: `
    <fieldset class="set" [class.cards]="variant() === 'cards'">
      @if (legend()) {
        <legend class="legend">{{ legend() }}</legend>
      }

      @for (choice of options(); track choice.value) {
        <label class="choice" [class.on]="choice.value === value()" [class.disabled]="choice.disabled">
          <input
            type="radio"
            class="input"
            [name]="name()"
            [value]="choice.value"
            [checked]="choice.value === value()"
            [disabled]="choice.disabled"
            (change)="value.set(choice.value)"
          />
          @if (choice.icon) {
            <span class="glyph"><mf-icon [icon]="choice.icon" /></span>
          }
          <span class="text">
            <span class="label">{{ choice.label }}</span>
            @if (choice.hint) {
              <span class="hint">{{ choice.hint }}</span>
            }
          </span>
          <span class="dot" aria-hidden="true"></span>
        </label>
      }
    </fieldset>
  `,
  styles: `
    :host {
      display: block;
    }

    .set {
      display: grid;
      gap: var(--space-2);
      margin: 0;
      padding: 0;
      border: 0;
      min-width: 0;
    }

    .legend {
      margin-bottom: var(--space-2);
      padding: 0;
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
    }

    .choice {
      position: relative;
      display: flex;
      align-items: center;
      gap: var(--space-3);
      min-height: 56px;
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-card);
      background: var(--surface-raised);
      box-shadow: inset 0 0 0 1px var(--border);
      cursor: pointer;
      transition:
        box-shadow 140ms ease,
        background-color 140ms ease;
    }

    .choice:active {
      background: var(--surface-hover);
    }

    .choice.on {
      background: color-mix(in srgb, var(--primary) 7%, var(--surface-raised));
      box-shadow: inset 0 0 0 2px var(--primary);
    }

    .choice.disabled {
      opacity: 0.5;
      cursor: default;
    }

    .cards .choice {
      align-items: flex-start;
      padding: var(--space-4);
      box-shadow:
        inset 0 0 0 1px var(--border),
        var(--shadow-card);
    }

    .cards .choice.on {
      box-shadow:
        inset 0 0 0 2px var(--primary),
        var(--shadow-raised);
    }

    .input {
      position: absolute;
      opacity: 0;
      width: 1px;
      height: 1px;
    }

    .input:focus-visible ~ .dot {
      outline: 2px solid var(--primary);
      outline-offset: 2px;
    }

    .glyph {
      flex: none;
      display: grid;
      place-items: center;
      width: 36px;
      height: 36px;
      border-radius: var(--radius-md);
      background: var(--surface-inset);
      color: var(--text-muted);
    }

    .choice.on .glyph {
      background: var(--primary-soft);
      color: var(--primary-text);
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
      line-height: var(--font-leading-snug);
    }

    .dot {
      flex: none;
      width: 22px;
      height: 22px;
      border-radius: var(--radius-full);
      box-shadow: inset 0 0 0 1.5px var(--border-strong);
      transition: box-shadow 140ms ease;
    }

    .choice.on .dot {
      box-shadow:
        inset 0 0 0 6px var(--primary),
        inset 0 0 0 11px var(--surface-raised);
    }
  `,
})
export class MfChoices {
  readonly options = input.required<MfChoice[]>();
  readonly legend = input<string | null>(null);
  /** Rows for plain choices; cards, with a shadow each, for the weighty ones. */
  readonly variant = input<'rows' | 'cards'>('rows');
  readonly name = input(`mf-choices-${Math.random().toString(36).slice(2, 8)}`);

  readonly value = model<string>('');
}
