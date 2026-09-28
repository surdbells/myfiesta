import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { ArrowDown, ArrowUp, Lock, LockOpen, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-angular';
import type { OrganizerEventDetail, TicketType } from '@myfiesta/api-types';
import { isoToZonedWallClock, zonedWallClockToIso } from '@myfiesta/shared/zoned-time';
import { Organizer } from '../../core/organizer';
import { formatMoney } from '../../core/money';
import { fieldErrors, messageOf } from '../../core/errors';
import { dayOf } from '../../core/when';
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
  MfSelect,
  MfSheet,
  MfSkeleton,
  MfStepper,
  ToastStore,
  type MfChoice,
  type MfOption,
} from '../../ui';
import { EventContext } from './event-context';
import { MfReviewLock, lockedForReview } from './event-review';

interface Draft {
  name: string;
  description: string;
  price: number | null;
  admits: number;
  quantity: string;
  maxPerOrder: string;
  status: 'on_sale' | 'hidden' | 'closed';
  salesStart: string;
  salesEnd: string;
  opensAfter: string;
}

const BLANK: Draft = {
  name: '',
  description: '',
  price: null,
  admits: 1,
  quantity: '',
  maxPerOrder: '',
  status: 'on_sale',
  salesStart: '',
  salesEnd: '',
  opensAfter: '',
};

/**
 * What a night sells: its ticket types.
 *
 * Each tier as a card that says what matters about it at a glance — price,
 * whether it is on sale, how much of it is gone, when it sells — with the
 * rest behind a tap. Adding and editing happen in a floating sheet, so the
 * list stays in view underneath and nobody loses their place.
 *
 * Sale windows are in the event's own zone, as every time on an event is: an
 * organizer in Toronto setting a Lagos night's early-bird cut-off means the
 * cut-off in Lagos.
 */
