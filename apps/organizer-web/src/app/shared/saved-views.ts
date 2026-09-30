import { Component, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { tap } from 'rxjs';
import { ConfirmDialog, ToastStore, UiSavedViews, type FilterDef, type ListState, type ViewChoice } from '@myfiesta/ui';
import { Api } from '../core/api';
import type { SavedView, SavedViewList } from '../core/api.types';
import { messageFor } from '../core/errors';
import { SessionStore } from '../core/session';

/**
 * A list's saved views, kept on the server for this person and organization.
 *
 * The one place the console talks to /saved-views: a screen drops this into
 * its filter bar with its ListState and the list's name, and the views load,
 * apply, save and delete the same way on every list. Which one is "on" is
 * worked out, not remembered — the view whose filters, sort and columns are
 * what the list shows now — so changing a filter by hand quietly stops
 * claiming to be "Refunds this week".
 */
@Component({
  selector: 'app-saved-views',
  imports: [UiSavedViews],
  template: `
    <ui-saved-views
      [views]="choices()"
      [activeId]="activeId()"
      [loading]="loading()"
      [saving]="saving()"
      [example]="example()"
      (applied)="apply($event)"
      (saved)="save($event)"
      (deleted)="remove($event)"
    />
  `,
})
export class SavedViews {
  readonly list = input.required<SavedViewList>();
  readonly state = input.required<ListState<Record<string, FilterDef>>>();

  private readonly api = inject(Api);
  private readonly session = inject(SessionStore);
  private readonly toasts = inject(ToastStore);
  private readonly confirmDialog = inject(ConfirmDialog);

  private readonly views = signal<SavedView[]>([]);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);

  /** A name somebody might give a view of this list, in its empty box. */
  protected readonly example = computed(() => EXAMPLES[this.list()]);

  protected readonly choices = computed<ViewChoice[]>(() => this.views().map(({ id, name }) => ({ id, name })));

  protected readonly activeId = computed(() => {
    const now = canonical(this.state().snapshot());
    return this.views().find((view) => canonical(view.state) === now)?.id ?? null;
  });

  constructor() {
    // Loaded for the list, and again when the organization changes: views
    // are per organization.
    effect(() => {
      const list = this.list();
      this.session.current();
      untracked(() => this.load(list));
    });
  }

  private load(list: SavedViewList): void {
    this.loading.set(true);

    this.api.savedViews(list).subscribe({
      next: ({ data }) => {
        this.views.set(data);
        this.loading.set(false);
      },
      // The list works without its views; say nothing more than that they
      // are not there.
      error: () => {
        this.views.set([]);
        this.loading.set(false);
      },
    });
  }

  protected apply(choice: ViewChoice): void {
    const view = this.views().find((v) => v.id === choice.id);
    if (view) this.state().apply(view.state);
  }

  /**
   * Keep what the list shows now under a name — asked first, like everything
   * that writes on the server, and saying so when it replaces a view of the
   * same name, whose filters are then gone.
   */
  protected async save(name: string): Promise<void> {
    const replacing = this.views().find((v) => v.name.toLowerCase() === name.toLowerCase());
    let saved: SavedView | null = null;

    this.saving.set(true);

    const done = await this.confirmDialog.confirm({
      title: replacing ? `Replace the view “${replacing.name}”?` : `Keep this view as “${name}”?`,
      body: replacing
        ? 'It keeps what the list shows now — its filters, sort and columns — in place of what it kept before.'
        : 'What the list shows now — its filters, sort and columns — is kept under Views on this list, for you in this organization.',
      confirmLabel: replacing ? 'Replace the view' : 'Keep the view',
      busyLabel: 'Keeping it…',
      tone: 'default',
      run: () => this.api.saveView(this.list(), name, this.state().snapshot()).pipe(tap(({ data }) => (saved = data))),
      failure: (error) => messageFor(error, 'The view could not be saved.'),
    });

    this.saving.set(false);

    const view = saved as SavedView | null;
    if (!done || !view) return;

    this.views.set([...this.views().filter((v) => v.id !== view.id), view].sort((a, b) => a.name.localeCompare(b.name)));
    this.toasts.show(`Saved as “${view.name}”.`, 'success');
  }

  protected async remove(choice: ViewChoice): Promise<void> {
    const gone = await this.confirmDialog.confirm({
      title: `Delete the view “${choice.name}”?`,
      body: 'The list stays as it is; only the saved view goes.',
      confirmLabel: 'Delete the view',
      tone: 'danger',
      run: () => this.api.deleteSavedView(choice.id),
      failure: (error) => messageFor(error, 'The view could not be deleted.'),
    });

    if (gone) this.views.set(this.views().filter((v) => v.id !== choice.id));
  }
}

/** What somebody might call a view of each list: "Refunds this week" is not a view of events. */
const EXAMPLES: Record<SavedViewList, string> = {
  orders: 'Refunds this week',
  'event-orders': 'Refunds this week',
  codes: 'Promoter codes in use',
  'event-codes': 'Promoter codes in use',
  guests: 'Not arrived yet',
  campaigns: 'Scheduled to send',
  payouts: 'Paid this month',
  events: 'Toronto, next month',
};

/** The same state written the same way, whatever order its keys arrived in. */
function canonical(state: Readonly<Record<string, unknown>>): string {
  return JSON.stringify(
    Object.keys(state)
      .sort()
      .map((key) => {
        const value = state[key];
        return [key, Array.isArray(value) ? [...value].map(String).sort() : value];
      }),
  );
}
