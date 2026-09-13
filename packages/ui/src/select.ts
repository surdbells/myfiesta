import {
  Component,
  ElementRef,
  Injector,
  afterNextRender,
  booleanAttribute,
  computed,
  forwardRef,
  inject,
  input,
  model,
  signal,
  viewChild,
} from '@angular/core';
import { NG_VALUE_ACCESSOR, type ControlValueAccessor } from '@angular/forms';

export interface SelectOption {
  value: string;
  label: string;
  /** A second line, and more to search: a province's code, a tier's price. */
  hint?: string;
  disabled?: boolean;
}

let sequence = 0;

/**
 * A dropdown you can type into.
 *
 * Replaces the native `<select>`, which offers no search: finding Saskatchewan
 * in a list of provinces, one event among forty, or a time zone was a scroll.
 * Every dropdown in both applications uses this, so they all work the same way
 * — including the two-option ones, where typing still jumps straight to the
 * choice and nobody has to learn which dropdowns search and which do not.
 *
 * Built to the ARIA combobox pattern with a listbox popup:
 *
 *   - the closed control is a button with role="combobox", so a label's `for`
 *     reaches it (pass `controlId`, which becomes its id)
 *   - opening focuses a search field that owns the list through
 *     aria-activedescendant; arrows move, Enter chooses, Escape and Tab close,
 *     and focus goes back to the control rather than the top of the page
 *   - typing a letter on the closed control opens it with that letter searched
 *
 * The list is shown as a popover in the top layer, placed against the
 * control. A plain absolutely positioned list was cut off inside the modal
 * dialogs and scrolled cards it most often sits in.
 *
 * Works with ngModel (it is a value accessor) and with `[(value)]`.
 */
@Component({
  selector: 'ui-select',
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => UiSelect), multi: true }],
  host: { class: 'ui-select', '[class.is-compact]': 'compact()' },
  template: `
    <button
      #trigger
      type="button"
      class="ui-select__trigger"
      role="combobox"
      aria-haspopup="listbox"
      [id]="triggerId()"
      [attr.name]="name()"
      [attr.aria-expanded]="open()"
      [attr.aria-controls]="listId"
      [attr.aria-label]="ariaLabel()"
      [attr.aria-invalid]="invalid() || null"
      [disabled]="isDisabled()"
      [class.is-placeholder]="!selected()"
      [class.is-compact]="compact()"
      (click)="toggle()"
      (keydown)="onTriggerKey($event)"
    >
      <span class="ui-select__value">{{ selected()?.label ?? placeholder() }}</span>
    </button>

    <div
      #panel
      class="ui-select__panel"
      popover="manual"
      [class.is-open]="open()"
      [style.top.px]="position().top"
      [style.left.px]="position().left"
      [style.width.px]="position().width"
      [style.bottom.px]="position().bottom"
      (focusout)="onFocusOut($event)"
    >
      <input
        #search
        class="ui-select__search"
        type="text"
        role="searchbox"
        autocomplete="off"
        spellcheck="false"
        [attr.aria-label]="'Search ' + (ariaLabel() ?? 'options')"
        [attr.aria-controls]="listId"
        [attr.aria-activedescendant]="activeId()"
        [placeholder]="searchPlaceholder()"
        [value]="query()"
        (input)="onSearch($event)"
        (keydown)="onSearchKey($event)"
      />

      <ul class="ui-select__list" role="listbox" [id]="listId" [attr.aria-label]="ariaLabel()">
        @for (option of filtered(); track option.value; let i = $index) {
          <li
            class="ui-select__option"
            role="option"
            [id]="listId + '-' + i"
            [attr.aria-selected]="option.value === value()"
            [attr.aria-disabled]="option.disabled || null"
            [class.is-active]="i === active()"
            (mousedown)="$event.preventDefault()"
            (mouseenter)="active.set(i)"
            (click)="choose(option)"
          >
            <span class="ui-select__label">{{ option.label }}</span>
            @if (option.hint) {
              <span class="ui-select__hint">{{ option.hint }}</span>
            }
          </li>
        } @empty {
          <li class="ui-select__none" role="presentation">No matches for “{{ query() }}”</li>
        }
      </ul>
    </div>
  `,
  styles: `
    :host { display: block; min-width: 0; position: relative; }

    /* The closed control matches the native select it replaced, drawn from
       the same tokens, so a form with both does not look like two apps. */
    .ui-select__trigger {
      display: flex;
      align-items: center;
      width: 100%;
      min-width: 0;
      height: 44px;
      padding: 0 var(--space-6) 0 var(--space-3);
      font: inherit;
      font-size: var(--font-size-sm);
      text-align: left;
      color: var(--text);
      background-color: var(--surface-raised);
      background-image: linear-gradient(45deg, transparent 50%, currentColor 50%),
        linear-gradient(135deg, currentColor 50%, transparent 50%);
      background-position: calc(100% - 18px) 20px, calc(100% - 13px) 20px;
      background-size: 5px 5px, 5px 5px;
      background-repeat: no-repeat;
      border: 1px solid var(--field-border);
      border-radius: var(--radius-md);
      cursor: pointer;
      transition: border-color var(--motion-fast) var(--motion-ease), box-shadow var(--motion-fast) var(--motion-ease);
    }
    .ui-select__trigger:focus-visible,
    .ui-select__trigger[aria-expanded='true'] {
      outline: none;
      border-color: var(--primary);
      box-shadow: var(--focus-ring);
    }
    .ui-select__trigger:disabled {
      color: var(--text-subtle);
      background-color: var(--surface-inset);
      cursor: not-allowed;
    }
    .ui-select__trigger[aria-invalid='true'] { border-color: var(--danger); }
    .ui-select__trigger.is-placeholder { color: var(--text-subtle); }
    /* Inline beside words, like "Sort by": as wide as its choice, pill-shaped. */
    :host(.is-compact) { display: inline-block; }
    .ui-select__trigger.is-compact { width: auto; border-radius: 9999px; font-weight: var(--font-weight-medium); }
    .ui-select__value { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    .ui-select__panel {
      position: fixed;
      inset: auto;
      margin: 0;
      padding: var(--space-2);
      display: none;
      flex-direction: column;
      gap: var(--space-2);
      max-height: min(320px, 60vh);
      color: var(--text);
      background: var(--surface-raised);
      border: 1px solid var(--border);
      border-radius: var(--radius-md);
      box-shadow: var(--shadow-overlay);
      z-index: 1000;
    }
    .ui-select__panel.is-open { display: flex; }

    .ui-select__search {
      height: 40px;
      flex: none;
      /* 16px: iOS zooms the page into any smaller field it focuses. */
      font-size: 16px;
    }

    .ui-select__list {
      margin: 0;
      padding: 0;
      list-style: none;
      overflow-y: auto;
      overscroll-behavior: contain;
    }
    .ui-select__option {
      display: grid;
      gap: 2px;
      min-height: 40px;
      align-content: center;
      padding: var(--space-2) var(--space-3);
      font-size: var(--font-size-sm);
      border-radius: var(--radius-sm);
      cursor: pointer;
    }
    .ui-select__option.is-active { background: var(--surface-hover); }
    .ui-select__option[aria-selected='true'] { font-weight: var(--font-weight-semibold); color: var(--primary-text); }
    .ui-select__option[aria-disabled='true'] { color: var(--text-subtle); cursor: not-allowed; }
    .ui-select__hint { font-size: var(--font-size-xs); color: var(--text-muted); font-weight: var(--font-weight-regular); }
    .ui-select__none { padding: var(--space-3); font-size: var(--font-size-sm); color: var(--text-muted); }
  `,
})
export class UiSelect implements ControlValueAccessor {
  readonly options = input.required<readonly SelectOption[]>();
  readonly value = model<string | null>(null);

