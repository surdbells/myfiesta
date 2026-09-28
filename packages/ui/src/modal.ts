import {
  Component,
  ElementRef,
  Injector,
  PLATFORM_ID,
  afterNextRender,
  booleanAttribute,
  computed,
  effect,
  inject,
  input,
  output,
  viewChild,
} from '@angular/core';
import { DOCUMENT, isPlatformBrowser } from '@angular/common';
import { X } from 'lucide-angular';
import { UiIcon } from './icon';

let sequence = 0;

/** What Tab would land on, in the order it would land on them. */
const FOCUSABLE =
  'a[href], button, input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])';

/**
 * Whether a control is really there to be tabbed to.
 *
 * `checkVisibility` where the engine has it; otherwise everything counts. Not
 * `offsetParent`, which is null for everything in a test DOM with no layout
 * and would turn "skip the hidden ones" into "skip them all".
 */
function visible(element: HTMLElement): boolean {
  return typeof element.checkVisibility === 'function' ? element.checkVisibility() : true;
}

/**
 * A dialog, built on the native `<dialog>`.
 *
 * Native rather than a positioned div, because `showModal()` gives four things
 * for free that hand-rolled dialogs almost always get wrong: the top layer, so
 * it cannot be trapped behind a stacking context; the rest of the page made
 * inert; Escape; and focus handed back to whatever opened it.
 *
 * What it does not give is added here, each for a reason:
 *
 * - closing on a backdrop click, by checking whether the click landed on the
 *   dialog element itself — the backdrop is part of the dialog's own box, so a
 *   click on the panel inside never matches;
 * - Tab wrapping from the last control to the first. The browser lets focus
 *   out into its own toolbar and back, which is correct and still reads as
 *   "focus escaped" to somebody on a keyboard;
 * - a name and a description a screen reader announces on opening. Without
 *   `aria-labelledby` the dialog was announced as "dialog" and nothing else;
 * - a dialog that can refuse to be dismissed (`dismissible`), for the second a
 *   request it started is still running. Escape halfway through a refund would
 *   otherwise close the only place its answer can be shown.
 */
