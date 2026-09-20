import { Component, booleanAttribute, input, output } from '@angular/core';

/**
 * On or off.
 *
 * A real checkbox underneath, drawn over: the platform switch is the one
 * control iOS and Android draw most differently from each other, and this app
 * is the same product on both. Keeping the input means the label, the keyboard
 * and every screen reader still work the way they already did.
 *
 * The whole row is the target, because a thumb aimed at a 30-pixel track in a
 * moving vehicle misses.
 */
@Component({
  selector: 'mf-switch',
  template: `
    <label class="row">
      <span class="text">
        <span class="label">{{ label() }}</span>
        @if (hint()) {
          <span class="hint">{{ hint() }}</span>
        }
      </span>

      <input
        type="checkbox"
        class="input"
        [checked]="checked()"
        [disabled]="disabled()"
        (change)="changed.emit($any($event.target).checked)"
      />
      <span class="track" aria-hidden="true"><span class="thumb"></span></span>
    </label>
  `,
  styles: `
    :host {
      display: block;
    }

    .row {
      display: flex;
      align-items: center;
      gap: var(--space-4);
      min-height: var(--mf-tap);
      cursor: pointer;
    }

    .text {
      flex: 1;
      min-width: 0;
      display: grid;
      gap: var(--space-1);
    }

    .label {
      font-size: var(--font-size-base);
      color: var(--text);
    }

    .hint {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    /* Present for everything that reads the page; the track below is what a
       person sees. */
    .input {
      position: absolute;
      width: 1px;
      height: 1px;
      opacity: 0;
      pointer-events: none;
    }

    .track {
      flex: none;
      position: relative;
      width: 3.25rem;
      height: 1.9rem;
      border-radius: var(--radius-full);
      background: var(--surface-inset);
      box-shadow: inset 0 0 0 1px var(--border-subtle);
      transition:
        background-color 140ms ease,
        box-shadow 140ms ease;
    }

    .thumb {
      position: absolute;
      top: 0.2rem;
      left: 0.2rem;
      width: 1.5rem;
      height: 1.5rem;
      border-radius: var(--radius-full);
      background: var(--surface);
      /* A hairline as well as a shadow: in dark the thumb and the unlit track
         are both dark, and a shadow alone leaves nothing to see. */
      box-shadow: var(--shadow-card), inset 0 0 0 1px var(--border);
      transition: transform 140ms ease;
    }

    .input:checked ~ .track {
      background: var(--primary);
      box-shadow: none;
    }

    .input:checked ~ .track .thumb {
      transform: translateX(1.35rem);
    }

    .input:focus-visible ~ .track {
      outline: 2px solid var(--primary);
      outline-offset: 2px;
    }

    .input:disabled ~ .track {
      opacity: 0.5;
    }

    @media (prefers-reduced-motion: reduce) {
      .track,
      .thumb {
        transition: none;
      }
    }
  `,
})
export class MfSwitch {
  readonly label = input('');
  readonly hint = input<string | null>(null);
  readonly checked = input(false, { transform: booleanAttribute });
  readonly disabled = input(false, { transform: booleanAttribute });

  readonly changed = output<boolean>();
}