  /** Shown while nothing is chosen. */
  readonly placeholder = input('Choose…');
  readonly searchPlaceholder = input('Type to search');

  /** Becomes the control's id, so a `<label for>` elsewhere reaches it. */
  readonly controlId = input<string | null>(null);
  readonly name = input<string | null>(null);
  readonly ariaLabel = input<string | null>(null);
  readonly invalid = input(false, { transform: booleanAttribute });
  readonly disabled = input(false, { transform: booleanAttribute });

  /** Sized to its choice and rounded, for a control that sits inline in a sentence. */
  readonly compact = input(false, { transform: booleanAttribute });

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);
  private readonly trigger = viewChild.required<ElementRef<HTMLButtonElement>>('trigger');
  private readonly panel = viewChild.required<ElementRef<HTMLElement>>('panel');
  private readonly search = viewChild.required<ElementRef<HTMLInputElement>>('search');

  private readonly uid = `ui-select-${++sequence}`;
  readonly listId = `${this.uid}-list`;
  readonly triggerId = computed(() => this.controlId() ?? this.uid);

  readonly open = signal(false);
  readonly query = signal('');
  readonly active = signal(0);
  readonly position = signal<{ top: number | null; bottom: number | null; left: number; width: number }>({
    top: 0,
    bottom: null,
    left: 0,
    width: 0,
  });

  private readonly formDisabled = signal(false);
  readonly isDisabled = computed(() => this.disabled() || this.formDisabled());

  readonly selected = computed(() => this.options().find((o) => o.value === this.value()) ?? null);

  /**
   * Matching options. Accents and case are ignored, and the hint is searched
   * too — "ON" finds Ontario, "montreal" finds Montréal.
   */
  readonly filtered = computed(() => {
    const needle = fold(this.query());
    const options = this.options();

    if (!needle) return options;

    return options.filter((o) => fold(`${o.label} ${o.hint ?? ''} ${o.value}`).includes(needle));
  });

  readonly activeId = computed(() =>
    this.open() && this.filtered().length > 0 ? `${this.listId}-${this.active()}` : null,
  );

  private onChange: (value: string | null) => void = () => undefined;
  private onTouched: () => void = () => undefined;

  // --- value accessor ------------------------------------------------------

  writeValue(value: unknown): void {
    this.value.set(value === null || value === undefined ? null : String(value));
  }

  registerOnChange(fn: (value: string | null) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(disabled: boolean): void {
    this.formDisabled.set(disabled);
  }

  // --- behaviour -------------------------------------------------------------

  toggle(): void {
    this.open() ? this.close(true) : this.show();
  }

  show(seed = ''): void {
    if (this.isDisabled() || this.open()) return;

    this.query.set(seed);
    const index = this.filtered().findIndex((o) => o.value === this.value());
    this.active.set(Math.max(0, index));
    this.place();
    this.open.set(true);

    const panel = this.panel().nativeElement as HTMLElement & { showPopover?: () => void };
    panel.showPopover?.();

    // After the list has rendered. Focusing before it is visible does
    // nothing, and every key typed after the first then landed on the closed
    // control instead of the search.
    afterNextRender(
      () => {
        this.search().nativeElement.focus();
        this.scrollActiveIntoView();
      },
      { injector: this.injector },
    );

    addEventListener('resize', this.reposition);
    addEventListener('scroll', this.reposition, true);
  }

  close(returnFocus: boolean): void {
    if (!this.open()) return;

    this.open.set(false);
    this.query.set('');

    const panel = this.panel().nativeElement as HTMLElement & { hidePopover?: () => void };
    try {
      panel.hidePopover?.();
    } catch {
      // Already hidden by the browser (light dismiss); nothing to undo.
    }

    removeEventListener('resize', this.reposition);
    removeEventListener('scroll', this.reposition, true);

    this.onTouched();

    if (returnFocus) this.trigger().nativeElement.focus();
  }

  choose(option: SelectOption): void {
    if (option.disabled) return;

    if (option.value !== this.value()) {
      this.value.set(option.value);
      this.onChange(option.value);
    }

    this.close(true);
  }

  onSearch(event: Event): void {
    this.query.set((event.target as HTMLInputElement).value);
    this.active.set(0);
  }

  onTriggerKey(event: KeyboardEvent): void {
    // Keys that arrive while the list is opening but focus has not moved yet
    // still belong to the search — a fast typist gets there before the frame.
    if (this.open()) {
      if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
        event.preventDefault();
        this.query.set(this.query() + event.key);
        this.active.set(0);
      } else {
        this.onSearchKey(event);
      }

      return;
    }

    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
      event.preventDefault();
      this.show();

      return;
    }

    // A printable character opens the list already searching for it.
    if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
      event.preventDefault();
      this.show(event.key);
    }
  }

  onSearchKey(event: KeyboardEvent): void {
    const count = this.filtered().length;

    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        this.move(1, count);
        break;
      case 'ArrowUp':
        event.preventDefault();
        this.move(-1, count);
        break;
      case 'Home':
        event.preventDefault();
        this.active.set(0);
        this.scrollActiveIntoView();
        break;
      case 'End':
        event.preventDefault();
        this.active.set(Math.max(0, count - 1));
        this.scrollActiveIntoView();
        break;
      case 'Enter': {
        event.preventDefault();
        const option = this.filtered()[this.active()];
        if (option) this.choose(option);
        break;
      }
      case 'Escape':
        event.preventDefault();
        // Kept from reaching a dialog underneath, which would close too.
        event.stopPropagation();
        this.close(true);
        break;
      case 'Tab':
        this.close(false);
        break;
    }
  }

  /** Closes when focus leaves for anywhere outside the control and its list. */
  onFocusOut(event: FocusEvent): void {
    const next = event.relatedTarget as Node | null;

    if (next && (this.host.nativeElement.contains(next) || this.panel().nativeElement.contains(next))) return;

    this.close(false);
  }

  private move(delta: number, count: number): void {
    if (count === 0) return;

    let next = this.active();

    // Skips disabled options, and stops rather than wrapping when all are.
    for (let step = 0; step < count; step++) {
      next = (next + delta + count) % count;
      if (!this.filtered()[next].disabled) break;
    }

    this.active.set(next);
    this.scrollActiveIntoView();
  }

  private scrollActiveIntoView(): void {
    const id = this.activeId();
    if (!id) return;

    const element = this.panel().nativeElement.querySelector(`#${CSS.escape(id)}`) as HTMLElement | null;
    element?.scrollIntoView?.({ block: 'nearest' });
  }

  private readonly reposition = () => this.place();

  /**
   * Against the control: below it, or above when there is no room below —
   * the last field on a phone form would otherwise open off the screen.
   */
  private place(): void {
    const rect = this.trigger().nativeElement.getBoundingClientRect();
    const below = innerHeight - rect.bottom;
    const wanted = Math.min(320, innerHeight * 0.6);

    // At least wide enough to read the options and type into, which a compact
    // control is not; kept on screen when that makes it wider than the space.
    const width = Math.min(Math.max(rect.width, 240), innerWidth - 16);
    const left = Math.max(8, Math.min(rect.left, innerWidth - width - 8));

    this.position.set(
      below >= wanted || below >= rect.top
        ? { top: rect.bottom + 4, bottom: null, left, width }
        : { top: null, bottom: innerHeight - rect.top + 4, left, width },
    );
  }
}

function fold(text: string): string {
  return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim();
}
