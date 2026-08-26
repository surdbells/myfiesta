import { Component, ElementRef, computed, effect, inject, input, output, viewChild } from '@angular/core';
import { X } from 'lucide-angular';
import { UiIcon } from './icon';

/**
 * A dialog, built on the native `<dialog>`.
 *
 * Native rather than a positioned div, because `showModal()` gives four things
 * for free that hand-rolled dialogs almost always get wrong: the top layer, so
 * it cannot be trapped behind a stacking context; a real focus trap; the rest
 * of the page made inert; and Escape.
 *
 * The only thing it does not give is closing on a backdrop click, which is
 * handled below by checking whether the click landed on the dialog element
 * itself — the backdrop is part of the dialog's own box, so a click on the
 * panel inside never matches.
 */
@Component({
  selector: 'ui-modal',
  imports: [UiIcon],
  template: `
    <dialog #dialog class="modal" (close)="dismissed.emit()" (click)="onBackdrop($event)">
      <div class="modal__panel">
        <header class="modal__head">
          <h2 class="modal__title">{{ heading() }}</h2>
          <button
            type="button"
            class="modal__x"
            aria-label="Close"
            (click)="close()"
          >
            <ui-icon [icon]="closeIcon" />
          </button>
        </header>

        @if (description()) {
          <p class="modal__desc">{{ description() }}</p>
        }

        <div class="modal__body"><ng-content /></div>

        <footer class="modal__foot"><ng-content select="[modalActions]" /></footer>
      </div>
    </dialog>
  `,
  styles: `
    .modal {
      padding: 0;
      max-width: min(560px, calc(100vw - 2 * var(--space-5)));
      width: 100%;
      color: var(--text);
      background-color: var(--surface-raised);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-overlay);
    }
    .modal::backdrop {
      /* Dark in both themes. A light scrim over a light page does not read as
         a scrim at all, and the point is to say the page behind is inert. */
      background-color: rgba(8, 12, 9, 0.55);
    }
    .modal__panel { display: grid; gap: var(--space-4); padding: var(--space-5); }
    .modal__head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: var(--space-4);
    }
    .modal__title {
      margin: 0;
      font-size: var(--font-size-xl);
      font-weight: var(--font-weight-semibold);
      letter-spacing: var(--font-tracking-tight);
    }
    .modal__desc {
      margin: calc(-1 * var(--space-2)) 0 0;
      max-width: 60ch;
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }
    .modal__x {
      /* 44px, because this is the control somebody reaches for in a hurry and
         a 24px close target is a miss on a phone. */
      width: 44px;
      height: 44px;
      margin: calc(-1 * var(--space-2)) calc(-1 * var(--space-2)) 0 0;
      font: inherit;
      font-size: var(--font-size-xl);
      line-height: 1;
      color: var(--text-muted);
      background: none;
      border: 0;
      border-radius: var(--radius-md);
      cursor: pointer;
      flex-shrink: 0;
    }
    .modal__x:hover { color: var(--text); background-color: var(--surface-inset); }
    .modal__body { min-width: 0; }
    .modal__foot {
      display: flex;
      justify-content: flex-end;
      gap: var(--space-3);
      flex-wrap: wrap;
    }
    /* On a phone the actions go full width and stack, so the primary is not a
       small target in a corner. */
    @media (max-width: 480px) {
      .modal__foot { flex-direction: column-reverse; }
      .modal__foot ::ng-deep .btn { width: 100%; }
    }
  `,
})
export class UiModal {
  /** Not a signal: it never changes, and a computed would only add ceremony. */
  protected readonly closeIcon = X;

  readonly heading = input.required<string>();
  readonly description = input<string | null>(null);

  /** Whether the dialog is showing. Owned by the caller. */
  readonly open = input(false);

  /**
   * Closed by Escape, the backdrop, or the close button.
   *
   * Named for what happened rather than what to do about it: the caller decides
   * whether dismissing means cancelling.
   */
  readonly dismissed = output<void>();

  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly dialog = viewChild<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    effect(() => {
      const element = this.dialog()?.nativeElement;

      if (!element) return;

      // showModal() throws if already open, and close() on a closed dialog is
      // a no-op that still fires nothing — so both are guarded on the real
      // state rather than on the input.
      if (this.open() && !element.open) {
        element.showModal();
      } else if (!this.open() && element.open) {
        element.close();
      }
    });
  }

  close(): void {
    this.dialog()?.nativeElement.close();
  }

  /**
   * A click on the backdrop, not on the panel.
   *
   * The backdrop is painted by the dialog element itself, so a click that
   * lands on the dialog rather than on anything inside it came from outside
   * the panel.
   */
  onBackdrop(event: MouseEvent): void {
    if (event.target === this.dialog()?.nativeElement) {
      this.close();
    }
  }
}
