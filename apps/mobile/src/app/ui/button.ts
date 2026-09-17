import { Component, computed, input, booleanAttribute } from '@angular/core';

/**
 * The app's only button.
 *
 * Not ion-button. Ionic's button carries its own ripple, its own type scale
 * and its own idea of a corner radius, and an app built on it looks like every
 * other Ionic app — which for a ticketing brand whose whole pitch is the night
 * out is the wrong impression at the door.
 *
 * Three things this does that a web button does not need to:
 *
 * - it is never smaller than a finger (var(--mf-tap));
 * - it answers the press immediately, by scaling down rather than waiting for
 *   a colour transition, because on a phone a 100ms delay reads as a missed tap;
 * - it keeps its size while loading, so a row of controls does not reflow
 *   under the thumb that is still on the screen.
 */
@Component({
  selector: 'button[mfButton], a[mfButton]',
  template: `
    @if (loading()) {
      <span class="spinner" aria-hidden="true"></span>
    }
    <!--
      While loading with a label of its own, the resting label goes entirely so
      the button says one thing; while loading without one it is only made
      invisible, so the button keeps its width and the row does not reflow
      under a thumb that is still on the screen.
    -->
    <span class="label" [class.hidden]="loading() && !label()" [class.gone]="loading() && !!label()">
      <ng-content />
    </span>
    @if (loading() && label()) {
      <span class="label">{{ label() }}</span>
    }
  `,
  host: {
    '[class]': 'classes()',
    '[attr.disabled]': 'isButton && (disabled() || loading()) ? "" : null',
    '[attr.aria-disabled]': 'disabled() || loading() ? "true" : null',
    '[attr.aria-busy]': 'loading() ? "true" : null',
  },
  styles: `
    :host {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: var(--space-2);
      min-height: var(--mf-tap);
      padding: 0 var(--space-5);
      border: 0;
      border-radius: var(--radius-lg);
      font-family: inherit;
      font-size: var(--font-size-base);
      font-weight: var(--font-weight-semibold);
      line-height: 1;
      text-decoration: none;
      cursor: pointer;
      transition:
        transform 90ms ease,
        background-color var(--motion-fast, 120ms) ease,
        opacity 120ms ease;
    }

    /* The press itself, not a hover state a phone never has. */
    :host(:active:not([disabled])) {
      transform: scale(0.97);
    }

    :host([disabled]),
    :host([aria-disabled='true']) {
      opacity: 0.55;
      cursor: default;
    }

    :host(.block) {
      display: flex;
      width: 100%;
    }

    :host(.lg) {
      min-height: 56px;
      font-size: var(--font-size-lg);
    }

    :host(.sm) {
      min-height: 40px;
      padding: 0 var(--space-4);
      font-size: var(--font-size-sm);
    }

    :host(.primary) {
      background: var(--primary);
      color: var(--on-primary);
    }

    :host(.secondary) {
      background: var(--surface-raised);
      color: var(--text);
      box-shadow: inset 0 0 0 1px var(--border-strong);
    }

    :host(.ghost) {
      background: transparent;
      color: var(--primary-text);
      padding: 0 var(--space-3);
    }

    :host(.danger) {
      background: var(--danger);
      /* The token, not white. In dark the danger colour is a light coral and
         white on it is 2.9:1 — under the 4.5 this project's own contrast check
         holds every other pairing to. */
      color: var(--text-inverse);
    }

    .label.hidden {
      visibility: hidden;
    }

    .label.gone {
      display: none;
    }

    .spinner {
      width: 1.125rem;
      height: 1.125rem;
      border-radius: 50%;
      border: 2px solid currentColor;
      border-top-color: transparent;
      animation: mf-spin 700ms linear infinite;
    }

    @keyframes mf-spin {
      to {
        transform: rotate(360deg);
      }
    }
  `,
})
export class MfButton {
  readonly variant = input<'primary' | 'secondary' | 'ghost' | 'danger'>('primary');
  readonly size = input<'sm' | 'md' | 'lg'>('md');
  readonly block = input(false, { transform: booleanAttribute });
  readonly loading = input(false, { transform: booleanAttribute });
  readonly disabled = input(false, { transform: booleanAttribute });

  /** Said while it is working, e.g. "Signing in…", instead of the resting label. */
  readonly label = input<string | null>(null);

  protected readonly isButton = typeof HTMLElement !== 'undefined';

  protected readonly classes = computed(() =>
    [this.variant(), this.size(), this.block() ? 'block' : ''].filter(Boolean).join(' '),
  );
}
