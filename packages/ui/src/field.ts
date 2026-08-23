import {
  AfterContentInit,
  Component,
  ElementRef,
  computed,
  effect,
  inject,
  input,
} from '@angular/core';

let sequence = 0;

/**
 * A labelled form control.
 *
 * This wraps a native `<input>`, `<select>` or `<textarea>` rather than
 * replacing it. A custom control would have to reimplement autofill, the
 * mobile keyboard hints, `form` association, browser password managers and the
 * date picker — all of which people rely on and none of which are worth
 * rewriting.
 *
 * What the wrapper adds is the accessibility wiring that screens keep getting
 * wrong when each one does it by hand:
 *
 *   - a generated id, so the label actually points at the control
 *   - `aria-describedby` pointing at the hint and the error together, because a
 *     control with both must announce both
 *   - `aria-invalid` while there is an error
 *   - `aria-required`, since `required` alone is not announced by every reader
 *
 * The error is a *message*, not a boolean. A field that says "Required" tells
 * somebody nothing they did not already know from the asterisk; the caller is
 * made to write what is actually wrong.
 */
@Component({
  selector: 'ui-field',
  template: `
    <div class="field" [class.field--invalid]="!!error()">
      <label class="field__label" [attr.for]="controlId()">
        {{ label() }}
        @if (required()) {
          <span class="field__required" aria-hidden="true">*</span>
        }
        @if (optionalMark() && !required()) {
          <span class="field__optional">optional</span>
        }
      </label>

      <div class="field__control"><ng-content /></div>

      <!--
        The error replaces the hint rather than stacking under it. Once
        something is wrong, the hint is no longer the thing to read.
      -->
      @if (error()) {
        <p class="field__error" [id]="errorId()" role="alert">{{ error() }}</p>
      } @else if (hint()) {
        <p class="field__hint" [id]="hintId()">{{ hint() }}</p>
      }
    </div>
  `,
  styles: `
    .field { display: grid; gap: var(--space-2); min-width: 0; }
    .field__label {
      display: flex;
      align-items: baseline;
      gap: var(--space-2);
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
      color: var(--text);
    }
    .field__required { color: var(--danger); }
    .field__optional {
      font-weight: var(--font-weight-regular);
      font-size: var(--font-size-xs);
      color: var(--text-subtle);
    }
    .field__control { display: grid; min-width: 0; }
    .field__hint,
    .field__error {
      margin: 0;
      font-size: var(--font-size-sm);
      line-height: var(--font-leading-snug);
    }
    .field__hint { color: var(--text-muted); }
    .field__error { color: var(--danger-text); }

    /* The red outline is applied from here rather than asking every screen to
       remember a class on the control itself. */
    .field--invalid .field__control ::ng-deep :is(input, select, textarea) {
      border-color: var(--danger);
    }
    .field--invalid .field__control ::ng-deep :is(input, select, textarea):focus-visible {
      outline-color: var(--danger);
    }
  `,
})
export class UiField implements AfterContentInit {
  readonly label = input.required<string>();
  readonly hint = input<string | null>(null);

  /** What is wrong, in words. Null while the field is fine. */
  readonly error = input<string | null>(null);

  readonly required = input(false);

  /**
   * Marks the field "optional" in words.
   *
   * On a form where most fields are required, marking the few that are not is
   * clearer than starring the many that are.
   */
  readonly optionalMark = input(false);

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly uid = `field-${++sequence}`;

  readonly controlId = computed(() => this.uid);
  readonly hintId = computed(() => `${this.uid}-hint`);
  readonly errorId = computed(() => `${this.uid}-error`);

  private control: HTMLElement | null = null;

  constructor() {
    // Re-applied whenever the error appears or clears, since aria-invalid and
    // aria-describedby both change with it.
    effect(() => {
      this.error();
      this.hint();
      this.required();
      this.apply();
    });
  }

  ngAfterContentInit(): void {
    this.control = this.host.nativeElement.querySelector(
      'input, select, textarea',
    );
    this.apply();
  }

  /**
   * Writes the wiring onto the projected control.
   *
   * Done imperatively because the control arrives through content projection —
   * there is no template binding to hang these attributes on, and the
   * alternative is asking every screen to repeat them by hand, which is the
   * duplication this component exists to remove.
   */
  private apply(): void {
    const control = this.control;

    if (!control) return;

    if (!control.id) control.id = this.uid;

    const described = [
      this.error() ? this.errorId() : null,
      this.error() ? null : this.hint() ? this.hintId() : null,
    ].filter(Boolean);

    if (described.length) {
      control.setAttribute('aria-describedby', described.join(' '));
    } else {
      control.removeAttribute('aria-describedby');
    }

    if (this.error()) {
      control.setAttribute('aria-invalid', 'true');
    } else {
      control.removeAttribute('aria-invalid');
    }

    if (this.required()) {
      control.setAttribute('aria-required', 'true');
    } else {
      control.removeAttribute('aria-required');
    }
  }
}
