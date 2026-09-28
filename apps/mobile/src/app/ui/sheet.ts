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
import { X } from 'lucide-angular';
import { SheetStack } from './sheet-stack';
import { Chrome } from '../core/chrome';
import { MfIconButton } from './icon-button';

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
export type SheetDismissal = 'backdrop' | 'drag' | 'escape' | 'back' | 'close';

/**
 * A bottom sheet: this app's dialog, its menu, its picker and its short form.
 *
 * Everything that would be a modal on a desktop arrives from the bottom here,
 * because that is the half of a phone a thumb reaches.
 *
 * **Floating** by default: inset from the edges and rounded all the way round,
 * sitting on its own shadow above the page rather than bolted to the bottom of
 * the glass. It reads as a thing that has arrived and will leave — which is
 * what a sheet is — and it clears the rounded corners of a modern phone
 * instead of being cropped by them. `docked` puts it back against the edge for
 * the rare sheet that needs every pixel of height.
 *
 * It follows the finger when dragged and springs back if the drag was not far
 * enough, and a quick flick down closes it however short the flick — a sheet
 * that only closes by button, or only on a long drag, is one people fight.
 *
 * `expandable` gives it two heights to rest at, the way a map or a long list
 * sheet does: it opens halfway, a drag or flick up takes it nearly to the top,
 * and down brings it back before a longer drag closes it. The grip becomes a
 * button that does the same, for anybody not dragging.
 *
 * Actions go in the footer (`sheetFooter`), pinned below the scrolling body so
 * Save is never scrolled out of reach, and the whole sheet rides above the
 * keyboard — the WebView is told not to resize for it.
 *
 * Content is out of the DOM until the sheet opens, so a screen with six sheets
 * on it is not six hidden subtrees a screen reader has to be told to ignore.
 */
@Component({
  selector: 'mf-sheet',
  imports: [MfIconButton],
  template: `
    @if (mounted()) {
      <div class="scrim" [class.showing]="showing()" (click)="dismiss('backdrop')" aria-hidden="true"></div>

      <section
        #panel
        class="panel"
        [class.docked]="docked()"
        [class.showing]="showing()"
        [class.expandable]="expandable()"
        [class.expanded]="expanded()"
        [style.transform]="dragging() ? 'translateY(' + dragged() + 'px)' : null"
        [style.transition]="dragging() ? 'none' : null"
        [style.--mf-sheet-lift.px]="chrome.keyboardHeight()"
        [attr.role]="role()"
        aria-modal="true"
        [attr.aria-label]="heading()"
        [attr.aria-describedby]="describedBy()"
        tabindex="-1"
        (pointerdown)="grab($event)"
        (pointermove)="drag($event)"
        (pointerup)="release()"
        (pointercancel)="release()"
      >
        @if (expandable()) {
          <button
            type="button"
            class="grip grip--button"
            [attr.aria-label]="expanded() ? 'Make smaller' : 'Make taller'"
            [attr.aria-expanded]="expanded()"
            (click)="expanded.set(!expanded())"
          >
            <span></span>
          </button>
        } @else {
          <div class="grip" aria-hidden="true"><span></span></div>
        }

        @if (heading() || closable()) {
          <header class="head">
            <div class="titles">
              @if (heading()) {
                <h2>{{ heading() }}</h2>
              }
              @if (subheading()) {
                <p class="sub">{{ subheading() }}</p>
              }
            </div>
            @if (closable()) {
              <button mfIconButton tone="tonal" size="sm" [icon]="closeIcon" label="Close" (click)="dismiss('close')"></button>
            }
          </header>
        }

        <div class="body">
          <ng-content />
        </div>

        <footer class="foot">
          <ng-content select="[sheetFooter]" />
        </footer>
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
      background: rgb(3 8 5 / 0.5);
      backdrop-filter: blur(2px);
      opacity: 0;
      transition: opacity 240ms ease;
    }

    .scrim.showing {
      opacity: 1;
    }

    .panel {
      position: fixed;
      z-index: 101;
      /* Floating: off every edge, rounded all round, above the keyboard. */
      left: var(--space-2);
      right: var(--space-2);
      bottom: calc(var(--mf-safe-bottom) + var(--space-2) + var(--mf-sheet-lift));
      display: grid;
      grid-template-rows: auto auto minmax(0, 1fr) auto;
      max-height: calc(100dvh - var(--mf-safe-top) - var(--mf-safe-bottom) - var(--mf-sheet-lift) - var(--space-8));
      overflow: hidden;
      background: var(--surface-raised);
      border-radius: var(--radius-overlay);
      box-shadow:
        inset 0 0 0 1px var(--border-subtle),
        var(--shadow-floating);
      transform: translateY(calc(100% + var(--mf-safe-bottom) + var(--space-4)));
      transition:
        transform 340ms var(--mf-ease-out),
        bottom 240ms var(--mf-ease-out);
      outline: none;
    }

    .panel.showing {
      transform: translateY(0);
    }

    /* Two resting heights: halfway, and as tall as the screen allows. */
    .panel.expandable {
      height: min(56dvh, calc(100dvh - var(--mf-safe-top) - var(--mf-safe-bottom) - var(--mf-sheet-lift) - var(--space-8)));
      transition:
        transform 340ms var(--mf-ease-out),
        bottom 240ms var(--mf-ease-out),
        height 320ms var(--mf-ease-out);
    }

    .panel.expandable.expanded {
      height: calc(100dvh - var(--mf-safe-top) - var(--mf-safe-bottom) - var(--mf-sheet-lift) - var(--space-8));
    }

    .panel.docked {
      left: 0;
      right: 0;
      bottom: var(--mf-sheet-lift);
      padding-bottom: var(--mf-safe-bottom);
      border-radius: var(--radius-overlay) var(--radius-overlay) 0 0;
    }

    .grip {
      display: grid;
      place-items: center;
      padding: var(--space-2) 0 var(--space-1);
      /* The chrome drags; the body below scrolls. */
      touch-action: none;
    }

    .grip--button {
      width: 100%;
      border: 0;
      background: transparent;
      cursor: grab;
    }

    .grip--button:focus-visible span {
      outline: 2px solid var(--primary);
      outline-offset: 4px;
    }

    .grip span {
      display: block;
      width: 2.25rem;
      height: 5px;
      border-radius: var(--radius-full);
      background: var(--border-strong);
      opacity: 0.8;
    }

    .head {
      display: flex;
      align-items: flex-start;
      gap: var(--space-3);
      padding: var(--space-1) var(--space-3) var(--space-3) var(--space-5);
      touch-action: none;
    }

    .titles {
      flex: 1;
      min-width: 0;
      display: grid;
      gap: var(--space-1);
      padding-top: var(--space-2);
    }

    h2 {
      font-size: var(--font-size-lg);
      line-height: 1.2;
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

    .foot:empty {
      display: none;
    }

    .foot {
      display: flex;
      gap: var(--space-3);
      padding: var(--space-3) var(--space-5) var(--space-4);
      border-top: 1px solid var(--border-subtle);
      background: var(--surface-raised);
    }

    .foot > ::ng-deep * {
      flex: 1;
    }
  `,
})
export class MfSheet {
  readonly open = input(false, { transform: booleanAttribute });
  readonly heading = input<string | null>(null);
  readonly subheading = input<string | null>(null);

