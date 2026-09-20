import {
  Component,
  ElementRef,
  booleanAttribute,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { DOCUMENT } from '@angular/common';
import { SheetStack } from './sheet-stack';

/**
 * Whether a control is really there to be tabbed to.
 *
 * `checkVisibility` where the engine has it; otherwise everything counts.
 * Not `offsetParent`: it is null for anything positioned fixed, and null for
 * everything at all in a test runner with no layout — which turns a filter
 * meant to skip a hidden option into one that skips every control in the
 * sheet.
 */
function visible(element: HTMLElement): boolean {
  return typeof element.checkVisibility === 'function' ? element.checkVisibility() : true;
}

/** What Tab would land on, in the order it would land on them. */
const FOCUSABLE = 'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])';

/** Why a sheet closed. */
export type SheetDismissal = 'backdrop' | 'drag' | 'escape' | 'back';

/**
 * A bottom sheet: this app's dialog, its menu and its picker.
 *
 * Everything that would be a modal on a desktop arrives from the bottom here,
 * because that is the half of a phone a thumb reaches. It can be dragged down
 * to dismiss, follows the finger while dragging, and springs back when the
 * drag was not far enough — a sheet that only closes by button is one people
 * fight with.
 *
 * Not ion-modal: its sheet mode brings iOS card chrome and a backdrop tuned to
 * Ionic's palette, and neither takes our tokens without a fight.
 *
 * Content is out of the DOM until the sheet opens, so a screen with six sheets
 * on it is not six hidden subtrees a screen reader has to be told to ignore.
 */
@Component({
  selector: 'mf-sheet',
  template: `
    @if (mounted()) {
      <div class="scrim" [class.showing]="showing()" (click)="dismiss('backdrop')" aria-hidden="true"></div>

      <section
        #panel
        class="panel"
        [class.showing]="showing()"
        [style.transform]="dragging() ? 'translateY(' + dragged() + 'px)' : null"
        [style.transition]="dragging() ? 'none' : null"
        role="dialog"
        aria-modal="true"
        [attr.aria-label]="heading()"
        tabindex="-1"
        (pointerdown)="grab($event)"
        (pointermove)="drag($event)"
        (pointerup)="release()"
        (pointercancel)="release()"
      >
        <div class="grip" aria-hidden="true"><span></span></div>

        @if (heading()) {
          <header class="head">
            <h2>{{ heading() }}</h2>
            @if (subheading()) {
              <p class="sub">{{ subheading() }}</p>
            }
          </header>
        }

        <div class="body">
          <ng-content />
        </div>
      </section>
    }
  `,
  host: {
    '(document:keydown.escape)': 'dismiss("escape")',
    '(document:keydown.tab)': 'keepFocusIn($any($event))',
    '(document:keydown.shift.tab)': 'keepFocusIn($any($event))',
  },
  styles: `
    :host {
      display: contents;
    }

    .scrim {
      position: fixed;
      inset: 0;
      z-index: 100;
      background: rgb(3 8 5 / 0.55);
      opacity: 0;
      transition: opacity 220ms ease;
    }

    .scrim.showing {
      opacity: 1;
    }

    .panel {
      position: fixed;
      z-index: 101;
      left: 0;
      right: 0;
      bottom: 0;
      display: grid;
      grid-template-rows: auto auto minmax(0, 1fr);
      max-height: min(92dvh, 46rem);
      padding-bottom: var(--mf-safe-bottom);
      background: var(--surface-raised);
      border-radius: var(--radius-xl) var(--radius-xl) 0 0;
      box-shadow: var(--shadow-floating);
      transform: translateY(100%);
      transition: transform 260ms cubic-bezier(0.32, 0.72, 0, 1);
      outline: none;
    }

    .panel.showing {
      transform: translateY(0);
    }

    .grip {
      display: grid;
      place-items: center;
      padding: var(--space-3) 0 var(--space-2);
      /* The chrome drags; the body below scrolls. */
      touch-action: none;
    }

    .grip span {
      display: block;
      width: 2.5rem;
      height: 4px;
      border-radius: var(--radius-full);
      background: var(--border-strong);
    }

    .head {
      display: grid;
      gap: var(--space-1);
      padding: 0 var(--space-5) var(--space-3);
      touch-action: none;
    }

    .sub {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .body {
      overflow-y: auto;
      overscroll-behavior: contain;
      padding: 0 var(--space-5) var(--space-5);
    }
  `,
})
export class MfSheet {
  readonly open = input(false, { transform: booleanAttribute });
  readonly heading = input<string | null>(null);
  readonly subheading = input<string | null>(null);

  readonly closed = output<SheetDismissal>();

  private readonly document = inject(DOCUMENT);
  private readonly stack = inject(SheetStack);
  private readonly panel = viewChild<ElementRef<HTMLElement>>('panel');

  /** What had focus when this opened. */
  private opener: HTMLElement | null = null;

  /** In the DOM. Stays true for the length of the closing animation. */
  protected readonly mounted = signal(false);

  /** Raised. Separate from mounted, or the sheet would appear already open. */
  protected readonly showing = signal(false);

  protected readonly dragging = signal(false);
  protected readonly dragged = signal(0);

  private startY = 0;
  private readonly closer = () => this.dismiss('back');

  constructor() {
    effect(() => (this.open() ? this.show() : this.hide()));
  }

  private show(): void {
    if (this.mounted()) return;

    // Where focus was, so it can be given back. A sheet that closes and leaves
    // focus on the page behind puts a keyboard or switch user back at the top
    // of a screen they had already worked their way down.
    const active = this.document.activeElement;
    this.opener = active instanceof HTMLElement ? active : null;

    this.mounted.set(true);
    this.dragged.set(0);
    this.stack.push(this.closer);

    // The page behind must not scroll under the sheet; on iOS it must not
    // rubber-band either, which is what makes a sheet feel like a web page.
    this.document.body.style.overflow = 'hidden';

    // One frame later, or the panel appears already raised and nothing slides.
    requestAnimationFrame(() => {
      this.showing.set(true);
      this.panel()?.nativeElement.focus();
    });
  }

  private hide(): void {
    if (!this.mounted()) return;

    this.showing.set(false);
    this.stack.remove(this.closer);
    this.document.body.style.overflow = '';

    // Back where it came from, if that is still on the page.
    if (this.opener?.isConnected) this.opener.focus();

    this.opener = null;

    // Kept mounted until it has slid away; unmounting first is a sheet that
    // vanishes rather than closes.
    setTimeout(() => this.mounted.set(false), 260);
  }

  /**
   * Keep Tab inside the sheet.
   *
   * `aria-modal` tells a screen reader the rest of the page is not there. It
   * tells a keyboard nothing at all — without this, two presses of Tab walked
   * out of the sheet and into the page behind it, which is a claim of
   * modality the sheet was not keeping.
   */
  protected keepFocusIn(event: KeyboardEvent): void {
    const panel = this.panel()?.nativeElement;

    if (!this.mounted() || !panel) return;

    const focusable = [...panel.querySelectorAll<HTMLElement>(FOCUSABLE)].filter(
      (element) => !element.hasAttribute('disabled') && visible(element),
    );

    // Nothing to land on: hold the panel itself rather than letting Tab out.
    if (focusable.length === 0) {
      event.preventDefault();
      panel.focus();

      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = this.document.activeElement;

    // Wrapping is the whole job — including from the panel itself, which is
    // where focus starts.
    if (!event.shiftKey && (active === last || !panel.contains(active))) {
      event.preventDefault();
      first.focus();
    } else if (event.shiftKey && (active === first || active === panel || !panel.contains(active))) {
      event.preventDefault();
      last.focus();
    }
  }

  /**
   * Ask to close.
   *
   * The sheet does not close itself: whoever opened it owns the flag, and a
   * sheet that hides while its owner still believes it is open is the bug that
   * makes a picker impossible to reopen.
   */
  dismiss(reason: SheetDismissal): void {
    if (!this.mounted()) return;

    this.closed.emit(reason);
  }

  // --- dragging ------------------------------------------------------------

  protected grab(event: PointerEvent): void {
    const target = event.target as HTMLElement;

    // Dragging starts on the sheet's chrome, or at the top of its content.
    // Starting it inside a scrolled list would steal the scroll.
    if (target.closest('.body') && !this.atTop()) return;

    this.startY = event.clientY;
    this.dragging.set(true);
  }

  protected drag(event: PointerEvent): void {
    if (!this.dragging()) return;

    const delta = event.clientY - this.startY;

    // Upward drags resist rather than lift the sheet off the bottom edge.
    this.dragged.set(delta > 0 ? delta : delta / 6);
  }

  protected release(): void {
    if (!this.dragging()) return;

    const travelled = this.dragged();
    this.dragging.set(false);
    this.dragged.set(0);

    // A third of the way down closes it.
    const height = this.panel()?.nativeElement.offsetHeight ?? 400;

    if (travelled > height / 3) this.dismiss('drag');
  }

  private atTop(): boolean {
    const body = this.panel()?.nativeElement.querySelector('.body');

    return !body || body.scrollTop <= 0;
  }
}
