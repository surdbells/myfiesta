import { Component, computed, inject, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { eventIdFrom } from '../../core/event-id';
import {
  ToastStore,
  UiBadge,
  UiButton,
  UiConfirm,
  UiEmpty,
  UiErrorState,
  UiField,
  UiIcon,
  UiModal,
  UiSelect,
  UiSkeleton,
  type SelectOption,
} from '@myfiesta/ui';
import { ChevronDown, ChevronUp, Pencil, Plus, Trash2 } from 'lucide-angular';
import { Api } from '../../core/api';
import { EventWaitlist } from './event-waitlist';
import { Money, TicketType } from '../../core/api.types';
import { amountProblem, formatMoney, toMajorUnits, toMinorUnits } from '../../core/money';
import { describeZone, isoToZonedWallClock, localZone, zonedWallClockToIso } from '../../core/zoned-time';

/** A ticket type as the form holds it, before it becomes an API body. */
interface TicketDraft {
  name: string;
  description: string;
  /** Major units, because that is what somebody types. */
  price: string;
  admits: number | string;
  /**
   * Held as whatever the control handed back.
   *
   * A number input under ngModel yields a number, and an empty one yields
   * null — not the empty string a text input would give. Typing these as
   * string and calling .trim() on them threw inside the save handler,
   * which produced the worst possible symptom: no request, no error, and a
   * button that did nothing at all.
   */
  quantity: number | string | null;
  maxPerOrder: number | string | null;
  salesStart: string;
  salesEnd: string;
  /** The tier this one waits for; empty for on sale straight away. */
  opensAfter: string;
  /**
   * Whether the tier is being offered.
   *
   * 'sold_out' is deliberately not settable: it is a fact the server works
   * out from the count, not a state somebody chooses. Offering it here would
   * let an organizer mark a tier sold out while places remain, which is a
   * different thing — that is what closing it is for.
   */
  status: 'on_sale' | 'hidden' | 'closed';
}

/**
 * Ticket types: what is on sale, at what price, and how it is going.
 *
 * This screen did not exist. The API has had full CRUD for ticket types since
 * the first migration and the console had no way to reach it — an organizer
 * could create an event and then could not put anything on sale, which makes
 * "published with nothing on sale" less an oversight than the only outcome
 * available to them.
 *
 * The table leads with how each tier is selling rather than with its price.
 * Price is a thing that was decided once; sold-against-capacity is the thing
 * being checked, and it is why somebody opened this tab.
 */
@Component({
  selector: 'app-event-tickets',
  imports: [
    FormsModule,
    UiButton,
    UiBadge,
    UiField,
    UiSelect,
    UiModal,
    UiConfirm,
    UiEmpty,
    UiErrorState,
    UiSkeleton,
    UiIcon,
    EventWaitlist,
  ],
  templateUrl: './event-tickets.html',
})
export class EventTickets {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  private readonly route = inject(ActivatedRoute);

  protected readonly addIcon = Plus;
  protected readonly editIcon = Pencil;
  protected readonly deleteIcon = Trash2;
  protected readonly upIcon = ChevronUp;
  protected readonly downIcon = ChevronDown;

  // The id lives on the parent route: this screen is a child of the workspace.
  readonly eventId = eventIdFrom(this.route);

  readonly types = signal<TicketType[]>([]);

  /**
   * The zone sale windows are typed and read in: the event's, not the
   * browser's. An organizer in Lagos opening a Toronto night's tier at "10am"
   * means 10am in Toronto — the time on every poster for it.
   */
  readonly timezone = signal<string | null>(null);
  readonly zoneName = computed(() => describeZone(this.zone()));

  private readonly waitlist = viewChild(EventWaitlist);
  readonly loading = signal(true);
  readonly failed = signal(false);

  /** The type being edited, or null for a new one. Drives the same form. */
  readonly editing = signal<TicketType | null>(null);
  readonly formOpen = signal(false);
  readonly saving = signal(false);
  readonly formError = signal<string | null>(null);

  readonly removing = signal<TicketType | null>(null);

  readonly draft = signal<TicketDraft>(this.blank());

  constructor() {
    this.load();

    this.api.event(this.eventId).subscribe({
      next: (event) => this.timezone.set(event.timezone),
      error: () => undefined,
    });
  }

  private zone(): string {
    return this.timezone() ?? localZone();
  }

  load(): void {
    this.loading.set(true);
    this.failed.set(false);

    this.api.ticketTypes(this.eventId).subscribe({
      next: ({ data }) => {
        this.types.set(data);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.failed.set(true);
      },
    });
  }

  // --- totals across the tiers ---------------------------------------------

  readonly totalSold = computed(() =>
    this.types().reduce((sum, type) => sum + (type.sold ?? 0), 0),
  );

  /**
   * The capacity of the whole event, or null.
   *
   * One unlimited tier makes the total unknowable, and a number that quietly
   * omits it is the number somebody plans a door around.
   */
  readonly totalCapacity = computed<number | null>(() => {
    const types = this.types();

    if (types.some((type) => type.quantity_available == null)) return null;

    return types.reduce((sum, type) => sum + (type.quantity_available ?? 0), 0);
  });

  /**
   * What has been taken, at face value.
   *
   * Deliberately not called revenue: it is price times tickets issued, which
   * counts comps at zero and knows nothing about discounts or refunds. The
   * orders screen is where the money is.
   */
  readonly faceValue = computed<Money | null>(() => {
    const types = this.types();

    if (types.length === 0) return null;

    return {
      amount: types.reduce((sum, type) => sum + type.price.amount * (type.sold ?? 0), 0),
      currency: types[0].price.currency,
    };
  });

  // --- the order they are offered in ---------------------------------------

  /**
   * The order here is the order a buyer sees, which is a selling decision:
   * Early Bird above General reads as a deadline, the other way round reads
   * as a list.
   *
   * Up and down rather than dragging. A tier row is a wide three-column grid
   * carrying two buttons and a meter, and dragging one of those by anywhere
   * that is not a control is a much worse target than two arrows — unlike the
   * gallery, where the tile itself is the handle.
   */
  readonly savingOrder = signal(false);

  move(type: TicketType, direction: -1 | 1): void {
    const order = this.types().map((t) => t.id);
    const from = order.indexOf(type.id);
    const to = from + direction;

    if (from < 0 || to < 0 || to >= order.length) return;

    [order[from], order[to]] = [order[to], order[from]];

    // Shown before the server agrees. A row that does not move when the
    // arrow is pressed reads as broken; a failure reloads to the truth.
    const by = new Map(this.types().map((t) => [t.id, t]));
    this.types.set(order.map((id) => by.get(id)!).filter(Boolean));

    this.savingOrder.set(true);

    this.api.reorderTicketTypes(this.eventId, order).subscribe({
      next: ({ data }) => {
        this.savingOrder.set(false);
        this.types.set(data);
      },
      error: () => {
        this.savingOrder.set(false);
        this.toasts.show('That order could not be saved.', 'danger');
        this.load();
      },
    });
  }

  canMove(type: TicketType, direction: -1 | 1): boolean {
    const at = this.types().findIndex((t) => t.id === type.id);

    return at >= 0 && at + direction >= 0 && at + direction < this.types().length;
  }

  // --- the form ------------------------------------------------------------

  private blank(): TicketDraft {
    return {
      name: '',
      description: '',
      price: '',
      admits: 1,
      quantity: '',
      maxPerOrder: '',
      salesStart: '',
      salesEnd: '',
      opensAfter: '',
      status: 'on_sale',
    };
  }

  openNew(): void {
    this.editing.set(null);
    this.draft.set(this.blank());
    this.formError.set(null);
    this.formOpen.set(true);
  }

  openEdit(type: TicketType): void {
    this.editing.set(type);
    this.formError.set(null);
    this.draft.set({
      name: type.name,
      description: type.description ?? '',
      price: String(toMajorUnits(type.price.amount)),
      admits: type.admits,
      quantity: type.quantity_available ?? '',
      maxPerOrder: type.max_per_order ?? '',
      salesStart: this.toLocalInput(type.sales_start_at),
      salesEnd: this.toLocalInput(type.sales_end_at),
      opensAfter: type.opens_after?.id ?? '',
      // A tier the server has worked out is sold out still edits as what it
      // is: on sale, with nothing left. Closing it is a separate decision.
      status: type.status === 'sold_out' ? 'on_sale' : type.status,
    });
    this.formOpen.set(true);
  }

  update<K extends keyof TicketDraft>(key: K, value: TicketDraft[K]): void {
    this.draft.set({ ...this.draft(), [key]: value });
  }

  /** Read the way it was typed: "5,000" is five thousand, and "5,00" is a question. */
  readonly priceError = computed(() => amountProblem(this.draft().price));

  /** What the chosen availability actually means, said under the control. */
  readonly statusOptions: SelectOption[] = [
    { value: 'on_sale', label: 'On sale — anybody can buy it' },
    { value: 'hidden', label: 'Hidden — only with a presale code' },
    { value: 'closed', label: 'Closed — not for sale' },
  ];

  /** Every other tier, as a ladder step this one can wait for. */
  readonly ladderOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'Straight away' },
    ...this.types()
      .filter((t) => t.id !== this.editing()?.id)
      .map((t) => ({ value: t.id, label: `When ${t.name} sells out`, hint: this.cash(t.price) })),
  ]);

  readonly statusHint = computed(() => {
    const status = this.draft().status;

    if (status === 'hidden') {
      // It used to promise "a direct link", which did not exist: nobody could
      // buy a hidden tier at all. A code that unlocks it is now the way in.
      return 'Off the event page. Only buyers with a code that unlocks it can see and buy it — make one under Codes.';
    }

    if (status === 'closed') {
      return 'It shows on the event page as unavailable. Tickets already sold still work.';
    }

    return 'Shown on the event page and open for sale, within any dates set above.';
  });

  readonly canSave = computed(
    () => this.draft().name.trim() !== '' && toMinorUnits(this.draft().price) !== null,
  );

  save(): void {
    if (!this.canSave() || this.saving()) return;

    const draft = this.draft();
    const editing = this.editing();

    const body: Record<string, unknown> = {
      name: draft.name.trim(),
      description: draft.description.trim() || null,
      price_amount: toMinorUnits(draft.price),
      admits: Number(draft.admits) || 1,
      // An empty box means unlimited, which is null and not zero. Zero would
      // put the tier on sale with nothing behind it.
      quantity_available: this.optionalNumber(draft.quantity),
      max_per_order: this.optionalNumber(draft.maxPerOrder),
      sales_start_at: draft.salesStart ? zonedWallClockToIso(draft.salesStart, this.zone()) : null,
      sales_end_at: draft.salesEnd ? zonedWallClockToIso(draft.salesEnd, this.zone()) : null,
      opens_after_id: draft.opensAfter || null,
      status: draft.status,
    };

    this.saving.set(true);
    this.formError.set(null);

    const request = editing
      ? this.api.updateTicketType(this.eventId, editing.id, body)
      : this.api.createTicketType(this.eventId, body);

    request.subscribe({
      next: () => {
        this.saving.set(false);
        this.formOpen.set(false);
        this.toasts.show(editing ? 'Ticket type saved.' : 'Ticket type added.');
        this.load();
        // A tier reopened or given places may be what the waitlist is waiting for.
        this.waitlist()?.load();
      },
      error: (error) => {
        this.saving.set(false);
        // The server's own words where it gave any: it knows about clashing
        // sale windows and price ceilings, and a generic message would send
        // somebody guessing at which field it meant.
        this.formError.set(
          error?.error?.message ?? 'That could not be saved. Check the figures and try again.',
        );
      },
    });
  }

  /**
   * An optional numeric field, whatever the control gave back.
   *
   * Empty means unlimited, which is null and never zero — zero would put a
   * tier on sale with nothing behind it.
   */
  private optionalNumber(value: number | string | null): number | null {
    if (value === null || value === undefined || String(value).trim() === '') return null;

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
  }

  // --- removal -------------------------------------------------------------

  /**
   * What deleting this would actually do.
   *
   * A tier that has sold is closed rather than removed — the tickets stay
   * valid and the record of what was charged has to survive — and the confirm
   * dialog has to say so rather than promise a deletion that will not happen.
   */
  consequence(type: TicketType): string {
    return (type.sold ?? 0) > 0
      ? `${type.sold} of these have sold, so it will be closed rather than deleted. Those tickets stay valid and can still be scanned.`
      : 'Nothing has sold on this tier, so it will be removed entirely.';
  }

  confirmRemove(): void {
    const type = this.removing();

    if (!type) return;

    this.api.deleteTicketType(this.eventId, type.id).subscribe({
      next: ({ message }) => {
        this.removing.set(null);
        this.toasts.show(message);
        this.load();
      },
      error: () => {
        this.removing.set(null);
        this.toasts.show('That could not be removed.', 'danger');
      },
    });
  }

  // --- display -------------------------------------------------------------

  cash(money: Money): string {
    return formatMoney(money);
  }

  tone(type: TicketType): 'success' | 'neutral' | 'warning' | 'danger' {
    if (type.status === 'on_sale' && type.sold_out) return 'danger';
    if (type.status === 'on_sale' && type.waiting) return 'neutral';

    return type.status === 'on_sale'
      ? 'success'
      : type.status === 'sold_out'
        ? 'danger'
        : type.status === 'hidden'
          ? 'warning'
          : 'neutral';
  }

  label(type: TicketType): string {
    if (type.status === 'on_sale' && type.sold_out) return 'Sold out';
    if (type.status === 'on_sale' && type.waiting) return 'Waiting';

    return { on_sale: 'On sale', sold_out: 'Sold out', hidden: 'Hidden', closed: 'Closed' }[
      type.status
    ];
  }

  /**
   * Percentage sold, or null where there is no capacity to measure against.
   *
   * Read with an explicit null check at the call site, never as a truthy
   * test: a tier that has sold nothing returns 0, and 0 is falsy — which
   * hid the track on exactly the tiers somebody is checking on.
   */
  percent(type: TicketType): number | null {
    if (type.quantity_available == null || type.quantity_available === 0) return null;

    return Math.min(100, Math.round(((type.sold ?? 0) / type.quantity_available) * 100));
  }

  window(type: TicketType): string | null {
    if (!type.sales_start_at && !type.sales_end_at) return null;

    const on = (iso: string) =>
      new Intl.DateTimeFormat('en-CA', { day: 'numeric', month: 'short', timeZone: this.zone() }).format(new Date(iso));

    if (type.sales_start_at && type.sales_end_at) {
      return `${on(type.sales_start_at)} – ${on(type.sales_end_at)}`;
    }

    return type.sales_start_at ? `From ${on(type.sales_start_at)}` : `Until ${on(type.sales_end_at!)}`;
  }

  /**
   * An ISO instant, as `datetime-local` wants it: the wall clock at the
   * event, since that control has no zone of its own and the save reads it
   * back in the event's.
   */
  private toLocalInput(iso: string | null | undefined): string {
    return iso ? (isoToZonedWallClock(iso, this.zone()) ?? '') : '';
  }
}
