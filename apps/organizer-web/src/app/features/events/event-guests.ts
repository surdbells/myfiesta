import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import {
  ConfirmDialog,
  Selection,
  ToastStore,
  UiBulkBar,
  UiButton,
  UiColumnMenu,
  UiEmpty,
  UiErrorState,
  UiFilterBar,
  UiIcon,
  UiPagination,
  UiScrollRegion,
  UiSelect,
  UiSortHeader,
  UiTable,
  createListState,
  type FilterChip,
  type SelectOption,
} from '@myfiesta/ui';
import { Download } from 'lucide-angular';
import { saveFile, today } from '../../core/download';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { eventIdFrom } from '../../core/event-id';
import { Api } from '../../core/api';
import { Guest, PageMeta, TicketType } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { loadList, searchBox } from '../../core/list-loader';
import { SessionStore } from '../../core/session';
import { SavedViews } from '../../shared/saved-views';

/**
 * Who is coming, who has arrived, and putting somebody on the list.
 *
 * The screen an organizer keeps open all night, so arrivals are the number that
 * leads and the search is the thing that works fastest — a name gets typed here
 * under time pressure with somebody waiting at the door.
 */
@Component({
  selector: 'app-event-guests',
  imports: [
    FormsModule,
    SavedViews,
    UiBulkBar,
    UiButton,
    UiColumnMenu,
    UiEmpty,
    UiErrorState,
    UiFilterBar,
    UiIcon,
    UiPagination,
    UiScrollRegion,
    UiSelect,
    UiSortHeader,
    UiTable,
  ],
  templateUrl: './event-guests.html',
})
export class EventGuests {
  protected readonly downloadIcon = Download;
  private readonly toasts = inject(ToastStore);
  private readonly confirmDialog = inject(ConfirmDialog);
  readonly exporting = signal(false);

  /**
   * The whole guest list as a spreadsheet, alphabetical — to print for a door
   * or send to a venue. No ticket codes: a forwarded list must not be a set of
   * working tickets.
   */
  /** The list as filtered and sorted — or only the ticked guests. */
  exportList(onlySelected = false): void {
    if (this.exporting()) return;

    this.exporting.set(true);

    const sort = this.list.sort();
    const query = onlySelected ? { ids: this.selection.ids(), sort: sort?.column, dir: sort?.direction } : this.list.criteria();

    this.api.exportGuests(this.eventId, query).subscribe({
      next: (file) => {
        this.exporting.set(false);
        saveFile(file, `guest-list-${today()}.csv`);
      },
      error: () => {
        this.exporting.set(false);
        this.toasts.show('The guest list could not be downloaded. Try again.', 'danger');
      },
    });
  }

  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  readonly session = inject(SessionStore);

  readonly eventId = eventIdFrom(this.route);

  readonly list = createListState({
    list: 'guests',
    filters: {
      q: { kind: 'text' },
      status: { kind: 'one' },
      ticket_type_id: { kind: 'many' },
    },
    sort: { column: 'name', direction: 'asc' },
    columns: [
      { id: 'name', label: 'Guest', required: true },
      { id: 'ticket', label: 'Ticket' },
      { id: 'answers', label: 'Answers' },
      { id: 'arrival', label: 'Arrival' },
    ],
  });

  readonly search = searchBox(this.list, 'q');
  readonly selection = new Selection();

  readonly page = loadList(this.list.query, () => this.api.guests(this.eventId, this.list.query()));

  readonly guests = computed<Guest[]>(() => this.page.result()?.data ?? []);
  readonly meta = computed<PageMeta | null>(() => this.page.result()?.meta ?? null);
  readonly loading = computed(() => this.page.loading() && this.page.result() === null);
  readonly refreshing = computed(() => this.page.loading() && this.page.result() !== null);
  readonly failed = this.page.failed;
  readonly rowIds = computed(() => this.guests().map((guest) => guest.id));

