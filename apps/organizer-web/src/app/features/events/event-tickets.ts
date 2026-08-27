import { Component, computed, inject, signal } from '@angular/core';
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
  UiSkeleton,
} from '@myfiesta/ui';
import { Pencil, Plus, Trash2 } from 'lucide-angular';
import { Api } from '../../core/api';
import { Money, TicketType } from '../../core/api.types';
import { formatMoney, toMajorUnits, toMinorUnits } from '../../core/money';

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
    UiModal,
    UiConfirm,
    UiEmpty,
    UiErrorState,
    UiSkeleton,
    UiIcon,
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

  // The id lives on the parent route: this screen is a child of the workspace.
  readonly eventId = eventIdFrom(this.route);

  readonly types = signal<TicketType[]>([]);
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
    });
    this.formOpen.set(true);
  }

  update<K extends keyof TicketDraft>(key: K, value: TicketDraft[K]): void {
    this.draft.set({ ...this.draft(), [key]: value });
  }

  readonly priceError = computed(() => {
    const price = this.draft().price.trim();

    if (price === '') return null;

    return Number.isFinite(Number(price)) && Number(price) >= 0
      ? null
      : 'Enter a price like 25 or 25.50.';
  });

  readonly canSave = computed(
    () => this.draft().name.trim() !== '' && this.draft().price.trim() !== '' && !this.priceError(),
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
      sales_start_at: draft.salesStart ? new Date(draft.salesStart).toISOString() : null,
      sales_end_at: draft.salesEnd ? new Date(draft.salesEnd).toISOString() : null,
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
    return type.status === 'on_sale'
      ? 'success'
      : type.status === 'sold_out'
        ? 'danger'
        : type.status === 'hidden'
          ? 'warning'
          : 'neutral';
  }

  label(type: TicketType): string {
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
      new Intl.DateTimeFormat('en-CA', { day: 'numeric', month: 'short' }).format(new Date(iso));

    if (type.sales_start_at && type.sales_end_at) {
      return `${on(type.sales_start_at)} – ${on(type.sales_end_at)}`;
    }

    return type.sales_start_at ? `From ${on(type.sales_start_at)}` : `Until ${on(type.sales_end_at!)}`;
  }

  /**
   * An ISO instant, as `datetime-local` wants it.
   *
   * That control has no timezone and reads whatever it is given as local, so
   * the value has to be shifted into the browser's offset before it goes in
   * and back out again on save.
   */
  private toLocalInput(iso: string | null | undefined): string {
    if (!iso) return '';

    const date = new Date(iso);
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);

    return local.toISOString().slice(0, 16);
  }
}
