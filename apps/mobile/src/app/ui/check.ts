import { Component, booleanAttribute, input, model } from '@angular/core';

/**
 * A checkbox with its label, as one row.
 *
 * For a yes/no that is a statement somebody agrees to or a property something
 * has — "ID is checked at the door", "hide the remaining count" — where a
 * switch would read as a setting that takes effect the moment it flips.
 *
 * A real checkbox underneath, drawn over, so the label, the keyboard and every
 * screen reader work as they always have. The whole row is the target.
 */
@Component({
  selector: 'mf-check',
  template: `
    <label class="row" [class.disabled]="disabled()">
      <input
        type="checkbox"
        class="input"
        [checked]="value()"
        [disabled]="disabled()"
        (change)="value.set($any($event.target).checked)"
      />
      <span class="box" aria-hidden="true">
        <svg viewBox="0 0 16 16"><path d="M3.5 8.5l3 3 6-7" /></svg>
      </span>
      <span class="text">
        <span class="label">{{ label() }}</span>
        @if (hint()) {
          <span class="hint">{{ hint() }}</span>
        }
      </span>
    </label>
  `,
  styles: `
    :host {
      display: block;
    }

    .row {
      display: flex;
      align-items: flex-start;
      gap: var(--space-3);
      min-height: var(--mf-tap);
      padding: var(--space-3) 0;
      cursor: pointer;
    }

    .row.disabled {
      opacity: 0.5;
      cursor: default;
    }

    .input {
      position: absolute;
      opacity: 0;
      width: 1px;
      height: 1px;
    }

    .box {
      flex: none;
      display: grid;
      place-items: center;
      width: 22px;
      height: 22px;
      margin-top: 1px;
      border-radius: var(--radius-sm);
      background: var(--surface-inset);
      box-shadow: inset 0 0 0 1.5px var(--border-strong);
      transition:
        background-color 120ms ease,
        box-shadow 120ms ease;
    }

    svg {
      width: 14px;
      height: 14px;
      fill: none;
      stroke: var(--on-primary);
      stroke-width: 2.4;
      stroke-linecap: round;
      stroke-linejoin: round;
      stroke-dasharray: 16;
      stroke-dashoffset: 16;
      transition: stroke-dashoffset 180ms ease;
    }

    .input:checked + .box {
      background: var(--primary);
      box-shadow: none;
    }

    .input:checked + .box svg {
      stroke-dashoffset: 0;
    }

    .input:focus-visible + .box {
      outline: 2px solid var(--primary);
      outline-offset: 2px;
    }

    .text {
      display: grid;
      gap: 2px;
      min-width: 0;
    }

    .label {
      font-weight: var(--font-weight-medium);
      line-height: 1.35;
    }

    .hint {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      line-height: var(--font-leading-snug);
    }
  `,
})
export class MfCheck {
  readonly label = input.required<string>();
  readonly hint = input<string | null>(null);
  readonly disabled = input(false, { transform: booleanAttribute });

  readonly value = model(false);
}
