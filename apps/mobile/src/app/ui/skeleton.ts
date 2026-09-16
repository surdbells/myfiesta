import { Component, input } from '@angular/core';

/**
 * The shape of what is loading.
 *
 * A spinner says "wait"; this says "a list of cards is coming", which on a
 * connection outside a venue is the difference between waiting and leaving.
 */
@Component({
  selector: 'mf-skeleton',
  template: '',
  host: { '[style.height]': 'height()', '[style.width]': 'width()' },
  styles: `
    :host {
      display: block;
      border-radius: var(--radius-md);
      background: linear-gradient(
        90deg,
        var(--surface-inset) 25%,
        var(--surface-hover) 37%,
        var(--surface-inset) 63%
      );
      background-size: 400% 100%;
      animation: mf-shimmer 1.4s ease infinite;
    }

    @keyframes mf-shimmer {
      from {
        background-position: 100% 50%;
      }
      to {
        background-position: 0 50%;
      }
    }
  `,
})
export class MfSkeleton {
  readonly height = input('1rem');
  readonly width = input('100%');
}
