import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { ArrowDown, ArrowUp, Lock, LockOpen, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-angular';
import type { AddOn, OrganizerEventDetail } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { formatMoney } from '../../core/money';
import { fieldErrors, messageOf } from '../../core/errors';
import {
  Dialogs,
  MfBadge,
  MfButton,
  MfCard,
  MfChoices,
  MfEmpty,
  MfField,
  MfIconButton,
  MfMoney,
  MfScreen,
  MfSheet,
  MfSkeleton,
  ToastStore,
  type MfChoice,
} from '../../ui';
import { EventContext } from './event-context';
import { MfReviewLock, lockedForReview } from './event-review';

interface Draft {
  name: string;
  description: string;
  price: number | null;
  quantity: string;
  maxPerOrder: string;
  status: 'on_sale' | 'closed';
}

const BLANK: Draft = { name: '', description: '', price: null, quantity: '', maxPerOrder: '', status: 'on_sale' };

/**
 * What is sold beside a ticket: a table, a bottle, a shirt.
 *
 * The same shape as the tickets screen on purpose — a price, a stock, whether
 * it is on sale — with the one difference said up front: an extra admits
 * nobody. It rides on an order that has a ticket in it.
 */
@Component({
  selector: 'mf-event-extras',
  imports: [MfScreen, MfIconButton, MfCard, MfBadge, MfButton, MfEmpty, MfSkeleton, MfSheet, MfField, MfMoney, MfChoices, MfReviewLock],
  template: `
    <mf-screen title="Extras" [subtitle]="event()?.title ?? null" back [backTo]="'/manage/events/' + id()" refreshable [busy]="loading()" (refresh)="load()">
      <button mfIconButton screenActions tone="tonal" [icon]="plusIcon" label="Add an extra" [disabled]="locked()" (click)="startNew()"></button>

      @if (locked()) {
        <mf-review-lock [eventId]="id()" />
      }

      @if (items(); as all) {
        @if (all.length === 0) {
          <mf-empty title="No extras" hint="Tables, bottles, a shirt: sold with a ticket, and they admit nobody.">
            <button mfButton [disabled]="locked()" (click)="startNew()">Add an extra</button>
          </mf-empty>
        } @else {
          <ul class="items">
            @for (item of all; track item.id; let first = $first; let last = $last) {
              <li>
                <mf-card [tappable]="!locked()" (click)="edit(item)">
                  <div class="top">
                    <div class="name">
                      <h3>{{ item.name }}</h3>
                      <p class="price">{{ cash(item.price) }}</p>
                    </div>
                    <mf-badge [tone]="item.status === 'on_sale' ? 'success' : 'neutral'">{{ item.status === 'on_sale' ? 'On sale' : 'Closed' }}</mf-badge>
                    @if (!locked()) {
                      <button mfIconButton size="sm" [icon]="moreIcon" [label]="'More for ' + item.name" (click)="$event.stopPropagation(); menu(item, first, last)"></button>
                    }
                  </div>
                  <p class="sold"><strong>{{ item.sold }}</strong>@if (item.quantity_available !== null) { / {{ item.quantity_available }} } sold</p>
                  @if (item.description) {
                    <p class="desc">{{ item.description }}</p>
                  }
                </mf-card>
              </li>
            }
          </ul>
        }
      } @else if (error(); as message) {
        <mf-empty title="Could not load the extras" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else {
        <mf-card><mf-skeleton height="4rem" /></mf-card>
      }
    </mf-screen>

    <mf-sheet [open]="formOpen()" [heading]="editing() ? 'Edit ' + editing()!.name : 'New extra'" closable (closed)="formOpen.set(false)">
      <div class="form">
        @if (formError(); as message) {
          <p class="form-error" role="alert">{{ message }}</p>
        }
        <mf-field label="Name" [error]="err('name')">
          <input [value]="draft().name" (input)="set('name', $any($event.target).value)" placeholder="VIP table for six" maxlength="120" />
        </mf-field>
        <mf-money label="Price" [currency]="event()?.currency ?? 'CAD'" [error]="err('price_amount')" [value]="draft().price" (valueChange)="set('price', $event)" />
        <mf-field label="How many" optional hint="Leave it empty for no limit." [error]="err('quantity_available')">
          <input inputmode="numeric" [value]="draft().quantity" (input)="set('quantity', $any($event.target).value)" placeholder="No limit" />
        </mf-field>
        <mf-field label="Most per order" optional [error]="err('max_per_order')">
          <input inputmode="numeric" [value]="draft().maxPerOrder" (input)="set('maxPerOrder', $any($event.target).value)" placeholder="No limit" />
        </mf-field>
        <mf-choices legend="Selling" [options]="statusChoices" [value]="draft().status" (valueChange)="set('status', $any($event))" />
        <mf-field label="Description" optional [limit]="500" [count]="draft().description.length">
          <textarea [value]="draft().description" (input)="set('description', $any($event.target).value)" placeholder="What comes with it"></textarea>
        </mf-field>
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="formOpen.set(false)">Cancel</button>
        <button mfButton [loading]="saving()" [disabled]="!canSave()" (click)="save()">{{ editing() ? 'Save' : 'Add' }}</button>
      </ng-container>
    </mf-sheet>
  `,
  styles: `
    .items {
      display: grid;
      gap: var(--space-3);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .top {
      display: flex;
      align-items: flex-start;
      gap: var(--space-2);
    }

    .name {
      flex: 1;
      min-width: 0;
    }

    h3 {
      font-size: var(--font-size-lg);
    }

    .price {
      font-family: var(--font-family-display);
      font-weight: var(--font-weight-semibold);
      color: var(--primary-text);
    }

    .sold {
      margin-top: var(--space-2);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .sold strong {
      color: var(--text);
    }

    .desc {
      margin-top: var(--space-1);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .form {
      display: grid;
      gap: var(--space-4);
    }

    .form-error {
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-lg);
      background: color-mix(in srgb, var(--danger) 10%, transparent);
      color: var(--danger-text);
      font-size: var(--font-size-sm);
    }
  `,
})
export class EventExtras implements OnInit {
  readonly id = input.required<string>();

  private readonly organizer = inject(Organizer);
  private readonly context = inject(EventContext);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  protected readonly event = signal<OrganizerEventDetail | null>(null);
  protected readonly items = signal<AddOn[] | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  /** Waiting for myFiesta's review: the extras are shown, and nothing about them can change. */
  protected readonly locked = computed(() => lockedForReview(this.event()));

  protected readonly formOpen = signal(false);
  protected readonly editing = signal<AddOn | null>(null);
  protected readonly draft = signal<Draft>({ ...BLANK });
  protected readonly saving = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly errors = signal<Record<string, string>>({});

  protected readonly plusIcon = Plus;
  protected readonly moreIcon = MoreHorizontal;
  protected readonly cash = formatMoney;

  protected readonly statusChoices: MfChoice[] = [
    { value: 'on_sale', label: 'On sale', hint: 'Offered with every ticket.' },
    { value: 'closed', label: 'Closed', hint: 'Not offered. Anything sold still stands.' },
  ];

  protected readonly canSave = computed(() => this.draft().name.trim() !== '' && this.draft().price !== null && !this.saving());

  ngOnInit(): void {
    this.event.set(this.context.peek(this.id()));
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      const [event, items] = await Promise.all([this.context.get(this.id()), this.organizer.addOns(this.id())]);
      this.event.set(event);
      this.items.set(items);
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected err(field: string): string | null {
    return this.errors()[field] ?? null;
  }

  protected set<K extends keyof Draft>(key: K, value: Draft[K]): void {
    this.draft.update((d) => ({ ...d, [key]: value }));
  }

  protected startNew(): void {
    if (this.locked()) return;

    this.editing.set(null);
    this.draft.set({ ...BLANK });
    this.formError.set(null);
    this.errors.set({});
    this.formOpen.set(true);
  }

  protected edit(item: AddOn): void {
    if (this.locked()) return;

    this.editing.set(item);
    this.draft.set({
      name: item.name,
      description: item.description ?? '',
      price: item.price.amount,
      quantity: item.quantity_available === null ? '' : String(item.quantity_available),
      maxPerOrder: item.max_per_order === null ? '' : String(item.max_per_order),
      status: item.status,
    });
    this.formError.set(null);
    this.errors.set({});
    this.formOpen.set(true);
  }

  protected async save(): Promise<void> {
    if (!this.canSave()) return;

    const d = this.draft();
    const number = (text: string) => (text.trim() === '' ? null : Number.parseInt(text, 10));
    const body = {
      name: d.name.trim(),
      description: d.description.trim() || null,
      price_amount: d.price ?? 0,
      quantity_available: number(d.quantity),
      max_per_order: number(d.maxPerOrder),
      status: d.status,
    };

    const editing = this.editing();
    const cost = formatMoney({ amount: body.price_amount, currency: this.event()?.currency ?? editing?.price.currency ?? 'CAD' });
    const onSale = this.event()?.status === 'published';

    // Said back before it is offered: an extra is a line on somebody's bill,
    // and on a night that is on sale the next checkout offers it at this price.
    const sure = await this.dialogs.confirm({
      title: editing ? `Save the changes to ${body.name}?` : `Add ${body.name} at ${cost}?`,
      body:
        d.status === 'on_sale'
          ? `${onSale ? 'The checkout offers it straight away' : 'The checkout offers it once the event is on sale'}, at ${cost} each.`
          : 'It is kept here, and the checkout does not offer it.',
      consequences:
        editing && editing.sold > 0 && editing.price.amount !== body.price_amount ? [`The ${editing.sold} already bought keep what was paid for them.`] : [],
      confirmLabel: editing ? 'Save changes' : 'Add the extra',
      tone: 'default',
    });

    if (!sure || this.saving()) return;

    this.saving.set(true);
    this.formError.set(null);
    this.errors.set({});

    try {
      if (editing) await this.organizer.updateAddOn(this.id(), editing.id, body);
      else await this.organizer.createAddOn(this.id(), body);

      this.formOpen.set(false);
      this.toasts.show(editing ? 'Saved.' : 'Extra added.', 'success');
      await this.load();
    } catch (error) {
      this.errors.set(fieldErrors(error));
      this.formError.set(Object.keys(this.errors()).length ? null : messageOf(error));
    } finally {
      this.saving.set(false);
    }
  }

  protected async menu(item: AddOn, first: boolean, last: boolean): Promise<void> {
    if (this.locked()) return;

    const chosen = await this.dialogs.menu({
      title: item.name,
      subtitle: `${formatMoney(item.price)} · ${item.sold} sold`,
      actions: [
        { key: 'edit', label: 'Edit', icon: Pencil },
        { key: 'up', label: 'Move up', icon: ArrowUp, disabled: first },
        { key: 'down', label: 'Move down', icon: ArrowDown, disabled: last },
        item.status === 'closed'
          ? { key: 'open', label: 'Put back on sale', icon: LockOpen }
          : { key: 'close', label: 'Stop selling it', icon: Lock },
        { key: 'delete', label: 'Delete', icon: Trash2, danger: true, disabled: item.sold > 0, hint: item.sold > 0 ? 'Sold, so it stays — stop selling it instead' : undefined },
      ],
    });

    try {
      switch (chosen) {
        case 'edit':
          this.edit(item);
          return;
        case 'up':
        case 'down': {
          const ids = (this.items() ?? []).map((i) => i.id);
          const at = ids.indexOf(item.id);
          const to = at + (chosen === 'up' ? -1 : 1);
          [ids[at], ids[to]] = [ids[to], ids[at]];
          this.items.set(await this.organizer.reorderAddOns(this.id(), ids));
          return;
        }
        case 'open':
        case 'close': {
          const sure = await this.dialogs.confirm(
            chosen === 'open'
              ? {
                  title: `Offer ${item.name} again?`,
                  body: `The checkout offers it at ${formatMoney(item.price)} again, straight away.`,
                  confirmLabel: 'Put it back on sale',
                  tone: 'default',
                }
              : {
                  title: `Stop selling ${item.name}?`,
                  body: 'The checkout stops offering it straight away.',
                  consequences: item.sold > 0 ? [`The ${item.sold} already bought stay on their orders.`] : [],
                  confirmLabel: 'Stop selling it',
                  tone: 'danger',
                },
          );

          if (!sure) return;

          await this.organizer.updateAddOn(this.id(), item.id, { status: chosen === 'open' ? 'on_sale' : 'closed' });
          this.toasts.show(chosen === 'open' ? 'Back on sale.' : 'No longer on sale.', 'success');
          break;
        }
        case 'delete': {
          // Deleted from inside the sheet, so a refusal is said where
          // "Delete" can be pressed again.
          const deleted = await this.dialogs.confirm({
            title: `Delete ${item.name}?`,
            body: 'The checkout stops offering it, and it is gone from this list. Nobody has bought it, so nothing else changes.',
            confirmLabel: 'Delete the extra',
            busyLabel: 'Deleting…',
            tone: 'danger',
            run: () => this.organizer.deleteAddOn(this.id(), item.id),
          });

          if (!deleted) return;

          this.toasts.show('Deleted.', 'success');
          break;
        }
        default:
          return;
      }

      await this.load();
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
    }
  }
}