@Component({
  selector: 'mf-event-tickets',
  imports: [
    MfScreen,
    MfIconButton,
    MfCard,
    MfBadge,
    MfButton,
    MfEmpty,
    MfSkeleton,
    MfSheet,
    MfField,
    MfMoney,
    MfStepper,
    MfChoices,
    MfSelect,
    MfReviewLock,
  ],
  template: `
    <mf-screen title="Tickets" [subtitle]="event()?.title ?? null" back [backTo]="'/manage/events/' + id()" refreshable [busy]="loading()" (refresh)="load()">
      <button mfIconButton screenActions tone="tonal" [icon]="plusIcon" label="Add a ticket type" [disabled]="locked()" (click)="startNew()"></button>

      @if (locked()) {
        <mf-review-lock [eventId]="id()" />
      }

      @if (types(); as all) {
        @if (all.length === 0) {
          <mf-empty title="No tickets yet" hint="Add a ticket type and the night can go on sale.">
            <button mfButton [disabled]="locked()" (click)="startNew()">Add a ticket type</button>
          </mf-empty>
        } @else {
          <mf-card class="summary">
            <div>
              <p class="label">Sold</p>
              <p class="figure">{{ totalSold() }}@if (totalCapacity() !== null) { <span class="of">/ {{ totalCapacity() }}</span> }</p>
            </div>
            <div>
              <p class="label">At face value</p>
              <p class="figure">{{ faceValue() }}</p>
            </div>
          </mf-card>

          <ul class="tiers">
            @for (type of all; track type.id; let first = $first; let last = $last) {
              <li>
                <mf-card [tappable]="!locked()" (click)="edit(type)">
                  <div class="tier-top">
                    <div class="tier-name">
                      <h3>{{ type.name }}</h3>
                      <p class="price">{{ cash(type.price) }}@if (type.admits > 1) { <span class="admits"> · admits {{ type.admits }}</span> }</p>
                    </div>
                    <mf-badge [tone]="tone(type)">{{ statusLabel(type) }}</mf-badge>
                    @if (!locked()) {
                      <button
                        mfIconButton
                        size="sm"
                        [icon]="moreIcon"
                        [label]="'More for ' + type.name"
                        (click)="$event.stopPropagation(); menu(type, first, last)"
                      ></button>
                    }
                  </div>

                  <div class="tier-sold">
                    <span><strong>{{ type.sold }}</strong>@if (type.quantity_available !== null) { / {{ type.quantity_available }} } sold</span>
                    @if (window(type); as w) {
                      <span class="window">{{ w }}</span>
                    }
                  </div>
                  @if (portion(type) !== null) {
                    <span class="meter"><span [style.width.%]="portion(type)" [class.full]="portion(type)! >= 100"></span></span>
                  }
                  @if (type.waiting && type.opens_after) {
                    <p class="waiting">Opens when {{ type.opens_after.name }} sells out</p>
                  }
                </mf-card>
              </li>
            }
          </ul>
        }
      } @else if (error(); as message) {
        <mf-empty title="Could not load the tickets" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else {
        <div class="tiers">
          @for (n of [0, 1]; track n) {
            <mf-card><mf-skeleton height="4.5rem" /></mf-card>
          }
        </div>
      }
    </mf-screen>

    <mf-sheet
      [open]="formOpen()"
      [heading]="editing() ? 'Edit ' + editing()!.name : 'New ticket type'"
      [subheading]="editing()?.sold ? editing()!.sold + ' already sold. Price changes apply to new sales only.' : null"
      closable
      (closed)="formOpen.set(false)"
    >
      <div class="form">
        @if (formError(); as message) {
          <p class="form-error" role="alert">{{ message }}</p>
        }

        <mf-field label="Name" [error]="err('name')">
          <input [value]="draft().name" (input)="set('name', $any($event.target).value)" placeholder="General admission" maxlength="120" />
        </mf-field>

        <mf-money label="Price" [currency]="event()?.currency ?? 'CAD'" hint="Zero makes it free." [error]="err('price_amount')" [value]="draft().price" (valueChange)="set('price', $event)" />

        <mf-field label="How many" optional hint="Leave it empty for no limit." [error]="err('quantity_available')">
          <input inputmode="numeric" [value]="draft().quantity" (input)="set('quantity', $any($event.target).value)" placeholder="No limit" />
        </mf-field>

        <mf-field label="Most per order" optional [error]="err('max_per_order')">
          <input inputmode="numeric" [value]="draft().maxPerOrder" (input)="set('maxPerOrder', $any($event.target).value)" placeholder="No limit" />
        </mf-field>

        <mf-stepper label="Admits" hint="A table for six is one ticket that admits six." [min]="1" [max]="50" [value]="draft().admits" (valueChange)="set('admits', $event)" />

        <mf-choices legend="Who can buy it" [options]="statusChoices" [value]="draft().status" (valueChange)="set('status', $any($event))" />

        <mf-field label="Description" optional [limit]="500" [count]="draft().description.length">
          <textarea [value]="draft().description" (input)="set('description', $any($event.target).value)" placeholder="What it includes"></textarea>
        </mf-field>

        <p class="group-label">When it sells <span class="zone">{{ event()?.timezone }} time</span></p>
        <mf-field label="Sales start" optional [error]="err('sales_start_at')">
          <input type="datetime-local" [value]="draft().salesStart" (input)="set('salesStart', $any($event.target).value)" />
        </mf-field>
        <mf-field label="Sales end" optional [error]="err('sales_end_at')">
          <input type="datetime-local" [value]="draft().salesEnd" (input)="set('salesEnd', $any($event.target).value)" />
        </mf-field>

        @if (ladderOptions().length > 1) {
          <div>
            <p class="group-label">Opens after</p>
            <mf-select heading="Opens after" subheading="This tier goes on sale once the one chosen sells out." placeholder="Not waiting on anything" [options]="ladderOptions()" [value]="draft().opensAfter" (valueChange)="set('opensAfter', $event ?? '')" />
          </div>
        }
      </div>

      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="formOpen.set(false)">Cancel</button>
        <button mfButton [loading]="saving()" [disabled]="!canSave()" (click)="save()">{{ editing() ? 'Save' : 'Add' }}</button>
      </ng-container>
    </mf-sheet>
  `,
  styles: `
    .summary {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-4);
      margin-bottom: var(--space-4);
    }

    .label {
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-semibold);
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--text-subtle);
    }

    .figure {
      font-size: var(--font-size-2xl);
    }

    .of {
      font-family: var(--font-family-sans);
      font-size: var(--font-size-base);
      color: var(--text-muted);
    }

    .tiers {
      display: grid;
      gap: var(--space-3);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .tier-top {
      display: flex;
      align-items: flex-start;
      gap: var(--space-2);
    }

    .tier-name {
      flex: 1;
      min-width: 0;
    }

    h3 {
      font-size: var(--font-size-lg);
      line-height: 1.25;
    }

    .price {
      margin-top: 2px;
      font-family: var(--font-family-display);
      font-weight: var(--font-weight-semibold);
      color: var(--primary-text);
    }

    .admits {
      font-family: var(--font-family-sans);
      font-weight: var(--font-weight-regular);
      color: var(--text-muted);
      font-size: var(--font-size-sm);
    }

    .tier-sold {
      display: flex;
      justify-content: space-between;
      gap: var(--space-3);
      margin-top: var(--space-3);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      font-variant-numeric: tabular-nums;
    }

    .tier-sold strong {
      color: var(--text);
    }

    .window {
      text-align: right;
    }

    .meter {
      display: block;
      height: 5px;
      margin-top: var(--space-2);
      border-radius: var(--radius-full);
      background: var(--surface-inset);
      overflow: hidden;
    }

    .meter span {
      display: block;
      height: 100%;
      border-radius: inherit;
      background: var(--primary);
    }

    .meter span.full {
      background: var(--accent);
    }

    .waiting {
      margin-top: var(--space-2);
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

    .group-label {
      margin-bottom: calc(var(--space-2) * -1);
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-semibold);
    }

    .zone {
      font-weight: var(--font-weight-regular);
      color: var(--text-subtle);
    }
  `,
})
export class EventTickets implements OnInit {
  readonly id = input.required<string>();

