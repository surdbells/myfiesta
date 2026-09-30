import { DestroyRef, Directive, ElementRef, afterNextRender, inject, signal } from '@angular/core';

/**
 * Whether a row that scrolls sideways has anything off-screen to scroll to.
 *
 * A shelf on the front page scrolls once it holds more nights than fill a
 * row, and the arrows beside its heading move it. In a 1240px column that was
 * always true; in a frame as wide as the window it is not — five nights sit
 * side by side on a 1920px screen, seven on a 2560px one — and the arrows
 * moved nothing. How many fit is the window's business, so it is measured.
 *
 * Until it is, the answer is yes: the server cannot measure, and the arrows
 * are what every screen narrower than a large monitor needs.
 */
@Directive({
  selector: '[appOverflows]',
  exportAs: 'overflows',
})
export class Overflows {
  private readonly element: HTMLElement = inject(ElementRef).nativeElement;

  readonly overflows = signal(true);

  constructor() {
    const destroyed = inject(DestroyRef);

    afterNextRender(() => {
      if (typeof ResizeObserver === 'undefined') return;

      const measure = () => this.overflows.set(this.element.scrollWidth > this.element.clientWidth + 1);

      // Once now: the observer's first word waits for a frame to be drawn,
      // which a tab opened in the background may not draw for a while.
      measure();

      const resized = new ResizeObserver(measure);
      resized.observe(this.element);
      destroyed.onDestroy(() => resized.disconnect());
    });
  }
}
