/**
 * Response shapes, kept in step with packages/contract.
 *
 * Hand-written here rather than imported from the generated TypeScript,
 * because the generator emits a path-keyed `paths` interface that is awkward to
 * consume in components. The generated schema is still the check: a conformance
 * test on the API side asserts these fields exist, so drift fails a build
 * rather than a page.
 */

import type { Availability } from '@myfiesta/shared/availability';

export type { Availability };

/**
 * An integer amount in minor units, with its currency.
 *
 * Never a float and never preformatted. The platform this replaces returned
 * "$1,234.00" from the API and re-parsed it in the client.
 */
export interface Money {
  amount: number;
  currency: 'CAD' | 'NGN';
}

export interface OrganizerRef {
  name: string;
  slug: string;
}

/**
 * An organizer as a reader meets them: the name, the mark, the words they
 * wrote, and whether somebody checked who they are.
 *
 * The same block on an event page and on their own page, so the two cannot
 * drift into describing two different organizers.
 */
export interface OrganizerBrand extends OrganizerRef {
  description: string | null;
  is_verified: boolean;
  logo_url: string | null;
  /**
   * Whether the reader holding this token follows them. Always false on this
   * site, which has no accounts to hold one — following is done in the app.
   * Never a count: an organizer is told how many follow them, nobody is told
   * who.
   */
  following?: boolean;
}

/** An organizer's own page: who they are, what is on, what has been. */
export interface OrganizerPage extends OrganizerBrand {
  upcoming: EventSummary[];
  /** The most recent nights that have already happened, newest first. */
  past: EventSummary[];
}

export interface EventSummary {
  slug: string;
  title: string;
  starts_at: string;
  /** When the organizer said it ends. Optional only for pages cached before cards carried it. */
  ends_at?: string | null;
  timezone: string;
  city: string;
  country: string;
  currency: Money['currency'];
  category: string | null;
  poster_url: string | null;
  organizer: OrganizerRef;
  /** The cheapest tier still selling; once none is, the cheapest there was. */
  from_price: Money | null;
  is_sold_out: boolean;
  /**
   * "Almost sold out", "Sold out" — counted the way checkout counts, and
   * never an exact number above the admin's setting. Optional only because
   * pages cached before it existed may still be in somebody's browser.
   */
  availability?: Availability;
  /** Sold out and not started, so the waitlist is taking names. */
  waitlist?: boolean;
}

export interface Venue {
  name: string | null;
  address: string | null;
  city: string | null;
}

export interface TicketType {
  id: string;
  name: string;
  description: string | null;
  price: Money;
  admits: number;
  max_per_order: number | null;
  status: 'on_sale' | 'sold_out' | 'hidden' | 'closed';
  /** When sales open and close. Before the start it is a presale: a code opens it early. */
  sales_start_at: string | null;
  sales_end_at: string | null;
  /** Every place taken, counting baskets in progress. */
  sold_out: boolean;
  /** The badge beside the tier: "Almost sold out", "Only 4 left", "Sold out". */
  availability?: Availability;
  /** A price ladder: this tier waits for that one to sell out. */
  opens_after: { id: string; name: string } | null;
  waiting: boolean;
}

/** What a presale code opens. */
export interface AccessUnlock {
  code: string;
  ticket_types: TicketType[];
}

/** A photograph from the night. The caption doubles as alt text. */
export interface GalleryImage {
  url: string;
  thumb_url: string;
  caption: string | null;
  width: number | null;
  height: number | null;
}

/**
 * Something sold with a ticket that is not one.
 *
 * A table, a bottle, a shirt. It has a price and a stock and it goes on the
 * same order — and it admits nobody, which is why buying one mints no ticket
 * and the door never hears about it.
 */
export interface AddOn {
  id: string;
  name: string;
  description: string | null;
  price: Money;
  max_per_order: number | null;
  /** What is genuinely left, baskets in progress counted. Null is unlimited. */
  remaining: number | null;
  sold_out: boolean;
}

/**
 * Something the organizer asks at checkout.
 *
 * The shape of the question only. Whether an answer is acceptable is decided
 * by the server against its own rows — this is what to render, not what to
 * enforce.
 */
export interface Question {
  id: string;
  label: string;
  type: 'text' | 'choice' | 'multi_choice' | 'boolean';
  /** What may be chosen. Empty for a question that is typed into. */
  options: string[];
  required: boolean;
  /**
   * Asked once for the whole order, or once about each person on it. A name
   * for the door is per attendee; how somebody heard about the night is not.
   */
  per_attendee: boolean;
}

/** An answer on its way to the server: words, a choice, several, or yes/no. */
export type AnswerValue = string | string[] | boolean;