  private readonly organizer = inject(Organizer);
  private readonly context = inject(EventContext);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  protected readonly event = signal<OrganizerEventDetail | null>(null);
  protected readonly types = signal<TicketType[] | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  /** Waiting for myFiesta's review: the tiers are shown, and nothing about them can change. */
  protected readonly locked = computed(() => lockedForReview(this.event()));

  protected readonly formOpen = signal(false);
  protected readonly editing = signal<TicketType | null>(null);
  protected readonly draft = signal<Draft>({ ...BLANK });
  protected readonly saving = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly errors = signal<Record<string, string>>({});

  protected readonly plusIcon = Plus;
  protected readonly moreIcon = MoreHorizontal;
  protected readonly cash = formatMoney;

  protected readonly statusChoices: MfChoice[] = [
    { value: 'on_sale', label: 'On sale', hint: 'Anybody can buy it.' },
    { value: 'hidden', label: 'Hidden', hint: 'Only with a presale code that unlocks it.' },
    { value: 'closed', label: 'Closed', hint: 'Shown, not for sale. Sales so far still stand.' },
  ];

  protected readonly totalSold = computed(() => (this.types() ?? []).reduce((sum, t) => sum + t.sold, 0));

  protected readonly totalCapacity = computed(() => {
    const types = this.types() ?? [];

    return types.some((t) => t.quantity_available === null) ? null : types.reduce((sum, t) => sum + (t.quantity_available ?? 0), 0);
  });

  protected readonly faceValue = computed(() => {
    const types = this.types() ?? [];
    const currency = this.event()?.currency ?? types[0]?.price.currency ?? 'CAD';

    return formatMoney({ amount: types.reduce((sum, t) => sum + t.sold * t.price.amount, 0), currency });
  });

  protected readonly ladderOptions = computed<MfOption[]>(() => [
    { value: '', label: 'Not waiting on anything' },
    ...(this.types() ?? [])
      .filter((t) => t.id !== this.editing()?.id)
      .map((t) => ({ value: t.id, label: t.name, hint: formatMoney(t.price) })),
  ]);

  protected readonly canSave = computed(() => {
    const d = this.draft();

    return d.name.trim() !== '' && d.price !== null && !this.saving();
  });

