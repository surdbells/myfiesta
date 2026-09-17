import {
  afterNextRender,
  booleanAttribute,
  Component,
  contentChild,
  effect,
  ElementRef,
  inject,
  input,
  signal,
} from '@angular/core';

let nextId = 0;

/**
 * A form field: label, control, and whatever the control needs to say.
 *
 * Solid rather than outlined. An outlined input on a phone is a 1px rectangle
 * that disappears against a dark background in a dark venue; a filled box with
 * its own surface is legible at arm's length, and the focus state can then be
 * a colour change rather than a thicker hairline.
 *
 * The label sits above the control and stays there. Floating labels save a
 * line of height and cost the reader the label at exactly the moment they are
 * typing into the thing it names.
 */
@Component({
  selector: 'mf-field',
  template: `
    <label class="label" [attr.for]="controlId()">
      {{ label() }}
      @if (optional()) {
        <span class="optional">optional</span>
      }
    </label>

    <div class="box" [class.invalid]="!!error()" [class.focused]="focused()">
      <ng-content />
    </div>

    @if (error()) {
      <p class="error" role="alert">{{ error() }}</p>
    } @else if (hint()) {
      <p class="hint">{{ hint() }}</p>
    }
  `,
  host: {
    '(focusin)': 'focused.set(true)',
    '(focusout)': 'focused.set(false)',
  },
  styles: `
    :host {
      display: grid;
      gap: var(--space-2);
    }

    .label {
      display: flex;
      align-items: baseline;
      gap: var(--space-2);
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
      color: var(--text);
    }

    .optional {
      font-weight: var(--font-weight-regular);
      font-size: var(--font-size-xs);
      color: var(--text-subtle);
    }

    .box {
      display: flex;
      align-items: center;
      min-height: var(--mf-tap);
      padding: 0 var(--space-4);
      border-radius: var(--radius-lg);
      background: var(--surface-inset);
      /* Inset rather than a border: the box has to read as a container the
         text sits inside, not as a line drawn near it. */
      box-shadow: inset 0 0 0 1px transparent;
      transition:
        box-shadow 120ms ease,
        background-color 120ms ease;
    }

    .box.focused {
      background: var(--surface-raised);
      box-shadow: inset 0 0 0 2px var(--primary);
    }

    .box.invalid {
      box-shadow: inset 0 0 0 2px var(--danger);
    }

    /* The control itself is projected, so it is styled from here. */
    .box ::ng-deep input,
    .box ::ng-deep textarea,
    .box ::ng-deep select {
      flex: 1;
      min-width: 0;
      width: 100%;
      border: 0;
      outline: none;
      background: transparent;
      color: var(--text);
      font-family: inherit;
      /* 16px or iOS zooms the whole page on focus, and the layout never
         quite recovers. */
      font-size: var(--font-size-base);
      padding: var(--space-3) 0;
    }

    .box ::ng-deep input::placeholder,
    .box ::ng-deep textarea::placeholder {
      color: var(--text-subtle);
    }

    .hint,
    .error {
      font-size: var(--font-size-sm);
      line-height: var(--font-leading-snug);
    }

    .hint {
      color: var(--text-muted);
    }

    .error {
      color: var(--danger-text);
    }
  `,
})
export class MfField {
  readonly label = input.required<string>();
  readonly hint = input<string | null>(null);
  readonly error = input<string | null>(null);
  readonly optional = input(false, { transform: booleanAttribute });

  protected readonly focused = signal(false);

  private readonly marked = contentChild<ElementRef<HTMLElement>>('control');
  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly generated = `mf-field-${nextId++}`;

  protected readonly controlId = signal(this.generated);

  constructor() {
    /*
     * A label needs something to point at.
     *
     * The control is taken from a #control reference when there is one, and
     * found in the projected content when there is not. Requiring the marker
     * was a quiet trap: forget it on one screen and that label points at
     * nothing, which looks identical on the page and is a field a screen
     * reader cannot name.
     */
    afterNextRender(() => this.adopt());
    effect(() => {
      this.marked();
      this.adopt();
    });
  }

  private adopt(): void {
    const element =
      this.marked()?.nativeElement ??
      (this.host.nativeElement as HTMLElement).querySelector<HTMLElement>(
        '.box input, .box textarea, .box select, .box [contenteditable]',
      );

    if (!element) return;

    if (!element.id) element.id = this.generated;

    this.controlId.set(element.id);
  }
}