@Component({
  selector: 'ui-modal',
  imports: [UiIcon],
  host: {
    // `<ui-modal role="alertdialog">` sets the input and, being a plain
    // attribute, also lands on this wrapper — a second alertdialog with no
    // name, round the real one. The role belongs on the dialog alone.
    '[attr.role]': 'null',
  },
  template: `
    <dialog
      #dialog
      class="modal"
      [attr.role]="role() === 'alertdialog' ? 'alertdialog' : null"
      [attr.aria-labelledby]="headingId"
      [attr.aria-describedby]="describedByIds()"
      (close)="onClosed()"
      (cancel)="onCancel($event)"
      (keydown)="onKeydown($event)"
      (click)="onBackdrop($event)"
    >
      <div class="modal__panel">
        <header class="modal__head">
          <h2 class="modal__title" [id]="headingId">{{ heading() }}</h2>
          @if (closable()) {
            <button
              type="button"
              class="modal__x"
              aria-label="Close"
              [disabled]="!dismissible()"
              (click)="close()"
            >
              <ui-icon [icon]="closeIcon" />
            </button>
          }
        </header>

        @if (description()) {
          <p class="modal__desc" [id]="descriptionId">{{ description() }}</p>
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
      max-height: calc(100dvh - 2 * var(--space-5));
      width: 100%;
      overflow-y: auto;
      overscroll-behavior: contain;
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
    /* Arrives rather than appears — and only for readers who have not asked
       the system for less movement. */
    @media (prefers-reduced-motion: no-preference) {
      .modal[open] { animation: modal-in var(--motion-base) var(--motion-ease); }
      .modal[open]::backdrop { animation: modal-scrim var(--motion-base) var(--motion-ease); }
    }
    @keyframes modal-in {
      from { opacity: 0; transform: translateY(var(--space-2)); }
    }
    @keyframes modal-scrim {
      from { opacity: 0; }
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
      overflow-wrap: anywhere;
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
    .modal__x:hover:not(:disabled) { color: var(--text); background-color: var(--surface-inset); }
    .modal__x:disabled { opacity: 0.55; cursor: not-allowed; }
    .modal__body { min-width: 0; }
    .modal__foot {
      display: flex;
      justify-content: flex-end;
      gap: var(--space-3);
      flex-wrap: wrap;
    }
    .modal__foot:empty { display: none; }
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
   * `alertdialog` for a question that interrupts — "Refund this order?" —
   * which a screen reader announces with more urgency than a form in a box.
   */
  readonly role = input<'dialog' | 'alertdialog'>('dialog');

  /**
   * Whether Escape, the backdrop and the close button may close it.
   *
   * Off only while something the dialog started is still running: the
   * answer, good or bad, has to land somewhere the person is still looking.
   */
  readonly dismissible = input(true, { transform: booleanAttribute });

  /** The corner close button. A confirmation leaves it off: Cancel says the same thing in words. */
  readonly closable = input(true, { transform: booleanAttribute });

  /** More of the dialog to read out on opening, by id — a list of consequences, say. */
  readonly extraDescription = input<string | null>(null, { alias: 'describedBy' });

  /**
   * Closed by Escape, the backdrop, or the close button.
   *
   * Named for what happened rather than what to do about it: the caller decides
   * whether dismissing means cancelling.
   */
  readonly dismissed = output<void>();

  private readonly uid = ++sequence;
  protected readonly headingId = `ui-modal-${this.uid}-title`;
  protected readonly descriptionId = `ui-modal-${this.uid}-desc`;

  protected readonly describedByIds = computed(() => {
    const ids = [this.description() ? this.descriptionId : null, this.extraDescription()].filter(Boolean);

    return ids.length ? ids.join(' ') : null;
  });

  private readonly document = inject(DOCUMENT);
  private readonly injector = inject(Injector);
  private readonly browser = isPlatformBrowser(inject(PLATFORM_ID));
  private readonly dialog = viewChild<ElementRef<HTMLDialogElement>>('dialog');

  /** Open, and not yet reported closed — so a close is reported once, however it happened. */
  private showing = false;

  /** What had focus when it opened, to be given back. */
  private opener: HTMLElement | null = null;

  constructor() {
    effect(() => {
      const element = this.dialog()?.nativeElement;

      // Never on the server: there is nobody there to ask.
      if (!element || !this.browser) return;

      // Guarded on the element's real state rather than on the input:
      // showModal() throws if it is already open. The attribute rather than
      // `.open`, which a test DOM without dialogs does not reflect.
      const isOpen = element.hasAttribute('open');

      if (this.open() && !isOpen) {
        this.show(element);
      } else if (!this.open() && isOpen) {
        this.shut(element);
        this.giveFocusBack();
      }
    });
  }

  close(): void {
    const element = this.dialog()?.nativeElement;

    if (!element?.hasAttribute('open')) return;

    this.shut(element);
    this.giveFocusBack();

    // Said here as well as from the element's close event, which a browser
    // fires a task later and a DOM without dialogs never fires at all.
    this.announceDismissed();
  }

  /** Reported once per opening, whichever of the ways out it took. */
  protected announceDismissed(): void {
    if (!this.showing) return;

    this.showing = false;
    this.dismissed.emit();
  }

  /**
   * The element closed — by this component, or by the browser on its own.
   *
   * Chrome lets a second back gesture through without asking, however the
   * first was answered. While the dialog is refusing to close it goes straight
   * back up, or the answer to a refund would land in a dialog nobody can see.
   */
  protected onClosed(): void {
    const element = this.dialog()?.nativeElement;

    // Up again already: a close reported late, about an opening since.
    if (!element || element.hasAttribute('open')) return;

    if (this.showing && this.open() && !this.dismissible()) {
      if (typeof element.showModal === 'function') element.showModal();
      else element.setAttribute('open', '');

      return;
    }

    this.announceDismissed();
  }

  /**
   * A click on the backdrop, not on the panel.
   *
   * The backdrop is painted by the dialog element itself, so a click that
   * lands on the dialog rather than on anything inside it came from outside
   * the panel.
   */
  onBackdrop(event: MouseEvent): void {
    if (event.target === this.dialog()?.nativeElement && this.dismissible()) {
      this.close();
    }
  }

  /**
   * The browser's own request to close — Escape in some engines, the back
   * gesture on Android. Taken over rather than allowed, so it closes the same
   * way as every other route out, and not at all while that is refused.
   */
  protected onCancel(event: Event): void {
    event.preventDefault();

    if (this.dismissible()) this.close();
  }

  protected onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      event.preventDefault();
      if (this.dismissible()) this.close();

      return;
    }

    if (event.key === 'Tab') this.keepFocusIn(event);
  }

  private show(element: HTMLDialogElement): void {
    const active = this.document.activeElement;
    this.opener = active instanceof HTMLElement ? active : null;

    if (typeof element.showModal === 'function') element.showModal();
    else element.setAttribute('open', '');

    this.showing = true;

    // Where the caller said to start: Cancel, on a question that destroys
    // something. The browser's own choice is the first control, which for a
    // confirmation is too often the one that does the damage. After the
    // render, so a field that appears with the dialog is there to be found.
    afterNextRender(
      () => {
        if (element.hasAttribute('open')) element.querySelector<HTMLElement>('[data-autofocus]')?.focus();
      },
      { injector: this.injector },
    );
  }

  private shut(element: HTMLDialogElement): void {
    if (typeof element.close === 'function') element.close();
    else element.removeAttribute('open');
  }

  /**
   * Back where the person was.
   *
   * The browser does this for a native dialog; a DOM without one does not,
   * and a dialog that leaves focus on the page's body puts a keyboard user
   * back at the top of a screen they had worked their way down.
   */
  private giveFocusBack(): void {
    const opener = this.opener;
    this.opener = null;

    const active = this.document.activeElement;
    const lost = !active || active === this.document.body || !!this.dialog()?.nativeElement.contains(active);

    if (lost && opener?.isConnected) opener.focus();
  }

  /** Tab from the last control goes to the first, and Shift+Tab the other way. */
  private keepFocusIn(event: KeyboardEvent): void {
    const dialog = this.dialog()?.nativeElement;

    if (!dialog) return;

    const focusable = [...dialog.querySelectorAll<HTMLElement>(FOCUSABLE)].filter(
      (element) => !element.hasAttribute('disabled') && visible(element),
    );

    if (focusable.length === 0) {
      event.preventDefault();

      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = this.document.activeElement;

    if (!event.shiftKey && (active === last || !dialog.contains(active))) {
      event.preventDefault();
      first.focus();
    } else if (event.shiftKey && (active === first || !dialog.contains(active))) {
      event.preventDefault();
      last.focus();
    }
  }
}
