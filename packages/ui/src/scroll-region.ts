import { DestroyRef, Directive, ElementRef, afterNextRender, inject, input, signal } from '@angular/core';

/**
 * The frame of something wider than the page: a table, a line of code.
 *
 * It scrolls sideways and draws no scrollbar (scroll-x, styles/scroll.css).
 * What the bar gave a mouse and a finger they still have — drag, swipe, Shift
 * and the wheel — but a keyboard reached the columns past the edge only by
 * tabbing to a link in one of them, and a column of figures has none. So the
 * frame takes a tab stop, and the arrow keys scroll it once it has focus. It
 * is a named region so that a screen reader says what the stop is rather than
 * announcing an unnamed box.
 *
 * Only while there is something past the edge. A stop on a table that fits
 * moves nothing and is one more press between somebody and the next control,
 * and at a desk most of the console's tables fit. Measured after the first
 * render and again whenever the frame, or what is in it, changes.
 *
 *   <div uiScrollRegion="Orders"><table>…</table></div>
 */
@Directive({
  selector: '[uiScrollRegion]',
  host: {
    class: 'scroll-x',
    role: 'region',
    '[attr.aria-label]': 'uiScrollRegion()',
    '[attr.tabindex]': 'scrolls() ? 0 : null',
  },
})
export class UiScrollRegion {
  /** What the frame holds, as a screen reader should name it: usually the table's caption. */
  readonly uiScrollRegion = input.required<string>();

  /** Whether any of it is past an edge, and so whether it takes a tab stop. */
  readonly scrolls = signal(false);

  private readonly element: HTMLElement = inject(ElementRef).nativeElement;

  constructor() {
    const destroyed = inject(DestroyRef);

    afterNextRender(() => {
      // With no way to hear about a change of size, it is reachable: a stop
      // too many costs a key press, one too few costs the columns.
      if (typeof ResizeObserver === 'undefined') {
        this.scrolls.set(true);
        return;
      }

      // The frame, for the window; the table in it, for rows arriving and a
      // column growing, which make the table wider and leave the frame alone.
      const resized = new ResizeObserver(() => this.measure());
      const observe = () => {
        resized.disconnect();
        resized.observe(this.element);
        for (const child of Array.from(this.element.children)) resized.observe(child);
      };

      // And what is in it, for a change that moves nothing's size: a line of
      // code rewritten in a <pre> as long as the one before it, or a table
      // swapped for another, which the observer above has never seen.
      const changed = new MutationObserver((records) => {
        if (records.some((record) => record.type === 'childList' && record.target === this.element)) observe();
        this.measure();
      });

      observe();
      changed.observe(this.element, { childList: true, characterData: true, subtree: true });
      this.measure();

      destroyed.onDestroy(() => {
        resized.disconnect();
        changed.disconnect();
      });
    });
  }

  measure(): void {
    this.scrolls.set(this.element.scrollWidth > this.element.clientWidth + 1);
  }
}