  /** Against the bottom edge rather than floating, for a sheet that needs every pixel. */
  readonly docked = input(false, { transform: booleanAttribute });

  /** Rests halfway and expands to nearly full height: for long lists and pickers. */
  readonly expandable = input(false, { transform: booleanAttribute });

  /** At its taller resting height. Starts short every time it opens. */
  readonly expanded = signal(false);

  /** A close button in the header, for a sheet with no footer to leave by. */
  readonly closable = input(false, { transform: booleanAttribute });

  /**
   * `alertdialog` for a question that interrupts — "Refund this order?" —
   * which a screen reader announces with more urgency than a form.
   */
  readonly role = input<'dialog' | 'alertdialog'>('dialog');

  /** What to read out after the heading, by id: what the question is about. */
  readonly describedBy = input<string | null>(null);

  /**
   * Whether drag, scrim, back, Escape and the close button may close it.
   *
   * Off only while something the sheet started is still running: the answer,
   * good or bad, has to land somewhere the person is still looking.
   */
  readonly dismissible = input(true, { transform: booleanAttribute });

  readonly closed = output<SheetDismissal>();

  protected readonly closeIcon = X;
  protected readonly chrome = inject(Chrome);

  private readonly document = inject(DOCUMENT);
  private readonly stack = inject(SheetStack);
  private readonly panel = viewChild<ElementRef<HTMLElement>>('panel');

  /** What had focus when this opened. */
  private opener: HTMLElement | null = null;

  /** In the DOM. Stays true for the length of the closing animation. */
  protected readonly mounted = signal(false);

  /** Raised. Separate from mounted, or the sheet would appear already open. */
  protected readonly showing = signal(false);

  /** The unmount waiting on the closing slide, called off if the sheet is opened again first. */
  private unmounting: ReturnType<typeof setTimeout> | null = null;

  protected readonly dragging = signal(false);
  protected readonly dragged = signal(0);

  /** The last few finger positions, for how fast the drag was going when it let go. */
  private trail: { y: number; t: number }[] = [];
  private startY = 0;
  private readonly closer = () => this.dismiss('back');

