import { Component, input } from '@angular/core';

/**
 * The shape of content that has not arrived.
 *
 * Sized to the thing it stands in for, so the page does not jump when the real
 * content lands. A spinner in the middle of an empty page tells somebody to
 * wait; a skeleton tells them what they are waiting for and holds its place.
 */
@Component({
  selector: 'ui-skeleton',
  template: `
    <span
      class="sk"
      [style.width]="width()"
      [style.height]="height()"
      [class.sk--round]="round()"
      aria-hidden="true"
    ></span>
  `,
  styles: `
    :host { display: block; }
    .sk {
      display: block;
      background-color: var(--surface-inset);
      border-radius: var(--radius-sm);
      /* A slow sweep rather than a pulse. A pulsing block in the corner of the
         eye reads as an error indicator. */
      background-image: linear-gradient(
        90deg,
        transparent,
        color-mix(in srgb, var(--text) 6%, transparent),
        transparent
      );
      background-size: 200% 100%;
      animation: sweep 1.4s ease-in-out infinite;
    }
    .sk--round { border-radius: var(--radius-full); }
    @keyframes sweep {
      from { background-position: 200% 0; }
      to { background-position: -200% 0; }
    }
    /* The global reduced-motion rule stops the sweep; the block still holds
       its space, which is the part that matters. */
  `,
})
export class UiSkeleton {
  readonly width = input('100%');
  readonly height = input('1rem');
  readonly round = input(false);
}