/** One person on an order, and what was asked about them. */
export interface Attendee {
  ticket_type_id: string;
  answers: Record<string, AnswerValue>;
}

export interface EventDetail extends EventSummary {
  /**
   * Formatted HTML, sanitized by the server to an allowlist — safe to render
   * as markup. Never put it in a meta tag or anywhere else that shows text.
   */
  description: string | null;
  /** The same description as plain words, for meta tags and link previews. */
  description_text: string | null;
  /**
   * The banner cropped to exactly 1200×630 — what the social networks read.
   * Distinct from poster_url, which is a different shape and gets cropped by
   * whichever of them is doing the cropping.
   */
  og_image_url: string | null;
  gallery: GalleryImage[];
  ends_at: string | null;
  subdivision: string | null;
  dress_code: string | null;
  min_age: number | null;
  id_required: boolean;
  venue: Venue | null;
  organizer: OrganizerBrand;
  ticket_types: TicketType[];
  /** What the organizer asks at checkout. Empty when they ask nothing. */
  questions: Question[];
  /** Sold beside a ticket and admitting nobody. Empty when there is nothing extra. */
  add_ons: AddOn[];
  calendar: CalendarLinks;
}

export interface QuoteLine {
  /**
   * Which of the two this line is.
   *
   * Said by the server rather than inferred from a null: a client counting
   * people to ask questions of must not count bottles.
   */
  kind: 'ticket' | 'add_on';
  ticket_type_id: string | null;
  add_on_id: string | null;
  name: string;
  quantity: number;
  unit_price: Money;
  line_total: Money;
  /** This line's share of the discount — zero on tickets the code does not cover. */
  discount: Money;
}

export interface Quote {
  lines: QuoteLine[];
  subtotal: Money;
  discount: Money;
  tax: Money;
  /**
   * The 8% the buyer pays on top — always sent by the server, and shown as
   * its own line. Folding it silently into the total made the total
   * unexplainable from the lines above it.
   */
  service_charge: Money;
  /** What the organizer is paid: the ticket money, before tax and our charge. */
  net_revenue: Money;
  total: Money;
  /** Whether tax was already inside the displayed price, or added at checkout. */
  tax_inclusive: boolean;
  tax_label: string | null;
  /**
   * Each tax on its own, the way the receipt will show it: GST and QST side
   * by side in Quebec, and the service charge's own, marked
   * `on: 'service_charge'`, where it is taxed. The ticket ones add up to
   * `tax`.
   */
  tax_lines: ReceiptTax[];
  /** How much of `service_charge` is tax on it. Zero unless it is taxed. */
  service_charge_tax: Money;
  code_applied: string | null;
  /** The presale code that opened a locked ticket in this basket. */
  access_code_applied: string | null;
  /** The ticket types the code discounts; null when it covers every ticket. */
  code_applies_to: string[] | null;
  requires_payment: boolean;
  /**
   * How each tier is selling as of this quote — so a tier that sold out
   * while somebody chose says so here, before checkout refuses it.
   */
  availability?: (Availability & { ticket_type_id: string })[];
}

export interface OrderCreated {
  reference: string;
  status: 'pending' | 'paid';
  /** Null when there is nothing to charge — a comp, a full-value code, a free event. */
  payment: {
    gateway: 'stripe' | 'paystack';
    redirect_url: string;
    expires_at: string | null;
  } | null;
}

export interface Page<T> {
  data: T[];
  meta?: { has_more?: boolean; next_cursor?: string | null };
  links?: { next?: string | null; prev?: string | null };
}

/** What /api/orders/{reference} returns while a buyer waits for the webhook. */
/** "Add to calendar": a file for Apple and Outlook, a link for Google. */
export interface CalendarLinks {
  ics_url: string;
  google_url: string;
}

export interface OrderStatus {
  reference: string;
  status: 'pending' | 'paid' | 'failed' | 'cancelled' | 'refunded' | 'partially_refunded';
  total: Money;
  ticket_count: number;
  event: { slug: string; title: string; starts_at: string; timezone: string; calendar: CalendarLinks };
}

/** One ticket as its holder sees it, with the symbol a door reads. */
export interface HeldTicket {
  id: string;
  code: string | null;
  type: string | null;
  holder: string | null;
  status: string;
  /** How many people this one lets in — a Couple admits 2, a Table of 5 admits 5. */
  admits: number;
  /** How many of them are already inside. A table can arrive in two groups. */
  admitted: number;
  /**
   * SVG markup, drawn by the server from the ticket code.
   *
   * Null once the ticket has been handed back: a QR that will be turned away
   * is worse than none, because its holder finds out at the front of the
   * queue.
   */
  qr: string | null;
  /** Whether it can be given back, and if not, the reason to show. */
  return: { listed: boolean; refusal: string | null };
}

