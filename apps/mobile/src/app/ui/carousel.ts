import {
  Component,
  ElementRef,
  contentChildren,
  effect,
  input,
  signal,
  viewChild,
} from '@angular/core';

/**
 * A row you swipe: the app's carousel.
 *
 * Scroll snapping rather than a slider library. The browser already does
 * momentum, rubber-banding and the snap — all the parts a JavaScript carousel
 * re-implements badly on a phone — so this adds only what the platform does
 * not: the dots, and a peek of the next card so the row reads as scrollable
 * without an arrow nobody can tap.
 *
 * Dots are a position readout, not a control. They are hidden from screen
 * readers, which already have a scrollable list and do not need decoration.
 */
@Component({
  selector: 'mf-carousel',
  template: `
    <div #track class="track" (scroll)="onScroll()" [attr.aria-label]="ariaLabel()" role="group">
      <ng-content />
    </div>

    @if (count() > 1) {
      <div class="dots" aria-hidden="true">
        @for (dot of dots(); track dot) {
          <span class="dot" [class.on]="dot === current()"></span>
        }
      </div>
    }
  `,
  styles: `
    :host {
      display: block;
    }

    .track {
      display: grid;
      grid-auto-flow: column;
      /* The peek: the next card's edge is what says "there is more". */
      grid-auto-columns: min(82%, 22rem);
      gap: var(--space-3);
      overflow-x: auto;
      scroll-snap-type: x mandatory;
      scrollbar-width: none;
      /* Full-bleed inside a padded screen, so a card can reach the edge. */
      margin: 0 calc(var(--space-5) * -1);
      padding: 0 var(--space-5);
      /* And snapped to the screen's gutter, not the track's edge. Without it
         the browser snapped the first card to the padding's outer edge: the
         row opened scrolled by the gutter, and the first card's text touched
         the side of the phone. */
      scroll-padding-inline: var(--space-5);
      overscroll-behavior-x: contain;
    }

    .track::-webkit-scrollbar {
      display: none;
    }

    .track ::ng-deep > * {
      scroll-snap-align: start;
    }

    .dots {
      display: flex;
      justify-content: center;
      gap: 6px;
      margin-top: var(--space-3);
    }

    .dot {
      width: 6px;
      height: 6px;
      border-radius: var(--radius-full);
      background: var(--border-strong);
      transition:
        width 160ms ease,
        background-color 160ms ease;
    }

    .dot.on {
      width: 18px;
      background: var(--primary);
    }
  `,
})
export class MfCarousel {
  readonly ariaLabel = input<string | null>(null);

  /** How many cards are in the track, so the dots can be drawn. */
  readonly count = input(0);

  private readonly track = viewChild.required<ElementRef<HTMLElement>>('track');

  protected readonly current = signal(0);

  protected readonly dots = () => Array.from({ length: this.count() }, (_, i) => i);

  protected onScroll(): void {
    const element = this.track().nativeElement;
    const card = element.firstElementChild as HTMLElement | null;

    if (!card) return;

    const stride = card.offsetWidth + 12;

    this.current.set(Math.round(element.scrollLeft / stride));
  }

  constructor() {
    // A changed list starts at the beginning; leaving it scrolled shows the
    // middle of a row somebody has not seen the start of.
    effect(() => {
      this.count();
      this.track().nativeElement.scrollTo({ left: 0 });
      this.current.set(0);
    });
  }
}