  constructor() {
    effect(() => (this.open() ? this.show() : this.hide()));
  }

  private show(): void {
    // Opened again while it was still sliding away — the door asking about the
    // next table's ticket a moment after the last was answered. The unmount is
    // called off and the sheet comes back up from where it is. Left to run,
    // it took the sheet off the screen under an owner that had just asked for
    // it, and brought it back only once it had gone.
    if (this.unmounting !== null) {
      clearTimeout(this.unmounting);
      this.unmounting = null;
    } else if (this.mounted()) {
      return;
    }

    // Where focus was, so it can be given back. A sheet that closes and leaves
    // focus on the page behind puts a keyboard or switch user back at the top
    // of a screen they had already worked their way down.
    const active = this.document.activeElement;
    this.opener = active instanceof HTMLElement ? active : null;

    this.mounted.set(true);
    this.dragged.set(0);
    this.expanded.set(false);
    this.stack.push(this.closer);

    // The page behind must not scroll under the sheet; on iOS it must not
    // rubber-band either, which is what makes a sheet feel like a web page.
    this.document.body.style.overflow = 'hidden';

    // One frame later, or the panel appears already raised and nothing slides.
    // Not if it was closed again within that frame.
    requestAnimationFrame(() => {
      if (!this.open()) return;

      this.showing.set(true);
      this.panel()?.nativeElement.focus();
    });
  }

  private hide(): void {
    if (!this.mounted() || this.unmounting !== null) return;

    this.showing.set(false);
    this.stack.remove(this.closer);
    this.document.body.style.overflow = '';

    // Back where it came from, if that is still on the page.
    if (this.opener?.isConnected) this.opener.focus();

    this.opener = null;

    // Kept mounted until it has slid away; unmounting first is a sheet that
    // vanishes rather than closes.
    this.unmounting = setTimeout(() => {
      this.unmounting = null;
      this.mounted.set(false);
    }, 340);
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

    if (!this.dismissible()) {
      // Back has already taken this sheet off the stack on its way here. It
      // is still open, so it goes back on — or the next press of back would
      // leave the screen with the sheet still up.
      if (reason === 'back') this.stack.push(this.closer);

      return;
    }

    this.closed.emit(reason);
  }

  // --- dragging ------------------------------------------------------------

  protected grab(event: PointerEvent): void {
    // A sheet that will not close does not follow the finger as if it might.
    if (!this.dismissible()) return;

    const target = event.target as HTMLElement;

    // Dragging starts on the sheet's chrome, or at the top of its content.
    // Starting it inside a scrolled list would steal the scroll; starting it
    // on a control would steal the tap.
    if (target.closest('input, textarea, select, a, [role="option"]')) return;
    if (target.closest('button') && !target.closest('.grip--button')) return;
    if (target.closest('.body') && !this.atTop()) return;

    this.startY = event.clientY;
    this.trail = [{ y: event.clientY, t: event.timeStamp }];
    this.dragging.set(true);
  }

  protected drag(event: PointerEvent): void {
    if (!this.dragging()) return;

    const delta = event.clientY - this.startY;

    this.trail.push({ y: event.clientY, t: event.timeStamp });
    if (this.trail.length > 5) this.trail.shift();

    // Upward drags resist rather than lift the sheet off its place — less so
    // when there is a taller height to go to, so the finger feels the pull.
    const resistance = this.expandable() && !this.expanded() ? 2.5 : 6;
    this.dragged.set(delta > 0 ? delta : delta / resistance);
  }

  protected release(): void {
    if (!this.dragging()) return;

    const travelled = this.dragged();
    this.dragging.set(false);
    this.dragged.set(0);

    // How fast it was moving as it let go, in px per ms.
    const first = this.trail[0];
    const last = this.trail[this.trail.length - 1];
    const velocity = first && last && last.t > first.t ? (last.y - first.y) / (last.t - first.t) : 0;

    this.trail = [];

    // Zero is a panel not laid out yet: no height to measure against.
    const height = this.panel()?.nativeElement.offsetHeight || 400;

    if (this.expandable()) {
      // Up, by a little or a flick: to the taller height.
      if (!this.expanded() && (travelled < -24 || velocity < -0.55)) {
        this.expanded.set(true);
        return;
      }

      // Down from the taller height: back to halfway, unless it was most of
      // the way down, which is a close.
      if (this.expanded() && (travelled > 48 || velocity > 0.55) && travelled < height / 2) {
        this.expanded.set(false);
        return;
      }
    }

    // A third of the way down closes it; so does a flick, however short.
    if (travelled > height / 3 || (velocity > 0.55 && travelled > 24)) this.dismiss('drag');
  }

  private atTop(): boolean {
    const body = this.panel()?.nativeElement.querySelector('.body');

    return !body || body.scrollTop <= 0;
  }
}
