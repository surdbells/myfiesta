import { Component, ElementRef, computed, input, output, signal, viewChild } from '@angular/core';
import { Bookmark, Check, Trash2 } from 'lucide-angular';
import { UiIcon } from './icon';
import { Popover, POPOVER_PANEL_STYLES } from './popover';

/** A view as the menu shows it. */
export interface ViewChoice {
  readonly id: string;
  readonly name: string;
}

/**
 * A name to keep the list's view under, handed to the screen to ask about
 * and save.
 *
 * The screen calls `settle` once the question is answered: true when the view
 * was kept. Until then the name stays in the box — a question answered no, or
 * a save that failed, used to take the typed name with it.
 */
export interface ViewSave {
  readonly name: string;
  settle(kept: boolean): void;
}

/**
 * A list's saved views: pick one, keep the current one under a name, or let
 * one go.
 *
 * Presentational — the screen (or a wrapper that talks to the API) loads the
 * views, applies one to its ListState, saves and deletes, and says which one
 * is on. That keeps this package free of the API while every list offers
 * views the same way.
 */
@Component({
  selector: 'ui-saved-views',
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
      <ui-icon [icon]="bookmarkIcon" size="sm" />
      <span class="menu__label">{{ current()?.name ?? 'Views' }}</span>
    </button>

    <div
      #panel
      class="menu__panel"
      popover="auto"
      role="dialog"
      aria-label="Saved views"
      [id]="panelId"
      [class.is-open]="menu.open()"
      [style.top.px]="menu.position().top"
      [style.left.px]="menu.position().left"
      [style.width.px]="300"
      (toggle)="menu.onToggle($event)"
    >
      <p class="menu__heading">Saved views</p>

      @if (loading()) {
        <p class="views__note">Loading…</p>
      } @else {
        @for (view of views(); track view.id) {
          <div class="views__row">
            <button type="button" class="menu__item" [attr.aria-current]="view.id === activeId() || null" (click)="choose(view)">
              <span class="views__tick" aria-hidden="true">
                @if (view.id === activeId()) {
                  <ui-icon [icon]="checkIcon" size="sm" />
                }
              </span>
              <span class="views__name">{{ view.name }}</span>
            </button>
            <button type="button" class="views__delete" [attr.aria-label]="'Delete the view ' + view.name" (click)="deleted.emit(view)">
              <ui-icon [icon]="trashIcon" size="sm" />
            </button>
          </div>
        } @empty {
          <p class="views__note">None yet. Filter the list the way you read it, then keep it here.</p>
        }
      }

      <hr class="menu__rule" />

      <form class="views__save" (submit)="$event.preventDefault(); save()">
        <label class="menu__heading" [for]="panelId + '-name'">Keep this view as</label>
        <div class="views__field">
          <input
            #name
            type="text"
            maxlength="60"
            autocomplete="off"
            [placeholder]="example()"
            [id]="panelId + '-name'"
            [value]="draft()"
            (input)="draft.set($any($event.target).value)"
          />
          <button type="submit" class="views__keep" [disabled]="!draft().trim() || saving()">
            {{ replacing() ? 'Replace' : 'Save' }}
          </button>
        </div>
        @if (replacing()) {
          <p class="views__note">A view with this name exists; saving replaces it.</p>
        }
      </form>
    </div>
  `,
  styles: `
    :host { display: inline-block; }
    .menu__button {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      max-width: 16rem;
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
    .menu__label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    ${POPOVER_PANEL_STYLES}
    .views__row { display: flex; align-items: center; gap: var(--space-1); }
    .views__row .menu__item { flex: 1; min-width: 0; }
    .views__tick { display: grid; place-items: center; width: 16px; color: var(--primary-text); }
    .views__name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .menu__item[aria-current] { font-weight: var(--font-weight-semibold); }
    .views__delete {
      display: grid;
      place-items: center;
      width: 32px;
      height: 32px;
      padding: 0;
      color: var(--text-subtle);
      background: none;
      border: 0;
      border-radius: var(--radius-sm);
      cursor: pointer;
    }
    .views__delete:hover { color: var(--danger-text); background: var(--surface-hover); }
    .views__note { padding: var(--space-2); font-size: var(--font-size-xs); color: var(--text-muted); }
    .views__save { display: grid; gap: var(--space-1); }
    .views__field { display: flex; gap: var(--space-2); padding: 0 var(--space-2) var(--space-2); }
    .views__field input { height: 36px; }
    .views__keep {
      flex: none;
      height: 36px;
      padding: 0 var(--space-3);
      font: inherit;
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-semibold);
      color: var(--on-primary);
      background: var(--primary);
      border: 0;
      border-radius: var(--radius-control);
      cursor: pointer;
    }
    .views__keep:disabled { opacity: 0.55; cursor: not-allowed; }
  `,
})
export class UiSavedViews {
  readonly views = input.required<readonly ViewChoice[]>();
  /** The view whose filters are on, if the list still matches one. */
  readonly activeId = input<string | null>(null);
  readonly loading = input(false);
  readonly saving = input(false);
  /** A name somebody might give a view of this list, shown in the empty box. */
  readonly example = input('Refunds this week');

  readonly applied = output<ViewChoice>();
  readonly saved = output<ViewSave>();
  readonly deleted = output<ViewChoice>();

  private readonly trigger = viewChild<ElementRef<HTMLElement>>('trigger');
  private readonly panel = viewChild<ElementRef<HTMLElement>>('panel');
  private readonly nameBox = viewChild<ElementRef<HTMLInputElement>>('name');

  protected readonly menu = new Popover(
    () => this.trigger(),
    () => this.panel(),
    300,
  );

  protected readonly draft = signal('');
  protected readonly current = computed(() => this.views().find((v) => v.id === this.activeId()) ?? null);
  protected readonly replacing = computed(() => {
    const name = this.draft().trim().toLowerCase();
    return name !== '' && this.views().some((v) => v.name.toLowerCase() === name);
  });

  protected readonly bookmarkIcon = Bookmark;
  protected readonly checkIcon = Check;
  protected readonly trashIcon = Trash2;
  protected readonly panelId = `ui-views-${++sequence}`;

  protected choose(view: ViewChoice): void {
    this.applied.emit(view);
    this.menu.hide();
  }

  /**
   * Hand the name to the screen, and empty the box only once it is kept.
   *
   * The question is a modal dialog, and opening one closes every open menu —
   * this panel with it. Answered no, or failed, the panel comes back with the
   * name still in the box and the cursor in it, so trying again, or changing
   * the name, is where it was left.
   */
  protected save(): void {
    const name = this.draft().trim().replace(/\s+/g, ' ');
    if (!name || this.saving()) return;

    this.saved.emit({
      name,
      settle: (kept) => {
        if (kept) {
          this.draft.set('');
          return;
        }

        this.menu.show();
        this.nameBox()?.nativeElement.focus();
      },
    });
  }
}

let sequence = 0;
