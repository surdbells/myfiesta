import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { Copy, Download, MoreHorizontal, Pencil, Plus, Power, PowerOff, Share2 } from 'lucide-angular';
import { Share } from '@capacitor/share';
import type { CodeBatch, CodeSales, OrganizerEventDetail, PageMeta, PromoCode, TicketType } from '@myfiesta/api-types';
import { isoToZonedWallClock, zonedWallClockToIso } from '@myfiesta/shared/zoned-time';
import { Organizer } from '../../core/organizer';
import { Discover } from '../../core/discovery';
import { formatMoney } from '../../core/money';
import { fieldErrors, messageOf } from '../../core/errors';
import { shortEventTime } from '../../core/event-time';
import { shareFile } from '../../core/share-file';
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
  MfMultiSelect,
  MfScreen,
  MfSegmented,
  MfSheet,
  MfSkeleton,
  ToastStore,
  type MfChoice,
  type MfOption,
  type MfSegment,
} from '../../ui';
import { EventContext } from './event-context';

type Kind = 'discount' | 'promoter' | 'both' | 'access';

interface CodeDraft {
  code: string;
  label: string;
  kind: Kind;
  discountType: 'percentage' | 'fixed';
  percent: string;
  amount: number | null;
  promoter: string;
  refSlug: string;
  maxUses: string;
  perBuyer: string;
  minQuantity: string;
  covers: string[];
  unlocks: string[];
  startsAt: string;
  endsAt: string;
}

interface BatchDraft {
  name: string;
  prefix: string;
  quantity: string;
  purpose: 'discount' | 'access';
  discountType: 'percentage' | 'fixed';
  percent: string;
  amount: number | null;
  unlocks: string[];
  startsAt: string;
  endsAt: string;
}

const BLANK_CODE: CodeDraft = {
  code: '',
  label: '',
  kind: 'discount',
  discountType: 'percentage',
  percent: '',
  amount: null,
  promoter: '',
  refSlug: '',
  maxUses: '',
  perBuyer: '',
  minQuantity: '',
  covers: [],
  unlocks: [],
  startsAt: '',
  endsAt: '',
};

const BLANK_BATCH: BatchDraft = {
  name: '',
  prefix: '',
  quantity: '100',
  purpose: 'discount',
  discountType: 'percentage',
  percent: '100',
  amount: null,
  unlocks: [],
  startsAt: '',
  endsAt: '',
};

/**
 * Codes for one event: the ones typed at checkout, and batches of single-use
 * ones handed out a hundred at a time.
 *
 * A code does one of four things, and the form asks which first — what it
 * shows next depends on the answer, so a promoter's tracking code is never
 * asked for a discount it does not have.
 *
 * Its sale window is in the event's own time, like every other time about an
 * event; the phone's zone is wherever the organizer happens to be standing.
 */
