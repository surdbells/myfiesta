import { Component, ElementRef, input, viewChild } from '@angular/core';
import { Columns3 } from 'lucide-angular';
import { UiIcon } from './icon';
import { Popover, POPOVER_PANEL_STYLES } from './popover';
import type { Density, FilterDef, ListState } from './list-state';

let sequence = 0;

/**
 * Which columns a table shows, and how tightly its rows sit.
 *
 * Both remembered by this browser for this list (ListState). The column that
 * names the row cannot be hidden — a table of totals with no way of telling
 * whose they are is not a smaller table, it is a broken one.
 */
@Component({
  selector: 'ui-column-menu',
  imports: [UiIcon],
  template: `
    <button
      #trigger
      type="button"
      class="menu__button"
      aria-haspopup="true"
      [attr.aria-expanded]="menu.open()"
      [attr.aria-controls]="panelId"
      (click)="menu.toggle()"
    >
      <ui-icon [icon]="icon" size="sm" />
      <span>Columns</span>
    </button>

    <div
      #panel
      class="menu__panel"
      popover="auto"
      role="group"
      aria-label="Columns and density"
      [id]="panelId"
      [class.is-open]="menu.open()"
      [style.top.px]="menu.position().top"
      [style.left.px]="menu.position().left"
      [style.width.px]="260"
      (toggle)="menu.onToggle($event)"
    >
      <p class="menu__heading">Show</p>
      @for (column of state().columns; track column.id) {
        <label class="menu__item" [class.is-locked]="column.required">
          <input
            type="checkbox"
            class="check"
            [checked]="state().isVisible(column.id)"
            [disabled]="column.required"
            (change)="state().toggleColumn(column.id)"
          />
          <span>{{ column.label }}</span>
        </label>
      }
      <button type="button" class="menu__item menu__reset" (click)="state().resetColumns()">Show the usual columns</button>

      <hr class="menu__rule" />

      <p class="menu__heading" id="{{ panelId }}-density">Rows</p>
      <div class="density" role="radiogroup" [attr.aria-labelledby]="panelId + '-density'">
        @for (option of densities; track option.value) {
          <button
            type="button"
            role="radio"
            class="density__option"
            [attr.aria-checked]="state().density() === option.value"
            (click)="state().setDensity(option.value)"
          >
            {{ option.label }}
          </button>
        }
      </div>
    </div>
  `,
  styles: `
    :host { display: inline-block; }
    .menu__button {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      height: 44px;
      padding: 0 var(--space-4);
      font: inherit;
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
      color: var(--text);
      background: var(--surface-raised);
      border: 1px solid var(--field-border);
      border-radius: var(--radius-control);
      cursor: pointer;
    }
    .menu__button:hover { background: var(--surface-hover); }
    .menu__button[aria-expanded='true'] { border-color: var(--primary); box-shadow: var(--focus-ring); }
    ${POPOVER_PANEL_STYLES}
    .menu__item.is-locked { color: var(--text-muted); cursor: default; }
    .menu__reset { color: var(--primary-text); font-weight: var(--font-weight-medium); }
    .density {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 2px;
      margin: 0 var(--space-2) var(--space-2);
      padding: 2px;
      background: var(--surface-inset);
      border-radius: var(--radius-control);
    }
    .density__option {
      height: 32px;
      font: inherit;
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      background: transparent;
      border: 0;
      border-radius: var(--radius-sm);
      cursor: pointer;
    }
    .density__option[aria-checked='true'] {
      color: var(--text);
      font-weight: var(--font-weight-semibold);
      background: var(--surface-raised);
      box-shadow: var(--shadow-card);
    }
  `,
})
export class UiColumnMenu {
  readonly state = input.required<ListState<Record<string, FilterDef>>>();

  private readonly trigger = viewChild<ElementRef<HTMLElement>>('trigger');
  private readonly panel = viewChild<ElementRef<HTMLElement>>('panel');

  protected readonly menu = new Popover(
    () => this.trigger(),
    () => this.panel(),
    260,
  );

  protected readonly icon = Columns3;
  protected readonly panelId = `ui-columns-${++sequence}`;

  protected readonly densities: readonly { value: Density; label: string }[] = [
    { value: 'comfortable', label: 'Comfortable' },
    { value: 'compact', label: 'Compact' },
  ];
}

