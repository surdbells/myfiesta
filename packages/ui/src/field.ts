import {
  AfterContentChecked,
  AfterContentInit,
  afterNextRender,
  Component,
  ElementRef,
  booleanAttribute,
  computed,
  effect,
  inject,
  input,
  signal,
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
    .field--invalid .field__control ::ng-deep :is(input, select, textarea, .ui-select__trigger) {
      border-color: var(--danger);
    }
    .field--invalid .field__control ::ng-deep :is(input, select, textarea):focus-visible {
      outline-color: var(--danger);
    }
  `,
})
export class UiField implements AfterContentInit, AfterContentChecked {
  readonly label = input.required<string>();
  readonly hint = input<string | null>(null);

  /** What is wrong, in words. Null while the field is fine. */
  readonly error = input<string | null>(null);

  /**
   * Marked required.
   *
   * Takes the bare attribute — `<ui-field required>` — because that is how
   * it reads on the native control beside it, and a form where half the
   * flags are `[required]="true"` invites the other half to be written
   * `required` and silently pass the empty string.
   */
  readonly required = input(false, { transform: booleanAttribute });

  /**
   * Marks the field "optional" in words.
   *
   * On a form where most fields are required, marking the few that are not is
   * clearer than starring the many that are.
   */
  readonly optionalMark = input(false, { transform: booleanAttribute });

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly uid = `field-${++sequence}`;

  /**
   * The id the label points at.
   *
   * Not simply the generated one: a screen may put its own id on the
   * control, and honouring that while labelling the generated one leaves the
   * label pointing at an element that does not exist — which looks correct
   * on screen and is the exact failure this component exists to prevent.
   */
  readonly controlId = signal<string | null>(null);

  readonly hintId = computed(() => `${this.uid}-hint`);
  readonly errorId = computed(() => `${this.uid}-error`);

  private control: HTMLElement | null = null;

  constructor() {
    /*
     * Looked for again once there is a page, always — not only when nothing
     * was found the first time.
     *
     * A projected component renders its own template after this one's content
     * is initialised, and sets its own id on the pass after that. Looking once
     * caught the control mid-build, gave it an id of ours, and then watched
     * the component overwrite it — leaving the label pointing at an id nothing
     * had. By the time the page is rendered, the control is finished.
     */
    afterNextRender(() => this.find());

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
    this.find();
  }

  /**
   * Keep pointing at the control, as it is now.
   *
   * Looking once is not enough, in two ways. A projected component renders its
   * own template after this one's content is initialised, and a control inside
   * a drawer is thrown away and rebuilt each time it opens — so a field that
   * looked once labels an element that is gone. And a component that sets its
   * own id does it on a later pass, overwriting the one put there here: the
   * label went on pointing at an id nothing had any more, which looks perfect
   * on screen and is a control a screen reader cannot name.
   *
   * Cheap: it does nothing while the control is still attached and still
   * called what the label says it is called.
   */
  ngAfterContentChecked(): void {
    if (this.control?.isConnected && this.control.id === this.controlId()) return;

    this.find();
  }

  /**
   * Look for the control, twice.
   *
   * A plain input is there by the time content is initialised. A projected
   * component is not: its own template renders later, so a field wrapping a
   * ui-select found nothing and left its label pointing at an id that did not
   * exist — which looks perfect on screen and is a control a screen reader
   * cannot name. Looking again after the first render catches it.
   */
  private find(): void {
    /*
     * Asked in order of preference, one at a time.
     *
     * Not one selector list: querySelector returns the first match in document
     * order, not the first selector that matches. A ui-select holds a search
     * box as well as its trigger, and asking for both at once labelled
     * whichever the template happened to put first — the search box, which
     * only exists while the menu is open.
     */
    const host = this.host.nativeElement as HTMLElement;

    this.control =
      host.querySelector('button[role=combobox]') ??
      host.querySelector('input:not([role=searchbox]), select, textarea');

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

    if (!control) {
      // No control, no label pointing at one. A dangling `for` is worse than
      // none: it reads as wired and is not.
      this.controlId.set(null);

      return;
    }

    if (!control.id) control.id = this.uid;

    this.controlId.set(control.id);

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
