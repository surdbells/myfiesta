import { DOCUMENT } from '@angular/common';
import { ConfirmDialog, UiButton, UiPagination, UiSelect, type SelectOption } from '@myfiesta/ui';
import { CodeBatches } from './code-batches';
import { Component, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { eventIdFrom } from '../../core/event-id';
import { Api } from '../../core/api';
import { CodeSales, OrganizerEventDetail, PageMeta, PromoCode, TicketType } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { amountProblem, formatMoney, toMinorUnits } from '../../core/money';
import { describeZone, isoToZonedWallClock, localZone, zonedWallClockToIso } from '../../core/zoned-time';
import { SessionStore } from '../../core/session';
import { whenCodeWorks } from '@myfiesta/shared/code-window';

/**
 * Discount and promoter codes.
 *
 * One screen for both, because that is how they are actually used: in
 * nightlife a promoter's discount code *is* their attribution, and asking an
 * organizer to keep two separate lists in step would mean neither is right.
 *
 * The API and its tests have existed since Phase 4 with nothing able to reach
 * them. Reporting the feature as done on green tests was wrong — a code an
 * organizer cannot create does not exist.
 *
 * Numbers go in as an organizer says them and travel as integers. A percentage
 * is typed as 20 and sent as 2000 basis points; a fixed amount is typed as
 * 5.00 and sent as 500 minor units. Neither conversion belongs anywhere but
 * here, at the edge.
 */
@Component({
  selector: 'app-event-codes',
  imports: [FormsModule, UiButton, UiPagination, UiSelect, CodeBatches],
  templateUrl: './event-codes.html',
})
export class EventCodes implements OnInit {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly confirmDialog = inject(ConfirmDialog);
  readonly session = inject(SessionStore);

  private readonly document = inject(DOCUMENT);

  /**
   * The event, when this is placed somewhere without one in the address —
   * the organization-wide Discount codes screen, which asks which event first.
   */
  readonly forEvent = input<string | null>(null);

  /** Just the form: no heading, no list, no batches. For the Discount codes screen. */
  readonly formOnly = input(false);

  /** A code was made (form-only use). */
  readonly created = output<void>();

  private routeEventId: string | null = null;

  get eventId(): string {
    return this.forEvent() ?? this.routeEventId ?? '';
  }

  readonly event = signal<OrganizerEventDetail | null>(null);
  readonly ticketTypes = signal<TicketType[]>([]);
  readonly codes = signal<PromoCode[]>([]);
  readonly meta = signal<PageMeta | null>(null);
  readonly page = signal(1);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);
  readonly copied = signal<string | null>(null);

  /**
   * The code being edited, or null while the form is making a new one.
   *
   * Editing reuses the same form rather than a second one: the fields are the
   * same fields, and two of them would drift.
   */
  readonly editing = signal<PromoCode | null>(null);

  readonly form = signal({
    code: '',
    label: '',
    kind: 'discount' as 'discount' | 'promoter' | 'both' | 'access',
    discount_type: 'percentage' as 'percentage' | 'fixed',
    discount_value: '',
    promoter_name: '',
    ref_slug: '',
    max_redemptions: '',
    max_per_customer: '',
    min_quantity: '',
    ticket_type_ids: [] as string[],
    unlock_ticket_type_ids: [] as string[],
    starts_at: '',
    ends_at: '',
  });

  readonly kindOptions: SelectOption[] = [
    { value: 'discount', label: 'Takes money off' },
    { value: 'promoter', label: 'Credits a promoter' },
    { value: 'both', label: 'Both' },
    { value: 'access', label: 'Only unlocks tickets (presale)' },
  ];

  readonly discountTypeOptions: SelectOption[] = [
    { value: 'percentage', label: 'A percentage' },
    { value: 'fixed', label: 'A fixed amount' },
  ];

  readonly discounts = computed(() => this.form().kind === 'discount' || this.form().kind === 'both');

  /** A fixed amount is money, and is read as money is typed: "5,000" is five thousand. */
  readonly discountProblem = computed(() => {
    const form = this.form();

    return this.discounts() && form.discount_type === 'fixed' ? amountProblem(form.discount_value) : null;
  });
  readonly attributes = computed(() => this.form().kind === 'promoter' || this.form().kind === 'both');

  /** A presale-only code has to open something, or it does nothing at all. */
  readonly needsUnlock = computed(() => this.form().kind === 'access' && this.form().unlock_ticket_type_ids.length === 0);

  isUnlocked(id: string): boolean {
    return this.form().unlock_ticket_type_ids.includes(id);
  }

  toggleUnlock(id: string): void {
    const ids = this.form().unlock_ticket_type_ids;

    this.form.set({
      ...this.form(),
      unlock_ticket_type_ids: ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id],
    });
  }

  /** Why a tier would need unlocking, said beside it, so the choice makes sense. */
  lockState(type: TicketType): string | null {
    if (type.status === 'hidden') return 'hidden';
    if (type.sales_start_at && new Date(type.sales_start_at) > new Date()) return 'not on sale yet';

    return null;
  }

  readonly currency = computed(() => this.event()?.currency ?? 'CAD');

  /** Code windows are the event's wall clock, like every other time about it. */
  readonly zone = computed(() => this.event()?.timezone ?? localZone());
  readonly zoneName = computed(() => describeZone(this.zone()));

  constructor() {
    // Absent on the Discount codes screen, which passes the event in instead.
    try {
      this.routeEventId = eventIdFrom(this.route);
    } catch {
      this.routeEventId = null;
    }
  }

  ngOnInit(): void {
    this.api.event(this.eventId).subscribe({
      next: (event) => this.event.set(event),
      error: () => undefined,
    });

    this.api.ticketTypes(this.eventId).subscribe({
      next: ({ data }) => this.ticketTypes.set(data),
      error: () => undefined,
    });

    if (!this.formOnly()) this.load();
  }

  /**
   * Whether the code being edited can be aimed at ticket types.
   *
   * A code for all an organization's events cannot name one event's tickets,
   * so the choice is not offered for one rather than refused on save.
   */
  readonly canTarget = computed(() => this.editing()?.event_scoped ?? true);

  /** Every ticket, until one is unticked. */
  isCovered(id: string): boolean {
    const ids = this.form().ticket_type_ids;

    return ids.length === 0 || ids.includes(id);
  }

  toggleCovered(id: string): void {
    const all = this.ticketTypes().map((t) => t.id);
    const current = this.form().ticket_type_ids.length === 0 ? all : this.form().ticket_type_ids;
    const next = current.includes(id) ? current.filter((x) => x !== id) : [...current, id];

    // All ticked is stored as "every ticket", so a tier added later is covered
    // too — which is what somebody who never unticked anything meant.
    this.form.set({ ...this.form(), ticket_type_ids: next.length === all.length ? [] : next });
  }

  /** "12 orders · 30 tickets · $1,200.00 sold · $240.00 off", per currency. */
  describeSales(sales: CodeSales): string {
    const money = (amount: number) => formatMoney({ amount, currency: sales.currency });

    return [
      `${sales.orders} ${sales.orders === 1 ? 'order' : 'orders'}`,
      `${sales.tickets} ${sales.tickets === 1 ? 'ticket' : 'tickets'}`,
      `${money(sales.revenue)} sold`,
      ...(sales.discount > 0 ? [`${money(sales.discount)} off`] : []),
    ].join(' · ');
  }

  /** The conditions on a code, as the list shows them. */
  describeConditions(code: PromoCode): string | null {
    const parts = [
      code.unlocks.length > 0 ? `Unlocks ${code.unlocks.map((t) => t.name).join(', ')}` : null,
      code.ticket_types.length > 0 ? `On ${code.ticket_types.map((t) => t.name).join(', ')}` : null,
      code.min_quantity ? `${code.min_quantity}+ tickets` : null,
      code.max_per_customer ? `${code.max_per_customer} per buyer` : null,
    ].filter((p): p is string => p !== null);

    return parts.length > 0 ? parts.join(' · ') : null;
  }

  load(): void {
    this.loading.set(true);

    this.api.codes(this.eventId, this.page()).subscribe({
      next: ({ data, meta }) => {
        this.codes.set(data);
        this.meta.set(meta);
        this.loading.set(false);
      },
      error: (response) => {
        this.loading.set(false);
        this.error.set(messageFor(response, 'Could not load the codes for this event.'));
      },
    });
  }

  /** Another page of the list. */
  goToPage(page: number): void {
    this.page.set(page);
    this.load();
  }

  /** "20% off", "$5.00 off", or nothing when the code only attributes. */
  describeDiscount(code: PromoCode): string {
    if (code.discount_type === 'percentage' && code.discount_value !== null) {
      // Basis points back to a percentage, trimmed so 2000 reads as 20% and
      // 1250 still reads as 12.5%.
      return `${Number((code.discount_value / 100).toFixed(2))}% off`;
    }

    if (code.discount_type === 'fixed' && code.discount_value !== null) {
      return `${formatMoney({
        amount: code.discount_value,
        currency: code.discount_currency ?? this.currency(),
      })} off`;
    }

    return code.ref_slug ? 'Tracking only' : 'Presale access';
  }

  /**
   * What gets shared: for a presale code, the ticket page with the code
   * already in it — the tiers open on arrival — and otherwise the event link
   * carrying a promoter's slug.
   */
  linkFor(code: PromoCode): string | null {
    const slug = this.event()?.slug;

    if (!slug) return null;

    if (code.unlocks.length > 0) {
      const ref = code.ref_slug ? `&ref=${encodeURIComponent(code.ref_slug)}` : '';

      return `${this.publicOrigin()}/${slug}/tickets?access=${encodeURIComponent(code.code)}${ref}`;
    }

    if (!code.ref_slug) return null;

    // An event lives at the root — myfiesta.ca/{slug} — not under /events.
    // Getting this wrong hands every promoter a link that 404s, and they find
    // out from the people who followed it.
    return `${this.publicOrigin()}/${slug}?ref=${encodeURIComponent(code.ref_slug)}`;
  }

  copy(code: PromoCode): void {
    const link = this.linkFor(code);

    if (!link) return;

    void navigator.clipboard.writeText(link).then(
      () => this.copied.set(code.id),
      // Clipboard access can be refused, and silently doing nothing would look
      // like a broken button.
      () => this.error.set('Your browser would not let us copy. Select the link instead.'),
    );
  }

  async submit(): Promise<void> {
    // Said under the box already. Sent anyway, it would go as no discount.
    if (this.saving() || this.discountProblem()) return;

    const form = this.form();
    const editing = this.editing();
    const name = editing?.code ?? form.code.trim().toUpperCase();

    // What the code does, said back as a buyer would meet it: when it works,
    // from its own From and Until, and that it works for whoever has it —
    // it travels further than the people it was made for.
    const sure = await this.confirmDialog.confirm({
      title: editing ? `Save the changes to ${name}?` : `Make the code ${name}?`,
      body: `${this.draftEffect()}.`,
      consequences: [
        form.max_redemptions ? `It works ${Number(form.max_redemptions).toLocaleString()} times in all.` : 'It works any number of times.',
        whenCodeWorks({
          startsAt: form.starts_at ? zonedWallClockToIso(form.starts_at, this.zone()) : null,
          endsAt: form.ends_at ? zonedWallClockToIso(form.ends_at, this.zone()) : null,
          format: (iso) => this.moment(iso),
          editing: editing !== null,
        }),
        ...(editing ? ['Orders already placed with it keep what they paid.'] : []),
      ],
      confirmLabel: editing ? 'Save changes' : 'Make the code',
      tone: 'default',
    });

    if (!sure || this.saving()) return;

    this.saving.set(true);
    this.error.set(null);
    this.notice.set(null);

    if (editing) {
      this.saveEdit(editing, form);

      return;
    }

    this.api
      .createCode(this.eventId, {
        code: form.code.trim().toUpperCase(),
        label: form.label.trim() || null,
        discount_type: this.discounts() ? form.discount_type : null,
        discount_value: this.discounts() ? this.discountValue() : null,
        promoter_name: this.attributes() ? form.promoter_name.trim() || null : null,
        ref_slug: this.attributes() ? this.refSlug() : null,
        max_redemptions: form.max_redemptions ? Number(form.max_redemptions) : null,
        max_per_customer: form.max_per_customer ? Number(form.max_per_customer) : null,
        min_quantity: this.discounts() && form.min_quantity ? Number(form.min_quantity) : null,
        ticket_type_ids: this.discounts() ? form.ticket_type_ids : [],
        unlock_ticket_type_ids: form.unlock_ticket_type_ids,
        starts_at: form.starts_at ? zonedWallClockToIso(form.starts_at, this.zone()) : null,
        ends_at: form.ends_at ? zonedWallClockToIso(form.ends_at, this.zone()) : null,
        event_scoped: true,
      })
      .subscribe({
        next: () => {
          this.saving.set(false);
          this.notice.set('Code created.');
          this.form.set({
            ...form,
            code: '',
            label: '',
            discount_value: '',
            promoter_name: '',
            ref_slug: '',
            max_redemptions: '',
            max_per_customer: '',
            min_quantity: '',
            ticket_type_ids: [],
            unlock_ticket_type_ids: [],
            starts_at: '',
            ends_at: '',
          });
          if (this.formOnly()) {
            this.created.emit();
          } else {
            this.load();
          }
        },
        error: (response) => {
          this.saving.set(false);
          this.error.set(messageFor(response, 'That code could not be created.'));
        },
      });
  }

  private saveEdit(editing: PromoCode, form: ReturnType<EventCodes['form']>): void {
    // The code itself is absent from an edit: it is printed on posters and
    // typed off screenshots, so renaming it would break every place it has
    // already been shared. The server refuses it for the same reason.
    const body: Record<string, unknown> = {
      label: form.label.trim() || null,
      discount_type: this.discounts() ? form.discount_type : null,
      discount_value: this.discounts() ? this.discountValue() : null,
      promoter_name: this.attributes() ? form.promoter_name.trim() || null : null,
      ref_slug: this.attributes() ? this.refSlug() : null,
      max_redemptions: form.max_redemptions ? Number(form.max_redemptions) : null,
      max_per_customer: form.max_per_customer ? Number(form.max_per_customer) : null,
      min_quantity: this.discounts() && form.min_quantity ? Number(form.min_quantity) : null,
      ...(editing.event_scoped
        ? { ticket_type_ids: this.discounts() ? form.ticket_type_ids : [], unlock_ticket_type_ids: form.unlock_ticket_type_ids }
        : {}),
      starts_at: form.starts_at ? zonedWallClockToIso(form.starts_at, this.zone()) : null,
      ends_at: form.ends_at ? zonedWallClockToIso(form.ends_at, this.zone()) : null,
    };

    this.api.updateCode(this.eventId, editing.id, body).subscribe({
      next: () => {
        this.saving.set(false);
        this.notice.set('Code saved.');
        this.cancelEdit();
        this.load();
      },
      error: (response) => {
        this.saving.set(false);
        this.error.set(messageFor(response, 'That code could not be saved.'));
      },
    });
  }

  /** Load a code into the form. */
  edit(code: PromoCode): void {
    this.editing.set(code);
    this.error.set(null);
    this.notice.set(null);

    this.form.set({
      code: code.code,
      label: code.label ?? '',
      kind:
        code.discount_type && code.ref_slug
          ? 'both'
          : code.discount_type
            ? 'discount'
            : code.ref_slug
              ? 'promoter'
              : 'access',
      discount_type: code.discount_type ?? 'percentage',
      // Back to what somebody typed: basis points to a percentage, minor
      // units to an amount.
      discount_value:
        code.discount_value === null
          ? ''
          : code.discount_type === 'percentage'
            ? String(Number((code.discount_value / 100).toFixed(2)))
            : String(code.discount_value / 100),
      promoter_name: code.promoter_name ?? '',
      ref_slug: code.ref_slug ?? '',
      max_redemptions: code.max_redemptions === null ? '' : String(code.max_redemptions),
      max_per_customer: code.max_per_customer === null ? '' : String(code.max_per_customer),
      min_quantity: code.min_quantity === null ? '' : String(code.min_quantity),
      ticket_type_ids: code.ticket_types.map((t) => t.id),
      unlock_ticket_type_ids: code.unlocks.map((t) => t.id),
      starts_at: this.toLocalInput(code.starts_at),
      ends_at: this.toLocalInput(code.ends_at),
    });

    // The form is above the list, and on a long list the edit control that
    // was just pressed is off the top of the screen.
    this.document.getElementById('code')?.scrollIntoView({ block: 'center' });
  }

  cancelEdit(): void {
    this.editing.set(null);
    this.form.set({
      code: '',
      label: '',
      kind: 'discount',
      discount_type: 'percentage',
      discount_value: '',
      promoter_name: '',
      ref_slug: '',
      max_redemptions: '',
      max_per_customer: '',
      min_quantity: '',
      ticket_type_ids: [],
      unlock_ticket_type_ids: [],
      starts_at: '',
      ends_at: '',
    });
  }

  /**
   * An instant as a datetime-local control wants it: the wall clock at the
   * event. The control has no zone, and the save reads it back in the
   * event's, so both ends have to agree on which clock it is.
   */
  private toLocalInput(iso: string | null): string {
    return iso ? (isoToZonedWallClock(iso, this.zone()) ?? '') : '';
  }

  /** A moment in a code's window, on the event's wall clock. */
  private moment(iso: string): string {
    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
      timeZone: this.zone(),
    }).format(new Date(iso));
  }

  /** "Until Fri, 12 Sep" — the fact an organizer is scanning for. */
  describeWindow(code: PromoCode): string | null {
    const when = (iso: string) => this.moment(iso);

    if (code.starts_at && code.ends_at) return `${when(code.starts_at)} → ${when(code.ends_at)}`;
    if (code.ends_at) return `Until ${when(code.ends_at)}`;
    if (code.starts_at) return `From ${when(code.starts_at)}`;

    return null;
  }

  /** What the form's code will do, in the words a buyer would use: "Takes 20% off, and credits Tolu". */
  private draftEffect(): string {
    const form = this.form();
    const parts: string[] = [];

    if (this.discounts()) {
      const value = this.discountValue() ?? 0;

      parts.push(
        form.discount_type === 'percentage'
          ? `Takes ${value / 100}% off`
          : `Takes ${formatMoney({ amount: value, currency: this.currency() })} off`,
      );
    }

    if (this.attributes()) {
      parts.push(`${parts.length ? 'credits' : 'Credits'} ${form.promoter_name.trim() || 'a promoter'} with the sales`);
    }

    const unlocks = this.ticketTypes()
      .filter((type) => form.unlock_ticket_type_ids.includes(type.id))
      .map((type) => type.name);

    if (unlocks.length) parts.push(`${parts.length ? 'opens' : 'Opens'} ${unlocks.join(', ')}`);

    return parts.length ? parts.join(', and ') : 'Tracks the orders that use it';
  }

  /** Back on, keeping its uses and its sales. */
  async turnOn(code: PromoCode): Promise<void> {
    this.error.set(null);
    this.notice.set(null);

    const sure = await this.confirmDialog.confirm({
      title: `Turn ${code.code} back on?`,
      body: `Anybody who has it can use it again straight away: ${this.describeDiscount(code).toLowerCase()}.`,
      consequences: ['It keeps the uses and sales it had.'],
      confirmLabel: 'Turn it on',
      tone: 'default',
    });

    if (!sure) return;

    this.api.updateCode(this.eventId, code.id, { is_active: true }).subscribe({
      next: () => {
        this.notice.set(`${code.code} is on again.`);
        this.load();
      },
      error: (response) => this.error.set(messageFor(response, 'That code could not be turned on.')),
    });
  }

  async turnOff(code: PromoCode): Promise<void> {
    this.error.set(null);
    this.notice.set(null);

    const sure = await this.confirmDialog.confirm({
      title: `Turn off ${code.code}?`,
      body: 'It stops working at checkout straight away, for everybody who has it.',
      consequences: ['Orders already placed with it keep what they paid. You can turn it back on.'],
      confirmLabel: 'Turn it off',
      tone: 'danger',
    });

    if (!sure) return;

    this.api.deactivateCode(this.eventId, code.id).subscribe({
      next: (result) => {
        this.notice.set(result.message);
        this.load();
      },
      error: (response) => {
        this.error.set(messageFor(response, 'That code could not be turned off.'));
      },
    });
  }

  /**
   * A percentage in basis points, a fixed amount in minor units.
   *
   * Both integers. A rate that multiplies money must never be a float, and an
   * amount of money must never be one either.
   */
  private discountValue(): number | null {
    const { discount_type, discount_value } = this.form();

    if (!discount_value) return null;

    return discount_type === 'percentage'
      ? Math.round(Number(discount_value) * 100)
      : toMinorUnits(discount_value);
  }

  /** Defaults to the code itself, which is what a promoter would expect. */
  private refSlug(): string | null {
    const { ref_slug, code } = this.form();

    return (ref_slug.trim() || code.trim()).toLowerCase() || null;
  }

  private publicOrigin(): string {
    // The console and the public site are separate deployments, so the origin
    // cannot be inferred from this one. Named in a meta tag for the same reason
    // the API base is: a built bundle should not carry a hostname.
    const meta = this.document.querySelector<HTMLMetaElement>('meta[name="public-base"]');

    return meta?.content || 'https://myfiesta.ca';
  }
}
