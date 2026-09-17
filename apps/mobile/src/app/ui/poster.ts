import { Component, computed, input } from '@angular/core';

/**
 * An event's picture, with something to look at when there is none.
 *
 * Imported events often arrive without one, and a grey box with a broken-image
 * glyph reads as an app that failed rather than an event that has no poster
 * yet. The fallback is the event's own initial on a tinted ground, which at
 * least looks deliberate in a list.
 */
@Component({
  selector: 'mf-poster',
  template: `
    @if (url()) {
      <img [src]="url()" [alt]="''" loading="lazy" decoding="async" />
    } @else {
      <span class="initial" aria-hidden="true">{{ initial() }}</span>
    }
  `,
  host: { '[class]': 'shape()' },
  styles: `
    :host {
      display: block;
      position: relative;
      overflow: hidden;
      border-radius: var(--radius-lg);
      background: linear-gradient(140deg, var(--primary-soft), var(--surface-inset));
    }

    :host(.wide) {
      aspect-ratio: 16 / 9;
    }

    :host(.tall) {
      aspect-ratio: 3 / 4;
    }

    :host(.square) {
      aspect-ratio: 1;
    }

    img {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .initial {
      display: grid;
      place-items: center;
      width: 100%;
      height: 100%;
      font-size: var(--font-size-3xl);
      font-weight: var(--font-weight-bold);
      color: var(--primary-text);
      opacity: 0.55;
    }
  `,
})
export class MfPoster {
  readonly url = input<string | null>(null);
  readonly title = input('');
  readonly shape = input<'wide' | 'tall' | 'square'>('wide');

  protected readonly initial = computed(() => this.title().trim().charAt(0).toUpperCase() || '·');
}
