import { Component, input } from '@angular/core';

/**
 * Waiting.
 *
 * Carries its own label rather than leaving a bare animation on screen: a
 * spinner with no text is silent to a screen reader, so somebody who cannot see
 * it experiences the page as having simply stopped.
 */
@Component({
  selector: 'ui-spinner',
  template: `
    <p class="loading" role="status">
      <span class="dot" aria-hidden="true"></span>
      {{ label() }}
    </p>
  `,
  styles: `
    .loading {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }
    .dot {
      width: 14px;
      height: 14px;
      border: 2px solid var(--border-strong);
      border-top-color: var(--primary);
      border-radius: var(--radius-full);
      animation: spin 700ms linear infinite;
    }
    @keyframes spin {
      to { transform: rotate(360deg); }
    }
    /* The global reduced-motion rule freezes the animation, so the label is the
       only signal left — which is why it is never optional. */
  `,
})
export class UiSpinner {
  readonly label = input('Loading…');
}
