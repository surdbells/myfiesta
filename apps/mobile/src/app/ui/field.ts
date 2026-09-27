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

    <div class="box" [class.invalid]="!!error()" [class.focused]="focused()" [class.tall]="tall()">
      @if (prefix()) {
        <span class="affix" aria-hidden="true">{{ prefix() }}</span>
      }
      <ng-content />
      @if (suffix()) {
        <span class="affix" aria-hidden="true">{{ suffix() }}</span>
      }
    </div>

    @if (error() || hint() || limit()) {
      <div class="foot">
        @if (error()) {
          <p class="error" role="alert">{{ error() }}</p>
        } @else if (hint()) {
          <p class="hint">{{ hint() }}</p>
        }
        @if (limit(); as max) {
          <!-- Only once it matters: a counter from the first letter is a nag. -->
          @if (count() > max * 0.7) {
            <p class="count" [class.over]="count() > max" aria-live="polite">{{ count() }} / {{ max }}</p>
          }
        }
      </div>
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
      /*
       * Inset rather than a border: the box has to read as a container the
       * text sits inside, not as a line drawn near it.
       *
       * The hairline is not decoration. A fill alone is a 4% difference from
       * the page, which is nothing on a phone held at arm's length outside a
       * venue — the field disappeared and people tapped the label.
       */
      box-shadow:
        inset 0 0 0 1px var(--border),
        inset 0 1px 2px rgb(0 0 0 / 0.04);
      transition:
        box-shadow 120ms ease,
        background-color 120ms ease;
    }

    .box.focused {
      background: var(--surface-raised);
      box-shadow:
        inset 0 0 0 2px var(--primary),
        var(--focus-ring);
    }

    .box.invalid {
      box-shadow: inset 0 0 0 2px var(--danger);
    }

    /* A textarea: the box grows with it and the text starts at the top. */
    .box.tall {
      align-items: flex-start;
    }

    .box ::ng-deep textarea {
      min-height: 6.5rem;
      resize: none;
      field-sizing: content;
      line-height: var(--font-leading-normal);
    }

    /* What the number is in: $ before it, % after it. Part of the box,
       not of the value, so it is never typed over or copied. */
    .affix {
      flex: none;
      color: var(--text-muted);
      font-weight: var(--font-weight-medium);
      padding: 0 var(--space-1);
    }

    .foot {
      display: flex;
      align-items: baseline;
      gap: var(--space-3);
    }

    .foot > :first-child {
      flex: 1;
    }

    .count {
      margin-left: auto;
      font-size: var(--font-size-xs);
      color: var(--text-subtle);
      font-variant-numeric: tabular-nums;
    }

    .count.over {
      color: var(--danger-text);
      font-weight: var(--font-weight-semibold);
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

  /** Shown inside the box before the value: a currency, a "+". */
  readonly prefix = input<string | null>(null);

  /** Shown inside the box after it: a "%", a unit. */
  readonly suffix = input<string | null>(null);

  /** A character limit, counted once the text is most of the way there. */
  readonly limit = input<number | null>(null);

  /** How long the text is now, for the counter. The screen already has it. */
  readonly count = input(0);

  protected readonly focused = signal(false);
  protected readonly tall = signal(false);

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
    this.tall.set(element.tagName === 'TEXTAREA');
  }
}
