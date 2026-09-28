import { Component, computed, effect, forwardRef, input, output, signal, viewChild, ElementRef, booleanAttribute } from '@angular/core';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';
import { MfSheet } from './sheet';

export interface MfOption {
  value: string;
  label: string;
  /** A second line: a date, a city, a role. */
  hint?: string;
  disabled?: boolean;
}

/**
 * A select that opens as a sheet and can be searched.
 *
 * The native select on Android is a list nobody can type into, and on iOS it is
 * a wheel — both fine for three choices and hopeless for the lists this app
 * actually has: a season of events, a country, a venue's ticket tiers.
 *
 * So: a trigger that looks like the app's other fields, a sheet with a search
 * box at the top, and a list that filters as you type. The search box is only
 * shown once the list is long enough to need it, because a keyboard covering
 * half the screen to choose between "Yes" and "No" is a worse default than no
 * search at all.
 *
 * Matching is on the label and the hint together, so "toronto" finds an event
 * whose title says nothing about the city.
 */
@Component({
  selector: 'mf-select',
  imports: [MfSheet],
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => MfSelect),
      multi: true,
    },
  ],
  template: `
    @if (!bare()) {
    <button
      class="trigger"
      type="button"
      [class.empty]="!chosen()"
      [disabled]="disabled()"
      [attr.aria-haspopup]="'dialog'"
      [attr.aria-expanded]="open()"
      [attr.aria-label]="ariaLabel() ?? heading()"
      (click)="show()"
    >
      <span class="chosen">
        <span class="text">{{ chosen()?.label ?? placeholder() }}</span>
        @if (chosen()?.hint) {
          <span class="hint">{{ chosen()?.hint }}</span>
        }
      </span>
      <span class="chevron" aria-hidden="true"></span>
    </button>
    }

    <!-- A long list opens halfway and can be pulled up to full height. -->
    <mf-sheet
      [open]="open()"
      [expandable]="options().length > 8"
      [heading]="heading()"
      [subheading]="subheading()"
      (keydown)="walk($any($event))"
      (closed)="hide()"
    >
      @if (searchable()) {
        <div class="search">
          <input
            #search
            type="search"
            inputmode="search"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            [placeholder]="searchPlaceholder()"
            [attr.aria-label]="searchPlaceholder()"
            [value]="query()"
            (input)="query.set($any($event.target).value)"
          />
        </div>
      }

      <ul class="options" role="listbox" [attr.aria-label]="heading()">
        @for (option of shown(); track option.value) {
          <li>
            <button
              type="button"
              class="option"
              role="option"
              [attr.aria-selected]="option.value === selected()"
              [class.selected]="option.value === selected()"
              [disabled]="option.disabled"
              (click)="choose(option)"
            >
              <span class="text">
                {{ option.label }}
                @if (option.hint) {
                  <span class="hint">{{ option.hint }}</span>
                }
              </span>
              @if (option.value === selected()) {
                <span class="tick" aria-hidden="true">✓</span>
              }
            </button>
          </li>
        } @empty {
          <li class="none">Nothing matches “{{ query() }}”.</li>
        }
      </ul>
    </mf-sheet>
  `,
  styles: `
    :host {
      display: block;
    }

    .trigger {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      width: 100%;
      min-height: var(--mf-tap);
      padding: var(--space-2) var(--space-4);
      border: 0;
      border-radius: var(--radius-control);
      background: var(--surface-inset);
      /* The same hairline as mf-field: a form whose selects are flat and whose
         inputs are outlined reads as two forms. */
      box-shadow:
        inset 0 0 0 1px var(--border),
        inset 0 1px 2px rgb(0 0 0 / 0.04);
      color: var(--text);
      font-family: inherit;
      font-size: var(--font-size-base);
      text-align: left;
      cursor: pointer;
    }

    .trigger.empty .text {
      color: var(--text-subtle);
    }

    .trigger:disabled {
      opacity: 0.55;
    }

    .chosen {
      display: grid;
      flex: 1;
      min-width: 0;
      gap: 2px;
    }

    .chosen .text,
    .option .text {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .hint {
      display: block;
      font-size: var(--font-size-xs);
      color: var(--text-subtle);
    }

    .chevron {
      width: 0.5rem;
      height: 0.5rem;
      border-right: 2px solid var(--text-subtle);
      border-bottom: 2px solid var(--text-subtle);
      transform: rotate(45deg) translate(-2px, -2px);
    }

    .search {
      position: sticky;
      top: 0;
      z-index: 1;
      padding-bottom: var(--space-3);
      background: var(--surface-raised);
    }

    .search input {
      width: 100%;
      min-height: var(--mf-tap);
      padding: 0 var(--space-4);
      border: 0;
      border-radius: var(--radius-control);
      background: var(--surface-inset);
      /* The same hairline as mf-field: a form whose selects are flat and whose
         inputs are outlined reads as two forms. */
      box-shadow:
        inset 0 0 0 1px var(--border),
        inset 0 1px 2px rgb(0 0 0 / 0.04);
      color: var(--text);
      font-family: inherit;
      font-size: var(--font-size-base);
      outline: none;
    }

    .search input:focus {
      box-shadow: inset 0 0 0 2px var(--primary);
    }

    .options {
      display: grid;
      gap: 2px;
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .option {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      width: 100%;
      min-height: var(--mf-tap);
      padding: var(--space-2) var(--space-3);
      border: 0;
      border-radius: var(--radius-md);
      background: transparent;
      color: var(--text);
      font-family: inherit;
      font-size: var(--font-size-base);
      text-align: left;
      cursor: pointer;
    }

    .option:active {
      background: var(--surface-hover);
    }

    .option.selected {
      background: var(--primary-soft);
      color: var(--primary-soft-text);
      font-weight: var(--font-weight-medium);
    }

    .option .text {
      flex: 1;
      min-width: 0;
    }

    .tick {
      color: var(--primary-text);
    }

    .none {
      padding: var(--space-4) var(--space-3);
      color: var(--text-muted);
      font-size: var(--font-size-sm);
    }
  `,
})
export class MfSelect implements ControlValueAccessor {
  readonly options = input<MfOption[]>([]);
  readonly placeholder = input('Choose…');
  readonly heading = input<string | null>('Choose');
  readonly subheading = input<string | null>(null);
  readonly searchPlaceholder = input('Type to search');
  readonly ariaLabel = input<string | null>(null);
  readonly disabled = input(false, { transform: booleanAttribute });

