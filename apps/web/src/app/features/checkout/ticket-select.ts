import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ConfirmDialog, UiSelect, type SelectOption } from '@myfiesta/ui';
import { Subscription } from 'rxjs';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { AddOn, Availability, EventDetail, Money, Quote, TicketType } from '../../core/api.types';
import { CheckoutStore } from '../../core/checkout-store';
import { EmbedMode, viewedOnce } from '../../core/embed';
import { formatMoney, formatPrice } from '../../core/money';
import { Seo } from '../../core/seo';
import { AvailabilityBadge } from '../../shared/availability-badge';
import { CheckoutSteps } from '../../shared/checkout-steps';
import { NotifyOnSalePart } from '../../shared/parts/notify-on-sale-part';

/**
 * Step one: which tickets, and how many.
 *
 * Its own page rather than a rail on the event page, so the decision gets
 * the width it needs — a tier is a card with room for what it includes, not
 * a row squeezed beside a stepper. Quantities are the only thing chosen
 * here; every figure in the bar below is the server's.
 */
@Component({
  selector: 'mf-ticket-select',
  standalone: true,
  // The part is a feature's own file, placed once in the template.
  imports: [RouterLink, FormsModule, UiSelect, CheckoutSteps, AvailabilityBadge, NotifyOnSalePart],
  templateUrl: './ticket-select.html',
})
export class TicketSelect {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly seo = inject(Seo);
  readonly store = inject(CheckoutStore);
  readonly embed = inject(EmbedMode);
  private readonly confirmDialog = inject(ConfirmDialog);

  readonly event = signal<EventDetail | null>(null);
  readonly notFound = signal(false);
  /** The API did not answer, which is not the same as there being no event. */
  readonly unavailable = signal(false);
  readonly quote = signal<Quote | null>(null);

  readonly formatMoney = formatMoney;
  readonly formatPrice = formatPrice;

  readonly slug = this.route.snapshot.paramMap.get('slug')!;

  readonly hasSelection = computed(() => this.store.count() > 0);

  // --- what the rail beside the tiers says -----------------------------------

  /** "Sat 3 Oct, 6:00 p.m." in the venue's zone, not the buyer's. */
  when(): string {
    const event = this.event();
    if (!event) return '';

    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
      timeZone: event.timezone,
    }).format(new Date(event.starts_at));
  }

  /** The letter that stands in for a poster nobody has uploaded yet. */
  initial(): string {
    return (this.event()?.title ?? '').trim().charAt(0).toUpperCase() || '?';
  }

  /** The room, and the city it is in when the room does not say so. */
  where(): string {
    const event = this.event();
    if (!event) return '';

    const venue = event.venue?.name;

    return venue && venue !== event.city ? `${venue}, ${event.city}` : (venue ?? event.city);
  }

  /**
   * The basket, named.
   *
   * The bar at the bottom could only ever say "4 tickets", which is the one
   * thing a buyer already knows. Four of which tier, and how the subtotal is
   * made of them, is the question the rail answers — and it is the last
   * chance to catch two Early Birds that were meant to be two General.
   */
