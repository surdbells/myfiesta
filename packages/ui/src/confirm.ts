import { Component, computed, input, output, signal } from '@angular/core';
import { UiButton } from './button';
import { UiModal } from './modal';

/**
 * Are you sure — asked properly.
 *
 * A confirm dialog that says "are you sure?" and nothing else is asking
 * somebody to guess. This one requires a consequence: what will happen, stated
 * in the caller's words, above the button that does it.
 *
 * For the genuinely irreversible, `confirmWord` makes somebody type the thing's
 * name. Deliberately not the default — friction on every delete trains people
 * to type without reading, which is worse than no friction at all.
 */
@Component({
  selector: 'ui-confirm',
  imports: [UiModal, UiButton],
  template: `
    <ui-modal
      [heading]="heading()"
      [description]="consequence()"
      [open]="open()"
      (dismissed)="cancelled.emit()"
    >
      <ng-content />

      @if (confirmWord(); as word) {
        <div class="field">
          <label [for]="'confirm-' + word">
            Type <strong>{{ word }}</strong> to confirm
          </label>
          <input
            [id]="'confirm-' + word"
            name="confirmWord"
            autocomplete="off"
            spellcheck="false"
            [value]="typed()"
            (input)="typed.set($any($event.target).value)"
          />
        </div>
      }

      <div modalActions>
        <button uiButton variant="ghost" type="button" (click)="cancelled.emit()">
          {{ cancelLabel() }}
        </button>
        <button
          uiButton
          [variant]="destructive() ? 'danger' : 'primary'"
          type="button"
          [disabled]="!ready() || busy()"
          [loading]="busy()"
          (click)="confirmed.emit()"
        >
          {{ busy() ? busyLabel() : confirmLabel() }}
        </button>
      </div>
    </ui-modal>
  `,
  styles: `
    .field { display: grid; gap: var(--space-2); }
    label { font-size: var(--font-size-sm); font-weight: var(--font-weight-medium); }
    input {
      height: 44px;
      padding: 0 var(--space-3);
      font: inherit;
      font-size: var(--font-size-sm);
      color: var(--text);
      background-color: var(--surface-raised);
      border: 1px solid var(--field-border);
      border-radius: var(--radius-md);
    }
    input:focus-visible {
      outline: none;
      border-color: var(--primary);
      box-shadow: var(--focus-ring);
    }
  `,
})
export class UiConfirm {
  readonly open = input(false);
  readonly heading = input.required<string>();

  /** What will actually happen. Required, because "are you sure?" is not a question. */
  readonly consequence = input.required<string>();

  readonly confirmLabel = input('Confirm');
  readonly busyLabel = input('Working…');
  readonly cancelLabel = input('Cancel');
  readonly destructive = input(false);
  readonly busy = input(false);

  /** Reserve for the irreversible. Friction everywhere trains people past it. */
  readonly confirmWord = input<string | null>(null);

  readonly confirmed = output<void>();
  readonly cancelled = output<void>();

  readonly typed = signal('');

  readonly ready = computed(() => {
    const word = this.confirmWord();

    return word === null || this.typed().trim().toLowerCase() === word.toLowerCase();
  });
}

