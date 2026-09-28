import { Component, booleanAttribute, computed, input, model, signal } from '@angular/core';
import { MfSheet } from './sheet';
import { MfButton } from './button';
import type { MfOption } from './select';

/**
 * Several of a list, chosen in a sheet.
 *
 * The same trigger as mf-select, so a form's fields all look alike, and the
 * same searchable sheet — but with ticks, and a Done button, because choosing
 * several things is not over at the first tap. The trigger says how many are
 * chosen, or names them when there are only a couple.
 *
 * Nothing chosen can mean "all of them" (a code that applies to every ticket
 * type); `emptyLabel` says so on the trigger rather than leaving it blank.
 */
@Component({
  selector: 'mf-multi-select',
  imports: [MfSheet, MfButton],
  template: `
    <button
      type="button"
      class="trigger"
      [class.empty]="value().length === 0"
      [attr.aria-haspopup]="'dialog'"
      [attr.aria-expanded]="open()"
      [attr.aria-label]="heading()"
      (click)="show()"
    >
      <span class="text">{{ summary() }}</span>
      <span class="chevron" aria-hidden="true"></span>
    </button>

    <mf-sheet [open]="open()" [expandable]="options().length > 8" [heading]="heading()" [subheading]="subheading()" (closed)="open.set(false)">
      @if (options().length > 7) {
        <div class="search">
          <input
            type="search"
            inputmode="search"
            autocomplete="off"
            placeholder="Search"
            aria-label="Search"
            [value]="query()"
            (input)="query.set($any($event.target).value)"
          />
        </div>
      }

      <ul class="options" role="listbox" aria-multiselectable="true" [attr.aria-label]="heading()">
        @for (option of shown(); track option.value) {
          <li>
            <button
              type="button"
              class="option"
              role="option"
              [attr.aria-selected]="picked().includes(option.value)"
              [class.on]="picked().includes(option.value)"
              [disabled]="option.disabled"
              (click)="toggle(option.value)"
            >
              <span class="box" aria-hidden="true">
                <svg viewBox="0 0 16 16"><path d="M3.5 8.5l3 3 6-7" /></svg>
              </span>
              <span class="label">
                {{ option.label }}
                @if (option.hint) {
                  <span class="hint">{{ option.hint }}</span>
                }
              </span>
            </button>
          </li>
        } @empty {
          <li class="none">Nothing matches “{{ query() }}”.</li>
        }
      </ul>

      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="picked.set([])">Clear</button>
        <button mfButton (click)="done()">Done</button>
      </ng-container>
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
      box-shadow: inset 0 0 0 1px var(--border);
      color: var(--text);
      font: inherit;
      font-size: var(--font-size-base);
      text-align: left;
      cursor: pointer;
    }

    .trigger.empty .text {
      color: var(--text-muted);
    }

    .text {
      flex: 1;
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .chevron {
      width: 0.5rem;
      height: 0.5rem;
      border-right: 2px solid var(--text-subtle);
      border-bottom: 2px solid var(--text-subtle);
      transform: rotate(45deg) translateY(-2px);
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
      height: 44px;
      padding: 0 var(--space-4);
      border: 0;
      border-radius: var(--radius-full);
      background: var(--surface-inset);
      box-shadow: inset 0 0 0 1px var(--border);
      color: var(--text);
      font: inherit;
      font-size: var(--font-size-base);
      outline: none;
    }

    .options {
      display: grid;
      gap: 2px;
      margin: 0 calc(var(--space-2) * -1);
      padding: 0;
      list-style: none;
    }

    .option {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      width: 100%;
      min-height: 52px;
      padding: var(--space-2) var(--space-3);
      border: 0;
      border-radius: var(--radius-control);
      background: transparent;
      color: var(--text);
      font: inherit;
      text-align: left;
      cursor: pointer;
    }

    .option:active {
      background: var(--surface-hover);
    }

    .option:disabled {
      opacity: 0.45;
    }

    .box {
      flex: none;
      display: grid;
      place-items: center;
      width: 22px;
      height: 22px;
      border-radius: var(--radius-sm);
      background: var(--surface-inset);
      box-shadow: inset 0 0 0 1.5px var(--border-strong);
    }

    svg {
      width: 14px;
      height: 14px;
      fill: none;
      stroke: var(--on-primary);
      stroke-width: 2.4;
      stroke-linecap: round;
      stroke-linejoin: round;
      opacity: 0;
    }

    .option.on .box {
      background: var(--primary);
      box-shadow: none;
    }

    .option.on svg {
      opacity: 1;
    }

    .label {
      display: grid;
      gap: 2px;
      font-weight: var(--font-weight-medium);
    }

    .hint {
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-regular);
      color: var(--text-muted);
    }

    .none {
      padding: var(--space-4) var(--space-3);
      color: var(--text-muted);
    }
  `,
})
export class MfMultiSelect {
  readonly options = input.required<MfOption[]>();
  readonly heading = input.required<string>();
  readonly subheading = input<string | null>(null);
  /** What the trigger says when nothing is chosen. */
  readonly emptyLabel = input('None chosen');
  readonly disabled = input(false, { transform: booleanAttribute });

  readonly value = model<string[]>([]);

  protected readonly open = signal(false);
  protected readonly query = signal('');

  /** Chosen inside the sheet, kept apart until Done so Cancel-by-dragging changes nothing. */
  protected readonly picked = signal<string[]>([]);

  protected readonly shown = computed(() => {
    const q = this.query().trim().toLowerCase();

    if (!q) return this.options();

    return this.options().filter((o) => `${o.label} ${o.hint ?? ''}`.toLowerCase().includes(q));
  });

  protected readonly summary = computed(() => {
    const chosen = this.options().filter((o) => this.value().includes(o.value));

    if (chosen.length === 0) return this.emptyLabel();
    if (chosen.length <= 2) return chosen.map((o) => o.label).join(', ');

    return `${chosen.length} chosen`;
  });

  protected show(): void {
    if (this.disabled()) return;

    this.picked.set([...this.value()]);
    this.query.set('');
    this.open.set(true);
  }

  protected toggle(value: string): void {
    const now = this.picked();
    this.picked.set(now.includes(value) ? now.filter((v) => v !== value) : [...now, value]);
  }

  protected done(): void {
    this.value.set(this.picked());
    this.open.set(false);
  }
}
