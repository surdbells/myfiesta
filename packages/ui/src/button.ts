import { Directive, computed, input } from '@angular/core';

/**
 * A button, applied to a real `<button>` or `<a>`.
 *
 * A directive rather than a wrapper component, deliberately. A wrapper has to
 * re-expose type, disabled, form, aria-*, routerLink and click for every caller
 * that needs one, and each of those is a chance to forget — a wrapped button
 * that silently loses `type="submit"` breaks a form in a way nobody notices
 * until somebody presses enter in a field.
 *
 * Usage: <button uiButton variant="primary" size="lg">Publish</button>
 */
@Directive({
  selector: 'button[uiButton], a[uiButton]',
  host: {
    '[class]': 'classes()',
    // Anchors are not buttons. Without this, a link styled as a button is
    // announced as a link and cannot be activated with space.
    '[attr.role]': 'null',
  },
})
export class UiButton {
  readonly variant = input<'primary' | 'secondary' | 'soft' | 'ghost' | 'danger'>('secondary');
  readonly size = input<'sm' | 'md' | 'lg'>('md');
  readonly block = input(false);

  /**
   * Busy, not disabled.
   *
   * A disabled button loses focus and stops announcing itself, so a screen
   * reader user who pressed it hears nothing at all. aria-busy keeps it in the
   * tab order and says what is happening.
   */
  readonly loading = input(false);

  readonly classes = computed(() =>
    [
      'btn',
      `btn--${this.variant()}`,
      this.size() === 'md' ? '' : `btn--${this.size()}`,
      this.block() ? 'btn--block' : '',
      this.loading() ? 'is-loading' : '',
    ]
      .filter(Boolean)
      .join(' '),
  );
}