/**
   * A line's own total.
   *
   * Minor units multiplied by a whole number, so there is nothing to round
   * and nothing to drift: two Early Birds at 4000 are 8000, and the sum of
   * these lines is the server's subtotal to the cent. What is charged is
   * still only ever the server's figure — these say what the rows add up to.
   */
  private lineTotal(price: Money, quantity: number): Money {
    return { amount: price.amount * quantity, currency: price.currency };
  }

  readonly chosen = computed(() => {
    const tiers = this.tiers();
    const addOns = this.addOns();
    const times = (price: Money, quantity: number) => this.lineTotal(price, quantity);

    const tickets = this.store.lines().flatMap((line) => {
      const tier = tiers.find((t) => t.id === line.ticket_type_id);

      return tier ? [{ id: tier.id, name: tier.name, quantity: line.quantity, total: times(tier.price, line.quantity) }] : [];
    });

    const extras = this.store.addOnLines().flatMap((line) => {
      const addOn = addOns.find((a) => a.id === line.add_on_id);

      return addOn ? [{ id: addOn.id, name: addOn.name, quantity: line.quantity, total: times(addOn.price, line.quantity) }] : [];
    });

    return [...tickets, ...extras];
  });

  /**
   * Nothing here costs anything: the basket as the server priced it, or,
   * before anything is chosen, every ticket and extra on the page.
   *
   * A free night read as a sale all the way through — "$0.00" beside each
   * line, "before you pay" under the button, "Details & payment" in the
   * steps — for a checkout that only ever reserves.
   */
  readonly free = computed(() => {
    const quote = this.quote();
    if (this.hasSelection() && quote) return quote.requires_payment === false;
    if (this.hasSelection()) return this.chosen().every((line) => line.total.amount === 0);

    const tiers = this.tiers();

    return tiers.length > 0 && tiers.every((t) => t.price.amount === 0) && this.addOns().every((a) => a.price.amount === 0);
  });

  /** The presale code field: closed until asked for, since most buyers have none. */
  readonly accessOpen = signal(false);
  readonly accessInput = signal('');
  readonly accessError = signal<string | null>(null);
  readonly unlocking = signal(false);

  /**
   * Nothing left to buy: every tier sold out, closed, ended, or waiting.
   *
   * The page used to end there. It now offers the waitlist, so a buyer who
   * missed out leaves an address instead of leaving for a resale post.
   */
  readonly nothingToBuy = computed(() => this.tiers().length > 0 && this.tiers().every((t) => !this.buyable(t)));

  /**
   * The waitlist, unless a tier stopped selling with places left. The API
   * calls that night closed rather than sold out and takes no names for it:
   * nothing is coming back to hand out, and the heading here says "Sold out".
   */
  readonly waitlistOpen = computed(
    () => this.nothingToBuy() && !this.tiers().some((t) => this.closed(t) && !this.soldOut(t)),
  );

  readonly waitEmail = signal('');
  readonly waitName = signal('');
  readonly waitQuantity = signal('1');
  readonly waitJoining = signal(false);
  readonly waitDone = signal<string | null>(null);
  readonly waitError = signal<string | null>(null);

  readonly quantityOptions: SelectOption[] = Array.from({ length: 10 }, (_, i) => ({
    value: String(i + 1),
    label: i === 0 ? '1 ticket' : `${i + 1} tickets`,
  }));

  async joinWaitlist(): Promise<void> {
    const email = this.waitEmail().trim();
    if (!email || this.waitJoining()) return;

    // The address is said back: there is no account behind it, so it is the
    // only way to reach this person, and a typo means never hearing.
    const quantity = Number(this.waitQuantity());
    const sure = await this.confirmDialog.confirm({
      title: `Join the waitlist for ${this.event()?.title ?? 'this event'}?`,
      body: `We email ${email} if ${quantity === 1 ? 'a place comes' : `${quantity} places come`} up. Nothing is held or charged.`,
      consequences: ['Places go to whoever buys first once people are told.'],
      confirmLabel: 'Join the waitlist',
      tone: 'default',
    });

    if (!sure || this.waitJoining()) return;

    this.waitJoining.set(true);
    this.waitError.set(null);

    this.api
      .joinWaitlist(this.slug, { email, name: this.waitName().trim() || undefined, quantity })
      .subscribe({
        next: ({ message }) => {
          this.waitJoining.set(false);
          this.waitDone.set(message);
        },
        error: (response) => {
          this.waitJoining.set(false);
          this.waitError.set(
            response?.status === 429
              ? 'Too many tries. Wait a minute and try again.'
              : (response?.error?.errors?.email?.[0] ?? response?.error?.message ?? 'That did not go through. Try again.'),
          );
        },
      });
  }

  /**
   * The tiers, with whatever a presale code opened merged in.
   *
   * Buyable first, then the ones waiting to open, then the done ones — a
   * sold-out tier stays on the page, dimmed, because "gone" is information.
   * Hidden stays hidden unless a code opened it.
   */
  readonly tiers = computed(() => {
    const unlocked = this.store.access()?.ticket_types ?? [];
    const unlockedIds = new Set(unlocked.map((t) => t.id));
    const publicTiers = (this.event()?.ticket_types ?? []).filter((t) => t.status !== 'hidden' && !unlockedIds.has(t.id));

    const rank = (t: TicketType) => (this.buyable(t) ? 0 : this.opensLater(t) ? 1 : 2);

    return [...unlocked, ...publicTiers].sort((a, b) => rank(a) - rank(b));
  });

  constructor() {
    // Inside an organizer's site this is the first thing anybody sees, so
    // it is where a look is counted. On ours, the event page counts it.
    if (this.embed.active() && viewedOnce(this.slug, true)) this.api.recordView(this.slug, true);

    this.store.loadFor(this.slug);

    // A promoter's ref, kept for the order as the event page keeps it. A link
    // can land here without passing the event page — the phone app sends
    // buyers straight to /{slug}/tickets?ref=… — and the promoter is owed the
    // sale all the same. Only when there is one: arriving from the event page,
    // its ref is kept already and the link to here does not repeat it.
    const ref = this.route.snapshot.queryParamMap.get('ref');
    if (ref) this.store.setRef(this.slug, ref);

    // A presale link: /{slug}/tickets?access=CODE opens the tiers straight away.
    const shared = this.route.snapshot.queryParamMap.get('access');
    if (shared && shared.toUpperCase() !== this.store.access()?.code) {
      this.accessInput.set(shared);
      this.accessOpen.set(true);
      this.unlock();
    }

    this.api.event(this.slug).subscribe({
      next: ({ data }) => {
        this.event.set(data);
        this.seo.forEvent(data, `https://myfiesta.ca/${data.slug}`);
        this.refreshQuote();
      },
      // As the event page does. It used to show "Event not found" and answer
      // 200 — a soft 404, kept by a search engine under whatever the link
      // said — and to say the same when the API had only failed to answer,
      // which is a page to come back to (503), not a page that is gone.
      error: (error: HttpErrorResponse) => {
        if (error.status === 404) {
          this.notFound.set(true);
          this.seo.notFound('Event not found');
        } else {
          this.unavailable.set(true);
          this.seo.unavailable('Event unavailable');
        }
      },
    });
  }

  quantity(id: string): number {
    return this.store.items()[id] ?? 0;
  }

  // --- the things that are not tickets -------------------------------------

  /** Only what is on sale reaches this page; a closed one is not offered. */
  readonly addOns = computed(() => this.event()?.add_ons ?? []);

  addOnQuantity(id: string): number {
    return this.store.addOns()[id] ?? 0;
  }

  adjustAddOn(addOn: AddOn, delta: number): void {
    if (addOn.sold_out && delta > 0) return;

    // Three ceilings, lowest wins: what the organizer allows per order, what
    // is left, and a sane bound for a page with steppers on it.
    const ceiling = Math.min(addOn.max_per_order ?? 20, addOn.remaining ?? 20, 20);
    const next = Math.min(Math.max(this.addOnQuantity(addOn.id) + delta, 0), ceiling);

    this.store.setAddOnQuantity(this.slug, addOn.id, next);
    this.refreshQuote();
  }

  addOnStepLabel(addOn: AddOn, direction: 'more' | 'fewer'): string {
    return `One ${direction} ${addOn.name}`;
  }

  /** Opened by the presale code this buyer holds. */
  isUnlocked(type: TicketType): boolean {
    return this.store.access()?.ticket_types.some((t) => t.id === type.id) ?? false;
  }

  /** Before its sales open, or waiting on the tier before it — and not opened by a code. */
  opensLater(type: TicketType): boolean {
    if (this.isUnlocked(type) || type.status !== 'on_sale') return false;

    return type.waiting || (!!type.sales_start_at && new Date(type.sales_start_at) > new Date());
  }

  // --- how much is left ----------------------------------------------------

  /**
   * How each tier is selling as of the last quote.
   *
   * The page's badges are from when it loaded; somebody choosing for five
   * minutes can be choosing a tier that went meanwhile. Every change asks for
   * a quote, and the quote says how each tier stands now — so the badge moves,
   * and a tier that went is taken out of the basket here rather than refused
   * at the payment step.
   */
  readonly live = signal<Record<string, Availability>>({});

  /** Said out loud when the quote changed the basket: what went, and what is left in it. */
  readonly notice = signal<string | null>(null);

  readonly soldOutBadge: Availability = { state: 'sold_out', left: null };

  availabilityOf(type: TicketType): Availability | undefined {
    return this.live()[type.id] ?? type.availability;
  }

  soldOut(type: TicketType): boolean {
    return type.status === 'sold_out' || type.sold_out || this.availabilityOf(type)?.state === 'sold_out';
  }

  /**
   * Stopped selling with places left: closed by the organizer, or past its
   * end — as the page loaded it, or as the last quote says. "Sales closed",
   * never "Sold out".
   */
  closed(type: TicketType): boolean {
    return type.status === 'closed' || this.salesEnded(type) || this.availabilityOf(type)?.state === 'closed';
  }

  /** The exact number left, when the API names one (only once it is small). */
  left(type: TicketType): number | null {
    return this.availabilityOf(type)?.left ?? null;
  }

  /** No more of this one: the organizer's limit per order, or what is left. */
  atCeiling(type: TicketType): boolean {
    return this.quantity(type.id) >= this.ceiling(type);
  }

  /**
   * The organizer's limit per order — as many as they allow, which can be
   * more than twenty — and what is left once the API names it. No bound of
   * the page's own: one of twenty copied from the add-ons stopped a buyer at
   * twenty of a tier the organizer sells thirty to an order.
   */
  private ceiling(type: TicketType): number {
    return Math.min(type.max_per_order ?? 20, this.left(type) ?? Infinity);
  }

  /**
   * Take in what the quote says about each tier, and put the basket right.
   *
   * A tier that sold out comes out of the basket; one with fewer left than
   * were chosen comes down to what is left. Either is said, in words, where a
   * screen reader hears it. Nothing else is changed.
   */
  private absorb(quote: Pick<Quote, 'availability'> | null | undefined): boolean {
    // A quote that says nothing about stock — an older API, or none at all —
    // leaves the badges as the page loaded them.
    if (!quote?.availability) return false;

    const now = Object.fromEntries(quote.availability.map(({ ticket_type_id, ...shown }) => [ticket_type_id, shown]));
    this.live.set(now);

    const changed: string[] = [];

    for (const line of this.store.lines()) {
      const shown = now[line.ticket_type_id];
      const tier = this.tiers().find((t) => t.id === line.ticket_type_id);
      if (!shown || !tier) continue;

      if (shown.state === 'sold_out') {
        this.store.setQuantity(this.slug, tier.id, 0);
        changed.push(`${tier.name} sold out while you were choosing, so it has been taken out of your order.`);
      } else if (shown.state === 'closed') {
        this.store.setQuantity(this.slug, tier.id, 0);
        changed.push(`Sales for ${tier.name} closed while you were choosing, so it has been taken out of your order.`);
      } else if (shown.left !== null && line.quantity > shown.left) {
        this.store.setQuantity(this.slug, tier.id, shown.left);
        changed.push(`Only ${shown.left} ${tier.name} ${shown.left === 1 ? 'is' : 'are'} left, so your order now has ${shown.left}.`);
      }
    }

    if (changed.length > 0) this.notice.set(changed.join(' '));

    return changed.length > 0;
  }

  salesEnded(type: TicketType): boolean {
    return !!type.sales_end_at && new Date(type.sales_end_at) <= new Date();
  }

  /** Whether the steppers work: the same rules the server prices by. */
  buyable(type: TicketType): boolean {
    if (this.closed(type) || this.soldOut(type)) return false;
    if (this.isUnlocked(type)) return type.status === 'on_sale' || type.status === 'hidden';

    return type.status === 'on_sale' && !this.opensLater(type);
  }

  /** "Fri 19 Sep, 10:00 a.m." in the event's own zone. */
  opensAt(type: TicketType): string {
    const zone = this.event()?.timezone;

    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
      ...(zone ? { timeZone: zone } : {}),
    }).format(new Date(type.sales_start_at!));
  }

  unlock(): void {
    const code = this.accessInput().trim();
    if (!code || this.unlocking()) return;

    this.unlocking.set(true);
    this.accessError.set(null);

    this.api.unlock(this.slug, code).subscribe({
      next: (access) => {
        this.unlocking.set(false);
        this.store.setAccess(this.slug, access);
        this.accessOpen.set(false);
        this.accessInput.set('');
        this.refreshQuote();
      },
      error: (response) => {
        this.unlocking.set(false);
        this.accessError.set(
          response?.status === 429
            ? 'Too many tries. Wait a minute and try again.'
            : (response?.error?.message ?? 'That code could not be checked. Try again.'),
        );
      },
    });
  }

  removeAccess(): void {
    this.store.setAccess(this.slug, null);
    this.refreshQuote();
  }

  adjust(type: TicketType, delta: number): void {
    if (!this.buyable(type) && delta > 0) return;

    // The organizer's limit per order, or what is left when the API names
    // it — whichever is lower. Checkout counts again; this only stops a
    // stepper from offering a sixth of five.
    const next = Math.min(Math.max(this.quantity(type.id) + delta, 0), this.ceiling(type));

    this.notice.set(null);
    this.store.setQuantity(this.slug, type.id, next);
    this.refreshQuote();
  }

  stepLabel(type: TicketType, direction: 'more' | 'fewer'): string {
    return `One ${direction} ${type.name}`;
  }

  continueToCheckout(): void {
    if (!this.hasSelection()) return;
    void this.router.navigate(this.embed.checkout(this.slug));
  }

  private quoting?: Subscription;

  private refreshQuote(): void {
    const lines = this.store.lines();

    if (lines.length === 0) {
      this.quoting?.unsubscribe();
      this.quote.set(null);
      return;
    }

    // Only the newest answer counts. Two taps in quick succession are two
    // quotes in flight, and the older one landing last priced a basket that
    // no longer existed — a subtotal with a ticket in it that was not.
    this.quoting?.unsubscribe();
    this.quoting = this.api
      .quote(this.slug, {
        items: lines,
        add_ons: this.store.addOnLines(),
        ref: this.store.ref() ?? undefined,
        access_code: this.store.access()?.code,
      })
      .subscribe({
        next: (quote) => {
          // The basket was put right: this price is for what was in it
          // before, so it is not shown, and what is in it now is priced.
          if (this.absorb(quote)) {
            this.quote.set(null);
            this.refreshQuote();

            return;
          }

          this.quote.set(quote);
        },
        error: (response: HttpErrorResponse) => {
          this.quote.set(null);

          // A refusal carries the same news when a tier was closed rather
          // than sold — "not currently on sale" — so the basket is put right
          // from it too, and priced again without the tier that went.
          if (this.absorb(response?.error)) this.refreshQuote();
        },
      });
  }
}