  ngOnInit(): void {
    this.event.set(this.context.peek(this.id()));
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      const [event, types] = await Promise.all([this.context.get(this.id()), this.organizer.ticketTypes(this.id())]);
      this.event.set(event);
      this.types.set(types);
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  // --- reading a tier --------------------------------------------------------------

  protected tone(type: TicketType): 'success' | 'warning' | 'danger' | 'neutral' {
    if (type.sold_out || type.status === 'sold_out') return 'danger';
    if (type.status === 'on_sale' && !type.waiting) return 'success';
    if (type.status === 'hidden' || type.waiting) return 'warning';

    return 'neutral';
  }

  protected statusLabel(type: TicketType): string {
    if (type.sold_out || type.status === 'sold_out') return 'Sold out';
    if (type.waiting) return 'Waiting';

    return { on_sale: 'On sale', hidden: 'Hidden', closed: 'Closed', sold_out: 'Sold out' }[type.status];
  }

  protected portion(type: TicketType): number | null {
    if (!type.quantity_available) return null;

    return Math.min(100, Math.round((type.sold / type.quantity_available) * 100));
  }

  protected window(type: TicketType): string | null {
    const zone = this.event()?.timezone ?? 'UTC';
    const on = (iso: string) => dayOf(iso, zone);

    if (type.sales_start_at && type.sales_end_at) return `${on(type.sales_start_at)} – ${on(type.sales_end_at)}`;
    if (type.sales_start_at) return `From ${on(type.sales_start_at)}`;
    if (type.sales_end_at) return `Until ${on(type.sales_end_at)}`;

    return null;
  }

  // --- the form ---------------------------------------------------------------------

  /** What the server said was wrong with one field, if anything. */
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

  protected edit(type: TicketType): void {
    if (this.locked()) return;

    const zone = this.event()?.timezone ?? 'UTC';
    const local = (iso: string | null) => (iso ? (isoToZonedWallClock(iso, zone) ?? '') : '');

    this.editing.set(type);
    this.draft.set({
      name: type.name,
      description: type.description ?? '',
      price: type.price.amount,
      admits: type.admits,
      quantity: type.quantity_available === null ? '' : String(type.quantity_available),
      maxPerOrder: type.max_per_order === null ? '' : String(type.max_per_order),
      // A tier the server has worked out is sold out still edits as what it
      // is: on sale, with nothing left. Closing it is a separate decision.
      status: type.status === 'sold_out' ? 'on_sale' : type.status,
      salesStart: local(type.sales_start_at),
      salesEnd: local(type.sales_end_at),
      opensAfter: type.opens_after?.id ?? '',
    });
    this.formError.set(null);
    this.errors.set({});
    this.formOpen.set(true);
  }

  protected async save(): Promise<void> {
    if (!this.canSave()) return;

    const d = this.draft();
    const zone = this.event()?.timezone ?? 'UTC';
    const number = (text: string) => (text.trim() === '' ? null : Number.parseInt(text, 10));
    const instant = (local: string) => (local ? zonedWallClockToIso(local, zone) : null);

    const body = {
      name: d.name.trim(),
      description: d.description.trim() || null,
      price_amount: d.price ?? 0,
      admits: d.admits,
      // Empty means unlimited, which is null and not zero. Zero would put the
      // tier on sale with nothing behind it.
      quantity_available: number(d.quantity),
      max_per_order: number(d.maxPerOrder),
      sales_start_at: instant(d.salesStart),
      sales_end_at: instant(d.salesEnd),
      opens_after_id: d.opensAfter || null,
      status: d.status,
    };

    // The tier, its price and who can buy it, said back before it is saved:
    // on a night that is on sale it is what buyers see and pay the moment it
    // lands, and a slipped digit sells at that price until somebody notices.
    const editing = this.editing();
    const price = formatMoney({ amount: body.price_amount, currency: this.event()?.currency ?? editing?.price.currency ?? 'CAD' });
    const onSale = this.event()?.status === 'published';
    const count = body.quantity_available !== null ? `, ${body.quantity_available} in all` : ', with no limit';
    const consequences: string[] = [];

    if (editing && editing.price.amount !== body.price_amount && editing.sold > 0) {
      consequences.push(`The ${editing.sold} already sold keep what was paid for them.`);
    }

    if (onSale && d.status === 'on_sale') consequences.push('The event is on sale: buyers see it straight away.');
    else if (onSale && d.status === 'hidden') consequences.push('Only buyers with a code that unlocks it can see and buy it.');
    else if (onSale && d.status === 'closed') consequences.push('It shows as unavailable. Tickets already sold still work.');
    else consequences.push('Nobody sees it until the event is approved and on sale.');

    const sure = await this.dialogs.confirm({
      title: editing ? `Save the changes to ${body.name}?` : `Add ${body.name} at ${price}?`,
      body: editing ? `${body.name} is sold at ${price}${count}.` : `A new ticket type, sold at ${price}${count}.`,
      consequences,
      confirmLabel: editing ? 'Save changes' : 'Add the ticket type',
      tone: 'default',
    });

    if (!sure || this.saving()) return;

    this.saving.set(true);
    this.formError.set(null);
    this.errors.set({});

    try {
      if (editing) await this.organizer.updateTicketType(this.id(), editing.id, body);
      else await this.organizer.createTicketType(this.id(), body);

      this.formOpen.set(false);
      this.toasts.show(editing ? 'Saved.' : 'Ticket type added.', 'success');
      this.context.forget(this.id());
      await this.load();
    } catch (error) {
      this.errors.set(fieldErrors(error));
      this.formError.set(Object.keys(this.errors()).length ? null : messageOf(error));
    } finally {
      this.saving.set(false);
    }
  }

  // --- a tier's menu -----------------------------------------------------------------

  protected async menu(type: TicketType, first: boolean, last: boolean): Promise<void> {
    if (this.locked()) return;

    const closed = type.status === 'closed';
    const chosen = await this.dialogs.menu({
      title: type.name,
      subtitle: `${formatMoney(type.price)} · ${type.sold} sold`,
      actions: [
        { key: 'edit', label: 'Edit', icon: Pencil },
        { key: 'up', label: 'Move up', icon: ArrowUp, disabled: first },
        { key: 'down', label: 'Move down', icon: ArrowDown, disabled: last },
        closed
          ? { key: 'open', label: 'Put back on sale', icon: LockOpen }
          : { key: 'close', label: 'Stop selling it', icon: Lock, hint: 'Sales so far still stand' },
        {
          key: 'delete',
          label: 'Delete',
          icon: Trash2,
          danger: true,
          disabled: type.sold > 0,
          hint: type.sold > 0 ? 'Sold tickets keep it here — stop selling it instead' : undefined,
        },
      ],
    });

    switch (chosen) {
      case 'edit':
        this.edit(type);
        break;
      case 'up':
      case 'down':
        await this.move(type, chosen === 'up' ? -1 : 1);
        break;
      case 'open':
      case 'close': {
        const sure = await this.dialogs.confirm(
          chosen === 'open'
            ? {
                title: `Put ${type.name} back on sale?`,
                body: `Anybody can buy it again at ${formatMoney(type.price)}, straight away.`,
                confirmLabel: 'Put it back on sale',
                tone: 'default',
              }
            : {
                title: `Stop selling ${type.name}?`,
                body: 'Nobody can buy it from now on. It shows as unavailable.',
                consequences: type.sold > 0 ? [`The ${type.sold} already sold still work at the door.`] : [],
                confirmLabel: 'Stop selling it',
                tone: 'danger',
              },
        );

        if (sure) {
          await this.act(() => this.organizer.updateTicketType(this.id(), type.id, { status: chosen === 'open' ? 'on_sale' : 'closed' }), chosen === 'open' ? 'Back on sale.' : 'No longer on sale.');
        }
        break;
      }
      case 'delete': {
        const sure = await this.dialogs.confirm({
          title: `Delete ${type.name}?`,
          body: 'It is gone from the event page and this list. Nobody has bought it, so nothing else changes.',
          confirmLabel: 'Delete the ticket type',
          tone: 'danger',
        });

        if (sure) await this.act(() => this.organizer.deleteTicketType(this.id(), type.id), 'Deleted.');
        break;
      }
    }
  }

  private async move(type: TicketType, by: -1 | 1): Promise<void> {
    const ids = (this.types() ?? []).map((t) => t.id);
    const at = ids.indexOf(type.id);
    const to = at + by;

    if (to < 0 || to >= ids.length) return;

    [ids[at], ids[to]] = [ids[to], ids[at]];

    // Moved on screen straight away; the server's answer is the final word.
    const byId = new Map((this.types() ?? []).map((t) => [t.id, t]));
    this.types.set(ids.map((id) => byId.get(id)!));

    try {
      this.types.set(await this.organizer.reorderTicketTypes(this.id(), ids));
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
      await this.load();
    }
  }

  private async act(task: () => Promise<unknown>, done: string): Promise<void> {
    try {
      await task();
      this.toasts.show(done, 'success');
      await this.load();
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
    }
  }
}
