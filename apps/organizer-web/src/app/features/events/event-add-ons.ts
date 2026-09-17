import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
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
import { AddOn } from '../../core/api.types';
import { eventIdFrom } from '../../core/event-id';
import { messageFor } from '../../core/errors';
import { formatMoney, toMajorUnits, toMinorUnits } from '../../core/money';

/** An add-on as the form holds it, before it becomes an API body. */
interface AddOnDraft {
  name: string;
  description: string;
  /** Major units, because that is what somebody types. */
  price: string;
  /** Held as whatever the number control handed back — null when emptied. */
  quantity: number | string | null;
  maxPerOrder: number | string | null;
  status: 'on_sale' | 'closed';
}

/**
 * What the event sells besides tickets.
 *
 * A table's two bottles, a cloakroom pass, a shirt. The rule that decides
 * whether something belongs here rather than on the tickets screen is on the
 * page, because it is the only thing anybody gets wrong: does somebody walk
 * through a door on it. A Table of 6 is a ticket type; the bottle on that
 * table is not.
 *
 * The row leads with how it is selling, like the tickets screen, because that
 * is the question this tab is opened with — twenty tables and three left is
 * the number an organizer is looking for.
 */
@Component({
  selector: 'app-event-add-ons',
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
  ],
  templateUrl: './event-add-ons.html',
})
export class EventAddOns {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  private readonly route = inject(ActivatedRoute);

  protected readonly addIcon = Plus;
  protected readonly editIcon = Pencil;
  protected readonly deleteIcon = Trash2;
  protected readonly upIcon = ChevronUp;
  protected readonly downIcon = ChevronDown;

  readonly eventId = eventIdFrom(this.route);

  readonly addOns = signal<AddOn[]>([]);
  readonly loading = signal(true);
  readonly failed = signal(false);

  readonly editing = signal<AddOn | null>(null);
  readonly formOpen = signal(false);
  readonly saving = signal(false);
  readonly formError = signal<string | null>(null);
  readonly savingOrder = signal(false);

  readonly removing = signal<AddOn | null>(null);

  readonly draft = signal<AddOnDraft>(this.blank());

  readonly statusOptions: SelectOption[] = [
    { value: 'on_sale', label: 'Offered at checkout' },
    { value: 'closed', label: 'Not offered' },
  ];

  readonly formatMoney = formatMoney;

  readonly canSave = computed(
    () => this.draft().name.trim().length >= 2 && toMinorUnits(this.draft().price) >= 0,
  );

  /** What has been taken on extras, at face value. */
  readonly takings = computed(() => {
    const addOns = this.addOns();

    if (addOns.length === 0) return null;

    return {
      amount: addOns.reduce((sum, addOn) => sum + addOn.price.amount * addOn.sold, 0),
      currency: addOns[0].price.currency,
    };
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.failed.set(false);

    this.api.addOns(this.eventId).subscribe({
      next: ({ data }) => {
        this.addOns.set(data);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.failed.set(true);
      },
    });
  }

  // --- the form -------------------------------------------------------------

  openNew(): void {
    this.editing.set(null);
    this.draft.set(this.blank());
    this.formError.set(null);
    this.formOpen.set(true);
  }

  edit(addOn: AddOn): void {
    this.editing.set(addOn);
    this.draft.set({
      name: addOn.name,
      description: addOn.description ?? '',
      price: String(toMajorUnits(addOn.price.amount)),
      quantity: addOn.quantity_available,
      maxPerOrder: addOn.max_per_order,
      status: addOn.status,
    });
    this.formError.set(null);
    this.formOpen.set(true);
  }

  update<K extends keyof AddOnDraft>(key: K, value: AddOnDraft[K]): void {
    this.draft.set({ ...this.draft(), [key]: value });
    this.formError.set(null);
  }

  save(): void {
    if (!this.canSave() || this.saving()) return;

    const draft = this.draft();
    const editing = this.editing();

    const body = {
      name: draft.name.trim(),
      description: draft.description.trim() || null,
      price_amount: toMinorUnits(draft.price),
      quantity_available: this.number(draft.quantity),
      max_per_order: this.number(draft.maxPerOrder),
      status: draft.status,
    };

    this.saving.set(true);
    this.formError.set(null);

    const request = editing
      ? this.api.updateAddOn(this.eventId, editing.id, body)
      : this.api.createAddOn(this.eventId, body);

    request.subscribe({
      next: ({ data }) => {
        this.saving.set(false);
        this.formOpen.set(false);

        this.addOns.set(
          editing
            ? this.addOns().map((one) => (one.id === data.id ? data : one))
            : [...this.addOns(), data],
        );

        this.toasts.show(editing ? 'Saved.' : 'Added. The checkout offers it now.', 'success');
      },
      error: (response) => {
        this.saving.set(false);
        this.formError.set(messageFor(response, 'That could not be saved.'));
      },
    });
  }

  // --- the order they are offered in ---------------------------------------

  move(addOn: AddOn, direction: -1 | 1): void {
    const order = this.addOns().map((one) => one.id);
    const from = order.indexOf(addOn.id);
    const to = from + direction;

    if (from < 0 || to < 0 || to >= order.length) return;

    [order[from], order[to]] = [order[to], order[from]];

    const before = this.addOns();
    this.addOns.set(order.map((id) => before.find((one) => one.id === id)!));
    this.savingOrder.set(true);

    this.api.reorderAddOns(this.eventId, order).subscribe({
      next: ({ data }) => {
        this.addOns.set(data);
        this.savingOrder.set(false);
      },
      error: () => {
        this.addOns.set(before);
        this.savingOrder.set(false);
        this.toasts.show('That order could not be saved.', 'danger');
      },
    });
  }

  // --- removing -------------------------------------------------------------

  consequence(addOn: AddOn): string {
    return addOn.sold > 0
      ? 'The checkout stops offering it. The ones already bought are unaffected and stay on their orders.'
      : 'The checkout stops offering it.';
  }

  confirmRemove(): void {
    const addOn = this.removing();
    if (!addOn) return;

    this.api.deleteAddOn(this.eventId, addOn.id).subscribe({
      next: ({ message }) => {
        this.removing.set(null);
        this.toasts.show(message, 'success');
        // Closed rather than gone when it has sold, so the list is reloaded
        // rather than guessed at.
        this.load();
      },
      error: (response) => {
        this.removing.set(null);
        this.toasts.show(messageFor(response, 'That could not be removed.'), 'danger');
      },
    });
  }

  // --- how a row reads ------------------------------------------------------

  /** How far through its stock it is, or null where there is no limit. */
  percent(addOn: AddOn): number | null {
    if (addOn.quantity_available === null || addOn.quantity_available === 0) return null;

    return Math.min(100, Math.round((addOn.sold / addOn.quantity_available) * 100));
  }

  private number(value: number | string | null): number | null {
    if (value === null || value === '') return null;

    const parsed = typeof value === 'number' ? value : parseInt(value, 10);

    return Number.isFinite(parsed) ? parsed : null;
  }

  private blank(): AddOnDraft {
    return { name: '', description: '', price: '', quantity: null, maxPerOrder: null, status: 'on_sale' };
  }
}