@Component({
  selector: 'mf-event-codes',
  imports: [
    MfScreen,
    MfSegmented,
    MfIconButton,
    MfCard,
    MfBadge,
    MfButton,
    MfEmpty,
    MfSkeleton,
    MfSheet,
    MfField,
    MfMoney,
    MfChoices,
    MfMultiSelect,
  ],
  template: `
    <mf-screen title="Codes" [subtitle]="event()?.title ?? null" back [backTo]="'/manage/events/' + id()" refreshable [busy]="loading()" (refresh)="load()">
      <button mfIconButton screenActions tone="tonal" [icon]="plusIcon" [label]="view() === 'codes' ? 'New code' : 'New batch'" (click)="view() === 'codes' ? startCode() : startBatch()"></button>
      <mf-segmented screenBar ariaLabel="Which codes" [segments]="views" [(value)]="view" />

      @if (error(); as message) {
        <mf-empty title="Could not load the codes" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (view() === 'codes') {
        @if (codes(); as all) {
          @if (all.length === 0) {
            <mf-empty title="No codes yet" hint="Money off, a promoter's link, or early access to a tier nobody else can see yet.">
              <button mfButton (click)="startCode()">Make a code</button>
            </mf-empty>
          } @else {
            <ul class="items">
              @for (code of all; track code.id) {
                <li>
                  <mf-card tappable (click)="editCode(code)">
                    <div class="top">
                      <div class="name">
                        <h3 class="code">{{ code.code }}</h3>
                        <p class="what">{{ describeDiscount(code) }}@if (code.label) { · {{ code.label }} }</p>
                      </div>
                      <mf-badge [tone]="code.usable ? 'success' : code.is_active ? 'warning' : 'neutral'">{{ code.usable ? 'Working' : code.is_active ? 'Not now' : 'Off' }}</mf-badge>
                      <button mfIconButton size="sm" [icon]="moreIcon" [label]="'More for ' + code.code" (click)="$event.stopPropagation(); codeMenu(code)"></button>
                    </div>
                    <p class="uses">
                      <strong>{{ code.redemption_count }}</strong>@if (code.max_redemptions !== null) { / {{ code.max_redemptions }} } used
                      @if (window(code); as w) { · {{ w }} }
                    </p>
                    @if (conditions(code); as c) {
                      <p class="sub">{{ c }}</p>
                    }
                    @for (sales of code.sales; track sales.currency) {
                      <p class="sub">{{ describeSales(sales) }}</p>
                    }
                  </mf-card>
                </li>
              }
            </ul>

            @if (hasMore()) {
              <button mfButton class="more" variant="secondary" block [loading]="loading()" (click)="more()">Show more</button>
            }
          }
        } @else {
          <mf-card><mf-skeleton height="4rem" /></mf-card>
        }
      } @else {
        @if (batches(); as all) {
          @if (all.length === 0) {
            <mf-empty title="No batches" hint="A hundred single-use codes at once — for a sponsor's guests, a radio giveaway, the staff.">
              <button mfButton (click)="startBatch()">Make a batch</button>
            </mf-empty>
          } @else {
            <ul class="items">
              @for (batch of all; track batch.id) {
                <li>
                  <mf-card>
                    <div class="top">
                      <div class="name">
                        <h3>{{ batch.name }}</h3>
                        <p class="what">{{ batch.prefix }}-… · {{ batch.quantity }} codes</p>
                      </div>
                      <button mfIconButton size="sm" [icon]="moreIcon" [label]="'More for ' + batch.name" (click)="batchMenu(batch)"></button>
                    </div>
                    <div class="bar" role="img" [attr.aria-label]="batch.used + ' used of ' + batch.quantity">
                      <span class="used" [style.width.%]="(batch.used / batch.quantity) * 100"></span>
                      <span class="off" [style.width.%]="(batch.turned_off / batch.quantity) * 100"></span>
                    </div>
                    <p class="uses"><strong>{{ batch.used }}</strong> used · {{ unused(batch) }} left@if (batch.turned_off > 0) { · {{ batch.turned_off }} off }</p>
                  </mf-card>
                </li>
              }
            </ul>
          }
        } @else {
          <mf-card><mf-skeleton height="4rem" /></mf-card>
        }
      }
    </mf-screen>

    <mf-sheet [open]="codeOpen()" [heading]="editing() ? editing()!.code : 'New code'" [subheading]="editing() ? 'The code itself stays as it is — it is already out there.' : null" closable (closed)="codeOpen.set(false)">
      <div class="form">
        @if (formError(); as message) {
          <p class="form-error" role="alert">{{ message }}</p>
        }
        @if (!editing()) {
          <mf-field label="Code" hint="What buyers type. Letters and numbers." [error]="err('code')">
            <input class="mono" autocapitalize="characters" autocomplete="off" [value]="code().code" (input)="setCode('code', $any($event.target).value.toUpperCase())" placeholder="EARLYBIRD" maxlength="40" />
          </mf-field>
        }
        <mf-choices legend="What it does" [options]="kinds" [value]="code().kind" (valueChange)="setCode('kind', $any($event))" />

        @if (discounts()) {
          <mf-segmented ariaLabel="How much off" [segments]="discountTypes" [value]="code().discountType" (valueChange)="setCode('discountType', $any($event))" />
          @if (code().discountType === 'percentage') {
            <mf-field label="Percentage off" suffix="%" [error]="err('discount_value')">
              <input inputmode="decimal" [value]="code().percent" (input)="setCode('percent', $any($event.target).value)" placeholder="20" />
            </mf-field>
          } @else {
            <mf-money label="Amount off" [currency]="currency()" [error]="err('discount_value')" [value]="code().amount" (valueChange)="setCode('amount', $event)" />
          }
          @if (canTarget() && tierOptions().length > 1) {
            <mf-multi-select heading="Which tickets it takes money off" emptyLabel="Every ticket" [options]="tierOptions()" [value]="code().covers" (valueChange)="setCode('covers', $event)" />
          }
        }

        @if (attributes()) {
          <mf-field label="Promoter" optional [error]="err('promoter_name')">
            <input [value]="code().promoter" (input)="setCode('promoter', $any($event.target).value)" placeholder="DJ Tolu" />
          </mf-field>
          <mf-field label="Link name" optional hint="Their link ends in ?ref= and this. The code, if left empty." [error]="err('ref_slug')">
            <input autocapitalize="off" [value]="code().refSlug" (input)="setCode('refSlug', $any($event.target).value)" [placeholder]="code().code.toLowerCase() || 'djtolu'" />
          </mf-field>
        }

        @if (canTarget()) {
          <mf-multi-select
            heading="Tiers it opens"
            [subheading]="code().kind === 'access' ? 'A presale code has to open at least one.' : 'Hidden tiers, or ones not on sale yet, open to whoever has it.'"
            emptyLabel="Opens nothing extra"
            [options]="unlockOptions()"
            [value]="code().unlocks"
            (valueChange)="setCode('unlocks', $event)"
          />
        }

        <p class="group-label">Limits</p>
        <div class="pair">
          <mf-field label="Uses in all" optional [error]="err('max_redemptions')">
            <input inputmode="numeric" [value]="code().maxUses" (input)="setCode('maxUses', $any($event.target).value)" placeholder="No limit" />
          </mf-field>
          <mf-field label="Per buyer" optional [error]="err('max_per_customer')">
            <input inputmode="numeric" [value]="code().perBuyer" (input)="setCode('perBuyer', $any($event.target).value)" placeholder="No limit" />
          </mf-field>
        </div>
        @if (discounts()) {
          <mf-field label="Fewest tickets in the order" optional [error]="err('min_quantity')">
            <input inputmode="numeric" [value]="code().minQuantity" (input)="setCode('minQuantity', $any($event.target).value)" placeholder="Any" />
          </mf-field>
        }

        <p class="group-label">When it works <span class="zone">{{ event()?.timezone }} time</span></p>
        <mf-field label="From" optional [error]="err('starts_at')">
          <input type="datetime-local" [value]="code().startsAt" (input)="setCode('startsAt', $any($event.target).value)" />
        </mf-field>
        <mf-field label="Until" optional [error]="err('ends_at')">
          <input type="datetime-local" [value]="code().endsAt" (input)="setCode('endsAt', $any($event.target).value)" />
        </mf-field>

        <mf-field label="Note" optional hint="Only your team sees this." [error]="err('label')">
          <input [value]="code().label" (input)="setCode('label', $any($event.target).value)" placeholder="Instagram giveaway" maxlength="120" />
        </mf-field>
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="codeOpen.set(false)">Cancel</button>
        <button mfButton [loading]="saving()" [disabled]="!codeReady()" (click)="saveCode()">{{ editing() ? 'Save' : 'Make code' }}</button>
      </ng-container>
    </mf-sheet>

    <mf-sheet [open]="batchOpen()" heading="New batch" subheading="Every code in it works once." closable (closed)="batchOpen.set(false)">
      <div class="form">
        @if (formError(); as message) {
          <p class="form-error" role="alert">{{ message }}</p>
        }
        <mf-field label="Name" [error]="err('name')">
          <input [value]="batch().name" (input)="setBatch('name', $any($event.target).value)" placeholder="Sponsor guests" maxlength="120" />
        </mf-field>
        <div class="pair">
          <mf-field label="How many" hint="Up to 1,000." [error]="err('quantity')">
            <input inputmode="numeric" [value]="batch().quantity" (input)="setBatch('quantity', $any($event.target).value)" />
          </mf-field>
          <mf-field label="Starts with" optional [error]="err('prefix')">
            <input class="mono" autocapitalize="characters" [value]="batch().prefix" (input)="setBatch('prefix', $any($event.target).value.toUpperCase())" [placeholder]="prefixFrom(batch().name) || 'SPONSOR'" maxlength="8" />
          </mf-field>
        </div>
        <p class="example">They will look like <code>{{ example() }}</code></p>

        <mf-choices
          legend="Each code"
          [options]="purposes"
          [value]="batch().purpose"
          (valueChange)="setBatch('purpose', $any($event))"
        />

        @if (batch().purpose === 'discount') {
          <mf-segmented ariaLabel="How much off" [segments]="discountTypes" [value]="batch().discountType" (valueChange)="setBatch('discountType', $any($event))" />
          @if (batch().discountType === 'percentage') {
            <mf-field label="Percentage off" suffix="%" hint="100 makes them free tickets." [error]="err('discount_value')">
              <input inputmode="decimal" [value]="batch().percent" (input)="setBatch('percent', $any($event.target).value)" />
            </mf-field>
          } @else {
            <mf-money label="Amount off" [currency]="currency()" [error]="err('discount_value')" [value]="batch().amount" (valueChange)="setBatch('amount', $event)" />
          }
        } @else {
          <mf-multi-select heading="Tiers they open" emptyLabel="Choose at least one" [options]="unlockOptions()" [value]="batch().unlocks" (valueChange)="setBatch('unlocks', $event)" />
        }

        <p class="group-label">When they work <span class="zone">{{ event()?.timezone }} time</span></p>
        <mf-field label="From" optional>
          <input type="datetime-local" [value]="batch().startsAt" (input)="setBatch('startsAt', $any($event.target).value)" />
        </mf-field>
        <mf-field label="Until" optional>
          <input type="datetime-local" [value]="batch().endsAt" (input)="setBatch('endsAt', $any($event.target).value)" />
        </mf-field>
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="batchOpen.set(false)">Cancel</button>
        <button mfButton [loading]="saving()" [disabled]="!batchReady()" (click)="saveBatch()">Make {{ batch().quantity || 0 }} codes</button>
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

    .code,
    .mono,
    code {
      font-family: var(--font-family-mono);
      letter-spacing: 0.04em;
    }

    .what {
      color: var(--primary-text);
      font-weight: var(--font-weight-semibold);
      font-size: var(--font-size-sm);
    }

    .uses {
      margin-top: var(--space-2);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      font-variant-numeric: tabular-nums;
    }

    .uses strong {
      color: var(--text);
    }

    .sub {
      margin-top: var(--space-1);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .bar {
      display: flex;
      height: 6px;
      margin-top: var(--space-3);
      border-radius: var(--radius-full);
      background: var(--surface-inset);
      overflow: hidden;
    }

    .bar .used {
      background: var(--primary);
    }

    .bar .off {
      background: var(--border-strong);
    }

    .more {
      margin-top: var(--space-4);
    }

    .form {
      display: grid;
      gap: var(--space-4);
    }

    .pair {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-3);
    }

    .group-label {
      margin-top: var(--space-2);
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-semibold);
    }

    .zone {
      font-weight: var(--font-weight-regular);
      color: var(--text-subtle);
    }

    .example {
      margin-top: calc(var(--space-2) * -1);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
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
export class EventCodes implements OnInit {
  readonly id = input.required<string>();
  /** "code" to open a new code's form straight away, from the organization's list. */
  readonly make = input<string | undefined>(undefined);

  private readonly organizer = inject(Organizer);
  private readonly discover = inject(Discover);
  private readonly context = inject(EventContext);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  protected readonly view = signal<'codes' | 'batches'>('codes');
  protected readonly views: MfSegment[] = [
    { value: 'codes', label: 'Codes' },
    { value: 'batches', label: 'Batches' },
  ];

  protected readonly event = signal<OrganizerEventDetail | null>(null);
  protected readonly types = signal<TicketType[]>([]);
  protected readonly codes = signal<PromoCode[] | null>(null);
  protected readonly meta = signal<PageMeta | null>(null);
  protected readonly batches = signal<CodeBatch[] | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly codeOpen = signal(false);
  protected readonly batchOpen = signal(false);
  protected readonly editing = signal<PromoCode | null>(null);
  protected readonly code = signal<CodeDraft>({ ...BLANK_CODE });
  protected readonly batch = signal<BatchDraft>({ ...BLANK_BATCH });
  protected readonly saving = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly errors = signal<Record<string, string>>({});

  protected readonly plusIcon = Plus;
  protected readonly moreIcon = MoreHorizontal;

  private opened = false;

  protected readonly currency = computed(() => this.event()?.currency ?? 'CAD');

  protected readonly kinds: MfChoice[] = [
    { value: 'discount', label: 'Takes money off' },
    { value: 'promoter', label: 'Credits a promoter', hint: 'Their sales counted, nothing off.' },
    { value: 'both', label: 'Both', hint: 'Money off, and the sale counted to them.' },
    { value: 'access', label: 'Only opens tickets', hint: 'A presale: hidden tiers, nothing off.' },
  ];

  protected readonly purposes: MfChoice[] = [
    { value: 'discount', label: 'Takes money off' },
    { value: 'access', label: 'Opens tickets', hint: 'Early or hidden tiers.' },
  ];

  protected readonly discountTypes: MfSegment[] = [
    { value: 'percentage', label: 'Percentage' },
    { value: 'fixed', label: 'Fixed amount' },
  ];

  protected readonly discounts = computed(() => this.code().kind === 'discount' || this.code().kind === 'both');
  protected readonly attributes = computed(() => this.code().kind === 'promoter' || this.code().kind === 'both');

  /** A code for every event in the organization cannot name this event's tiers. */
  protected readonly canTarget = computed(() => this.editing()?.event_scoped ?? true);

  protected readonly tierOptions = computed<MfOption[]>(() => this.types().map((t) => ({ value: t.id, label: t.name, hint: formatMoney(t.price) })));

  protected readonly unlockOptions = computed<MfOption[]>(() =>
    this.types().map((t) => ({ value: t.id, label: t.name, hint: this.lockState(t) ?? 'On sale already' })),
  );

  protected readonly codeReady = computed(() => {
    const d = this.code();
    if (this.saving()) return false;
    if (!this.editing() && !/^[A-Z0-9][A-Z0-9_-]{1,39}$/.test(d.code.trim())) return false;
    if (d.kind === 'access' && d.unlocks.length === 0) return false;
    if (this.discounts() && this.discountValue(d.discountType, d.percent, d.amount) === null) return false;
    return true;
  });

  protected readonly batchReady = computed(() => {
    const d = this.batch();
    const quantity = Number(d.quantity);
    if (this.saving() || !d.name.trim() || !Number.isInteger(quantity) || quantity < 1 || quantity > 1000) return false;
    return d.purpose === 'discount' ? this.discountValue(d.discountType, d.percent, d.amount) !== null : d.unlocks.length > 0;
  });

  protected readonly example = computed(() => `${(this.batch().prefix.trim() || this.prefixFrom(this.batch().name)).toUpperCase() || 'CODE'}-7K2M9Q`);

  ngOnInit(): void {
    this.event.set(this.context.peek(this.id()));
    void this.organizer.ticketTypes(this.id()).then((t) => this.types.set(t)).catch(() => undefined);
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      const [event, codes, batches] = await Promise.all([
        this.context.get(this.id()),
        this.organizer.eventCodes(this.id(), 1),
        this.organizer.codeBatches(this.id()),
      ]);
      this.event.set(event);
      this.codes.set(codes.data);
      this.meta.set(codes.meta);
      this.batches.set(batches);

      if (this.make() === 'code' && !this.opened) {
        this.opened = true;
        this.startCode();
      }
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected hasMore(): boolean {
    const m = this.meta();
    return !!m && m.current_page < m.last_page;
  }

  protected async more(): Promise<void> {
    this.loading.set(true);

    try {
      const next = await this.organizer.eventCodes(this.id(), (this.meta()?.current_page ?? 1) + 1);
      this.codes.update((rows) => [...(rows ?? []), ...next.data]);
      this.meta.set(next.meta);
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
    } finally {
      this.loading.set(false);
    }
  }

  protected err(field: string): string | null {
    return this.errors()[field] ?? null;
  }

  protected setCode<K extends keyof CodeDraft>(key: K, value: CodeDraft[K]): void {
    this.code.update((d) => ({ ...d, [key]: value }));
  }

  protected setBatch<K extends keyof BatchDraft>(key: K, value: BatchDraft[K]): void {
    this.batch.update((d) => ({ ...d, [key]: value }));
  }

  // ---- describing -------------------------------------------------------

  /** "20% off", "$5.00 off", or what it does when it takes nothing off. */
  protected describeDiscount(code: PromoCode): string {
    if (code.discount_type === 'percentage' && code.discount_value !== null) {
      return `${Number((code.discount_value / 100).toFixed(2))}% off`;
    }

    if (code.discount_type === 'fixed' && code.discount_value !== null) {
      return `${formatMoney({ amount: code.discount_value, currency: code.discount_currency ?? this.currency() })} off`;
    }

    return code.ref_slug ? `Tracks ${code.promoter_name ?? 'a promoter'}` : 'Presale access';
  }

  protected conditions(code: PromoCode): string | null {
    const parts = [
      code.unlocks.length > 0 ? `Opens ${code.unlocks.map((t) => t.name).join(', ')}` : null,
      code.ticket_types.length > 0 ? `On ${code.ticket_types.map((t) => t.name).join(', ')}` : null,
      code.min_quantity ? `${code.min_quantity}+ tickets` : null,
      code.max_per_customer ? `${code.max_per_customer} per buyer` : null,
    ].filter((p): p is string => p !== null);

    return parts.length > 0 ? parts.join(' · ') : null;
  }

  protected window(code: PromoCode): string | null {
    const zone = this.event()?.timezone ?? 'UTC';
    const at = (iso: string) => shortEventTime(iso, zone);

    if (code.starts_at && code.ends_at) return `${at(code.starts_at)} – ${at(code.ends_at)}`;
    if (code.ends_at) return `Until ${at(code.ends_at)}`;
    if (code.starts_at) return `From ${at(code.starts_at)}`;

    return null;
  }

  protected describeSales(sales: CodeSales): string {
    const money = (amount: number) => formatMoney({ amount, currency: sales.currency });

    return [
      `${sales.orders} ${sales.orders === 1 ? 'order' : 'orders'}`,
      `${money(sales.revenue)} sold`,
      ...(sales.discount > 0 ? [`${money(sales.discount)} off`] : []),
    ].join(' · ');
  }

  protected unused(batch: CodeBatch): number {
    return batch.quantity - batch.used - batch.turned_off;
  }

  protected prefixFrom(name: string): string {
    return (name.trim().split(/\s+/)[0] ?? '')
      .normalize('NFD')
      .replace(/[^A-Za-z0-9]/g, '')
      .slice(0, 8)
      .toUpperCase();
  }

  private lockState(type: TicketType): string | null {
    if (type.status === 'hidden') return 'Hidden';
    if (type.sales_start_at && new Date(type.sales_start_at) > new Date()) return 'Not on sale yet';

    return null;
  }

  /** Basis points for a percentage, minor units for an amount; null when there is nothing usable. */
  private discountValue(type: 'percentage' | 'fixed', percent: string, amount: number | null): number | null {
    if (type === 'fixed') return amount && amount > 0 ? amount : null;

    const n = Number(percent.replace(',', '.'));

    return Number.isFinite(n) && n > 0 && n <= 100 ? Math.round(n * 100) : null;
  }

  private linkFor(code: PromoCode): string | null {
    const slug = this.event()?.slug;
    if (!slug) return null;

    const base = this.discover.siteBase();

    if (code.unlocks.length > 0) {
      const ref = code.ref_slug ? `&ref=${encodeURIComponent(code.ref_slug)}` : '';
      return `${base}/${slug}/tickets?access=${encodeURIComponent(code.code)}${ref}`;
    }

    return code.ref_slug ? `${base}/${slug}?ref=${encodeURIComponent(code.ref_slug)}` : null;
  }

  // ---- codes ------------------------------------------------------------

  protected startCode(): void {
    this.editing.set(null);
    this.code.set({ ...BLANK_CODE });
    this.formError.set(null);
    this.errors.set({});
    this.codeOpen.set(true);
  }

  protected editCode(code: PromoCode): void {
    const zone = this.event()?.timezone ?? 'UTC';
    const local = (iso: string | null) => (iso ? (isoToZonedWallClock(iso, zone) ?? '') : '');

    this.editing.set(code);
    this.code.set({
      code: code.code,
      label: code.label ?? '',
      kind: code.discount_type && code.ref_slug ? 'both' : code.discount_type ? 'discount' : code.ref_slug ? 'promoter' : 'access',
      discountType: code.discount_type ?? 'percentage',
      percent: code.discount_type === 'percentage' && code.discount_value !== null ? String(Number((code.discount_value / 100).toFixed(2))) : '',
      amount: code.discount_type === 'fixed' ? code.discount_value : null,
      promoter: code.promoter_name ?? '',
      refSlug: code.ref_slug ?? '',
      maxUses: code.max_redemptions === null ? '' : String(code.max_redemptions),
      perBuyer: code.max_per_customer === null ? '' : String(code.max_per_customer),
      minQuantity: code.min_quantity === null ? '' : String(code.min_quantity),
      covers: code.ticket_types.map((t) => t.id),
      unlocks: code.unlocks.map((t) => t.id),
      startsAt: local(code.starts_at),
      endsAt: local(code.ends_at),
    });
    this.formError.set(null);
    this.errors.set({});
    this.codeOpen.set(true);
  }

  protected async saveCode(): Promise<void> {
    if (!this.codeReady()) return;

    const d = this.code();
    const zone = this.event()?.timezone ?? 'UTC';
    const instant = (local: string) => (local ? zonedWallClockToIso(local, zone) : null);
    const number = (text: string) => (text.trim() === '' ? null : Number.parseInt(text, 10));
    const discounts = this.discounts();
    const attributes = this.attributes();
    const editing = this.editing();
    const allTiers = this.types().length;

    const body: Record<string, unknown> = {
      label: d.label.trim() || null,
      discount_type: discounts ? d.discountType : null,
      discount_value: discounts ? this.discountValue(d.discountType, d.percent, d.amount) : null,
      promoter_name: attributes ? d.promoter.trim() || null : null,
      // Defaults to the code itself, which is what a promoter would expect.
      ref_slug: attributes ? (d.refSlug.trim() || d.code.trim()).toLowerCase() || null : null,
      max_redemptions: number(d.maxUses),
      max_per_customer: number(d.perBuyer),
      min_quantity: discounts ? number(d.minQuantity) : null,
      starts_at: instant(d.startsAt),
      ends_at: instant(d.endsAt),
    };

    if (this.canTarget()) {
      // Every tier chosen is stored as "every ticket", so a tier added later
      // is covered too — which is what somebody who picked them all meant.
      body['ticket_type_ids'] = discounts && d.covers.length < allTiers ? d.covers : [];
      body['unlock_ticket_type_ids'] = d.unlocks;
    }

    this.saving.set(true);
    this.formError.set(null);
    this.errors.set({});

    try {
      if (editing) {
        await this.organizer.updateCode(this.id(), editing.id, body);
      } else {
        await this.organizer.createCode(this.id(), { ...body, code: d.code.trim(), event_scoped: true });
      }

      this.codeOpen.set(false);
      this.toasts.show(editing ? 'Saved.' : `${d.code.trim()} is ready to hand out.`, 'success');
      await this.load();
    } catch (error) {
      this.errors.set(fieldErrors(error));
      this.formError.set(Object.keys(this.errors()).length ? null : messageOf(error));
    } finally {
      this.saving.set(false);
    }
  }

  protected async codeMenu(code: PromoCode): Promise<void> {
    const link = this.linkFor(code);

    const chosen = await this.dialogs.menu({
      title: code.code,
      subtitle: this.describeDiscount(code),
      actions: [
        { key: 'edit', label: 'Edit', icon: Pencil },
        { key: 'copy', label: 'Copy the code', icon: Copy },
        ...(link ? [{ key: 'share', label: code.unlocks.length ? 'Share the presale link' : 'Share their link', icon: Share2 }] : []),
        code.is_active
          ? { key: 'off', label: 'Turn it off', icon: PowerOff, danger: true, hint: 'Orders already placed keep their discount' }
          : { key: 'on', label: 'Turn it back on', icon: Power },
      ],
    });

    try {
      switch (chosen) {
        case 'edit':
          this.editCode(code);
          return;
        case 'copy':
          await navigator.clipboard.writeText(code.code);
          this.toasts.show(`${code.code} copied.`, 'success');
          return;
        case 'share':
          await Share.share({ title: code.code, url: link! });
          return;
        case 'off': {
          const { message } = await this.organizer.deactivateCode(this.id(), code.id);
          this.toasts.show(message, 'success');
          break;
        }
        case 'on':
          await this.organizer.updateCode(this.id(), code.id, { is_active: true });
          this.toasts.show(`${code.code} works again.`, 'success');
          break;
        default:
          return;
      }

      await this.load();
    } catch (error) {
      if (error instanceof Error && /abort|cancel/i.test(error.name + error.message)) return;
      this.toasts.show(messageOf(error), 'danger');
    }
  }

  // ---- batches ----------------------------------------------------------

  protected startBatch(): void {
    this.batch.set({ ...BLANK_BATCH });
    this.formError.set(null);
    this.errors.set({});
    this.batchOpen.set(true);
  }

  protected async saveBatch(): Promise<void> {
    if (!this.batchReady()) return;

    const d = this.batch();
    const zone = this.event()?.timezone ?? 'UTC';
    const instant = (local: string) => (local ? zonedWallClockToIso(local, zone) : null);
    const discount = d.purpose === 'discount';

    this.saving.set(true);
    this.formError.set(null);
    this.errors.set({});

    try {
      const made = await this.organizer.createCodeBatch(this.id(), {
        name: d.name.trim(),
        prefix: d.prefix.trim() || null,
        quantity: Number(d.quantity),
        discount_type: discount ? d.discountType : null,
        discount_value: discount ? this.discountValue(d.discountType, d.percent, d.amount) : null,
        unlock_ticket_type_ids: discount ? [] : d.unlocks,
        starts_at: instant(d.startsAt),
        ends_at: instant(d.endsAt),
      });

      this.batchOpen.set(false);
      this.batches.update((all) => [made, ...(all ?? [])]);
      this.toasts.show(`${made.quantity} codes made. Share the list to hand them out.`, 'success');
    } catch (error) {
      this.errors.set(fieldErrors(error));
      this.formError.set(Object.keys(this.errors()).length ? null : messageOf(error));
    } finally {
      this.saving.set(false);
    }
  }

  protected async batchMenu(batch: CodeBatch): Promise<void> {
    const left = this.unused(batch);

    const chosen = await this.dialogs.menu({
      title: batch.name,
      subtitle: `${batch.used} used · ${left} left`,
      actions: [
        { key: 'export', label: 'Share the codes', icon: Download, hint: 'As a spreadsheet' },
        { key: 'stop', label: 'Turn the unused ones off', icon: PowerOff, danger: true, disabled: left === 0 },
      ],
    });

    try {
      if (chosen === 'export') {
        const csv = await this.organizer.exportCodeBatch(this.id(), batch.id);
        await shareFile(csv, `${this.event()?.slug ?? 'event'}-${batch.prefix.toLowerCase()}-codes.csv`, batch.name);
        return;
      }

      if (chosen === 'stop') {
        const kept = batch.used === 0 ? '' : ` The ${batch.used} already used keep their orders.`;
        const sure = await this.dialogs.confirm({
          title: `Turn off ${left} ${left === 1 ? 'code' : 'codes'}?`,
          message: `Nobody will be able to use them.${kept} This cannot be undone.`,
          confirm: 'Turn off',
          danger: true,
        });

        if (!sure) return;

        const { message } = await this.organizer.deactivateCodeBatch(this.id(), batch.id);
        this.toasts.show(message, 'success');
        await this.load();
      }
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
    }
  }
}