  /**
   * The whole list's size, for "12 of 80 arrived". A filter narrows the rows,
   * not the room — taking a filtered count here read as 12 of 3 — so it is
   * only taken from an unfiltered answer.
   */
  readonly total = signal(0);
  readonly arrived = computed(() => this.page.result()?.meta.checked_in ?? 0);

  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);

  readonly filtered = computed(() => this.list.active() > 0);

  readonly statusOptions: SelectOption[] = [
    { value: '', label: 'Everyone' },
    { value: 'checked_in', label: 'Arrived' },
    { value: 'valid', label: 'Not arrived yet' },
  ];

  readonly ticketTypes = signal<TicketType[]>([]);

  /** For filtering, which needs an "any" row the issue form does not. */
  readonly tierOptions = computed<SelectOption[]>(() => this.ticketTypes().map((type) => ({ value: type.id, label: type.name })));

  readonly ticketTypeOptions = computed<SelectOption[]>(() =>
    this.ticketTypes().map((type) => ({
      value: type.id,
      label: type.name,
      hint: type.admits > 1 ? `Admits ${type.admits}` : undefined,
    })),
  );
  readonly issuing = signal(false);
  readonly issue = signal({
    ticket_type_id: '',
    name: '',
    email: '',
    quantity: '1',
    note: '',
    send_email: true,
  });

  constructor() {
    effect(() => {
      const result = this.page.result();
      if (result && this.list.active() === 0) untracked(() => this.total.set(result.meta.total));
    });

    // A different set of rows makes the ticks meaningless.
    effect(() => {
      this.list.criteria();
      untracked(() => this.selection.clear());
    });

    effect(() => {
      const ids = this.rowIds();
      untracked(() => this.selection.keep(ids));
    });

    this.api.ticketTypes(this.eventId).subscribe({
      next: ({ data }) => {
        this.ticketTypes.set(data);

        // Preselected so the common case — one type, one guest — is two fields
        // and a button rather than a form to work through.
        if (data.length > 0) {
          this.issue.set({ ...this.issue(), ticket_type_id: data[0].id });
        }
      },
      error: () => undefined,
    });
  }

  /** After a failure: the same question again. */
  load(): void {
    this.page.retry();
  }

  readonly chips = computed<FilterChip[]>(() => {
    const chips: FilterChip[] = [];
    const values = this.list.values();

    if (values.q) chips.push({ key: 'q', label: 'Search', value: String(values.q) });

    if (values.status) {
      const option = this.statusOptions.find((o) => o.value === values.status);
      chips.push({ key: 'status', label: 'Showing', value: option?.label ?? String(values.status) });
    }

    const tiers = values.ticket_type_id as readonly string[];
    if (tiers.length > 0) {
      const names = tiers.map((id) => this.ticketTypes().find((t) => t.id === id)?.name ?? 'One tier');
      chips.push({ key: 'ticket_type_id', label: tiers.length === 1 ? 'Tier' : 'Tiers', value: names.join(', ') });
    }

    return chips;
  });

  readonly summary = computed(() => {
    const meta = this.meta();
    if (!meta) return null;

    const noun = meta.total === 1 ? 'guest' : 'guests';
    const shown = Math.min(meta.per_page, this.guests().length);

    return meta.total > shown
      ? `Showing ${shown} of ${meta.total.toLocaleString()} ${noun}`
      : `${meta.total.toLocaleString()} ${noun}`;
  });

  remove(key: string): void {
    this.list.clear(key as 'q');
  }

  /** When somebody arrived, in the reader's own time. */
  arrivedAt(iso: string | null): string {
    if (!iso) return '';

    return new Intl.DateTimeFormat('en-CA', { hour: 'numeric', minute: '2-digit', day: 'numeric', month: 'short' }).format(new Date(iso));
  }

  async submitIssue(): Promise<void> {
    const form = this.issue();

    if (!form.name.trim() || !form.email.trim() || !form.ticket_type_id || this.issuing()) return;

    const quantity = Number(form.quantity) || 1;
    const tickets = `${quantity} ${this.ticketTypes().find((t) => t.id === form.ticket_type_id)?.name ?? ''} ${quantity === 1 ? 'ticket' : 'tickets'}`.replace(/\s+/g, ' ');

    // A guest ticket gets somebody in for nothing and takes a place from what
    // is for sale, so who, how many and whether an email goes are said back.
    const sure = await this.confirmDialog.confirm({
      title: `Issue ${tickets} to ${form.name.trim()}?`,
      body: form.send_email
        ? `They are emailed to ${form.email.trim()} straight away, and work at the door like any other.`
        : `Nothing is sent to ${form.email.trim()} now. The ${quantity === 1 ? 'ticket goes' : 'tickets go'} on the guest list under ${form.name.trim()}.`,
      consequences: ['They are free, and count against what is left to sell.'],
      confirmLabel: quantity === 1 ? 'Issue the ticket' : `Issue ${quantity} tickets`,
      tone: 'default',
    });

    if (!sure || this.issuing()) return;

    this.issuing.set(true);
    this.error.set(null);
    this.notice.set(null);

    this.api
      .issueTicket(this.eventId, {
        ticket_type_id: form.ticket_type_id,
        name: form.name.trim(),
        email: form.email.trim(),
        quantity,
        note: form.note.trim() || null,
        send_email: form.send_email,
      })
      .subscribe({
        next: (result) => {
          this.issuing.set(false);
          this.notice.set(
            form.send_email
              ? `${result.message} Emailed to ${form.email.trim()}.`
              : `${result.message} Nothing was sent — the code is on the list below.`,
          );

          this.issue.set({ ...this.issue(), name: '', email: '', quantity: '1', note: '' });
          this.page.retry();
        },
        error: (response) => {
          this.issuing.set(false);
          this.error.set(messageFor(response, 'That ticket could not be issued.'));
        },
      });
  }

  /** Proportion arrived, for the bar. Null before anyone is expected. */
  arrivalRate(): number | null {
    return this.total() === 0 ? null : Math.round((this.arrived() / this.total()) * 100);
  }
}