export interface TicketAccess {
  reference: string;
  status: string;
  buyer_name: string | null;
  event: {
    slug: string;
    title: string;
    starts_at: string;
    timezone: string;
    venue: string | null;
    address: string | null;
    city: string;
    min_age: number | null;
    calendar: CalendarLinks;
    id_required: boolean;
    organizer: string;
  };
  tickets: HeldTicket[];
  /**
   * What else was on the order: a table, a bottle, a shirt.
   *
   * An add-on has no code and nothing to scan, so this screen is the only
   * evidence its buyer holds of it.
   */
  extras: { name: string; quantity: number }[];
  /** What was paid, to whom, and each tax on it — the same receipt the email carries. */
  receipt: Receipt;
}

/**
 * One tax on an order, with its own rate.
 *
 * Quebec with QST collected has GST and QST side by side; a taxed service
 * charge has its own lines, marked `on: 'service_charge'`.
 */
export interface ReceiptTax {
  /** GST, HST, QST, VAT. */
  name: string;
  /** A percentage as written: "5", "9.975", "13", "7.5". */
  rate: string;
  on: 'tickets' | 'service_charge';
  /** Inside the price it was charged on (Nigeria), rather than added to it. */
  included: boolean;
  amount: Money;
}

/** Somebody named on a receipt, with the numbers their taxes are filed under. */
export interface ReceiptParty {
  name: string | null;
  address: string | null;
  registrations: { label: string; number: string }[];
}

/**
 * What an order says about itself as a receipt.
 *
 * Read from the order's own copy of how it was priced, so it says what was
 * charged at the time, whatever the settings say now. Never carries a ticket
 * code. Adds up: subtotal, less discount, plus every tax not `included`, plus
 * the service charge, is the total.
 */
export interface Receipt {
  reference: string;
  issued_at: string;
  currency: Money['currency'];
  seller_of_record: 'organizer' | 'platform';
  /** Who sold the tickets. */
  seller: ReceiptParty;
  /** Who sold the booking service — the service charge — where that is not the seller. */
  service: ReceiptParty | null;
  /** The organizer, named where the platform was the seller. */
  organizer: string | null;
  lines: { name: string; quantity: number; unit_price: Money; discount: Money; amount: Money }[];
  subtotal: Money;
  discount: Money;
  taxes: ReceiptTax[];
  /** Before any tax added to it; with its tax inside where prices include tax. */
  service_charge: Money;
  total: Money;
  tax_included: boolean;
  /** Money given back since, if any. */
  refunded: Money;
}

/** A category with something on, and the poster that stands for it. */
export interface CategoryPlace {
  category: string;
  /** Its page: /events/category/{slug}. Sent by the API, never made here. */
  slug: string;
  events: number;
  /** The next night's poster, a featured one first; null draws the site's own. */
  cover_url: string | null;
}

export interface CityPlace {
  city: string;
  country: string;
  /** Its page: /events/city/{slug}. */
  slug: string;
  events: number;
  cover_url: string | null;
}

/** Everything the front page needs, in one response. */
export interface Discovery {
  featured: EventSummary[];
  upcoming: EventSummary[];
  /** Still on or still to come today, in each event's own zone. */
  today?: EventSummary[];
  /** Friday to Sunday, the one underway or the next. */
  weekend?: EventSummary[];
  /** Still to come after today and outside this weekend, soonest first. */
  later?: EventSummary[];
  almost_sold_out?: EventSummary[];
  /** Still to come first — each taking waitlist names — then recently sold out. */
  sold_out?: EventSummary[];
  /** Ended in the last 90 days, most recent first. */
  past?: EventSummary[];
  /** Only places with something on — a filter leading nowhere is worse than none. */
  cities: (Pick<CityPlace, 'city' | 'country' | 'events'> & Partial<CityPlace>)[];
  categories: (Pick<CategoryPlace, 'category' | 'events'> & Partial<CategoryPlace>)[];
  /** Counted by the database, never claimed. */
  totals?: { upcoming: number; cities: number };
  /** The buyer's service charge per currency, as a person writes it: "8", "8.5". */
  fees?: { service_charge: Partial<Record<Money['currency'], string>> };
}

/** The listing's filters and the search's city picker. */
export interface Facets {
  categories: CategoryPlace[];
  cities: CityPlace[];
}

/**
 * Who operates the platform and how to reach them, from the API's config.
 *
 * Every field may be missing until the operator fills it in; `complete` says
 * whether the legal pages have what they need, and they say so when not.
 */
export interface ContactDetails {
  company_name: string | null;
  company_number: string | null;
  support_email: string | null;
  privacy_email: string | null;
  phone: string | null;
  addresses: { country: string; address: string }[];
  complete: boolean;
}