  /**
   * Only the sheet, opened with show() — for a choice asked in the middle of
   * something else ("which event is this for?") rather than a field on a form.
   */
  readonly bare = input(false, { transform: booleanAttribute });

  /** Below this many options, searching costs more than it saves. */
  readonly searchAfter = input(7);

  /**
   * The chosen value, for a template that binds it directly rather than
   * through a form. Forms go through writeValue instead; both end in the same
   * signal, so a select cannot be told two different things at once.
   */
  readonly value = input<string | null>(null);

  readonly valueChange = output<string | null>();

  protected readonly selected = signal<string | null>(null);
  protected readonly open = signal(false);
  protected readonly query = signal('');

  private readonly search = viewChild<ElementRef<HTMLInputElement>>('search');

  protected readonly chosen = computed(() => this.options().find((option) => option.value === this.selected()) ?? null);

  protected readonly searchable = computed(() => this.options().length >= this.searchAfter());

  protected readonly shown = computed(() => {
    const query = this.query().trim().toLowerCase();

    if (query === '') return this.options();

    return this.options().filter((option) =>
      `${option.label} ${option.hint ?? ''}`.toLowerCase().includes(query),
    );
  });

  constructor() {
    effect(() => this.selected.set(this.value()));

    // Focus the search once the sheet has drawn, so the keyboard comes up with
    // the list rather than after it.
    effect(() => {
      if (!this.open() || !this.searchable()) return;

      setTimeout(() => this.search()?.nativeElement.focus(), 280);
    });
  }

  show(): void {
    if (this.disabled()) return;

    this.query.set('');
    this.open.set(true);
  }

  hide(): void {
    this.open.set(false);
    this.onTouched();
  }

  /**
   * Arrows through the list, the way any list answers.
   *
   * Tab already reaches every option — the sheet keeps focus inside itself, so
   * it walks them one by one — but a list of twenty cities is a lot of tabbing
   * to reach Vancouver. Home and End for the same reason.
   *
   * Listened for on the sheet rather than the list: focus starts on the
   * sheet's own panel, which sits above the list, and a keydown there never
   * reaches a handler below it.
   *
   * Typing stays with the search box above; this only moves.
   */
  protected walk(event: KeyboardEvent): void {
    const keys = ['ArrowDown', 'ArrowUp', 'Home', 'End'];

    if (!keys.includes(event.key)) return;

    const sheet = event.currentTarget as HTMLElement;
    const options = [...sheet.querySelectorAll<HTMLButtonElement>('.option:not([disabled])')];

    if (options.length === 0) return;

    event.preventDefault();

    const at = options.indexOf(sheet.ownerDocument.activeElement as HTMLButtonElement);

    const next = {
      ArrowDown: at < 0 ? 0 : (at + 1) % options.length,
      ArrowUp: at < 0 ? options.length - 1 : (at - 1 + options.length) % options.length,
      Home: 0,
      End: options.length - 1,
    }[event.key]!;

    options[next].focus();
  }

  protected choose(option: MfOption): void {
    if (option.disabled) return;

    this.selected.set(option.value);
    this.onChange(option.value);
    this.valueChange.emit(option.value);
    this.hide();
  }

  // --- ControlValueAccessor -------------------------------------------------

  private onChange: (value: string | null) => void = () => undefined;
  private onTouched: () => void = () => undefined;

  writeValue(value: string | null): void {
    this.selected.set(value ?? null);
  }

  registerOnChange(fn: (value: string | null) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }
}
