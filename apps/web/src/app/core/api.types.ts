/**
 * Response shapes, kept in step with packages/contract.
 *
 * Hand-written here rather than imported from the generated TypeScript,
 * because the generator emits a path-keyed `paths` interface that is awkward to
 * consume in components. The generated schema is still the check: a conformance
 * test on the API side asserts these fields exist, so drift fails a build
 * rather than a page.
 */

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
  timezone: string;
  city: string;
  country: string;
  currency: Money['currency'];
  category: string | null;
  poster_url: string | null;
  organizer: OrganizerRef;
  from_price: Money | null;
  is_sold_out: boolean;
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
  code_applied: string | null;
  /** The presale code that opened a locked ticket in this basket. */
  access_code_applied: string | null;
  /** The ticket types the code discounts; null when it covers every ticket. */
  code_applies_to: string[] | null;
  requires_payment: boolean;
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
  code: string;
  type: string | null;
  holder: string | null;
  status: string;
  /** How many people this one lets in — a Couple admits 2, a Table of 5 admits 5. */
  admits: number;
  /** How many of them are already inside. A table can arrive in two groups. */
  admitted: number;
  /** SVG markup, drawn by the server from the ticket code. */
  qr: string;
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
}

/** Everything the front page needs, in one response. */
export interface Discovery {
  featured: EventSummary[];
  upcoming: EventSummary[];
  /** Only places with something on — a filter leading nowhere is worse than none. */
  cities: { city: string; country: string; events: number }[];
  categories: { category: string; events: number }[];
}
