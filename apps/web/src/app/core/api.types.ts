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
}

/** A photograph from the night. The caption doubles as alt text. */
export interface GalleryImage {
  url: string;
  thumb_url: string;
  caption: string | null;
  width: number | null;
  height: number | null;
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
  organizer: OrganizerRef & {
    description: string | null;
    is_verified: boolean;
    logo_url: string | null;
  };
  ticket_types: TicketType[];
}

export interface QuoteLine {
  ticket_type_id: string;
  name: string;
  quantity: number;
  unit_price: Money;
  line_total: Money;
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
export interface OrderStatus {
  reference: string;
  status: 'pending' | 'paid' | 'failed' | 'cancelled' | 'refunded' | 'partially_refunded';
  total: Money;
  ticket_count: number;
  event: { slug: string; title: string; starts_at: string; timezone: string };
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
    id_required: boolean;
    organizer: string;
  };
  tickets: HeldTicket[];
}

/** Everything the front page needs, in one response. */
export interface Discovery {
  featured: EventSummary[];
  upcoming: EventSummary[];
  /** Only places with something on — a filter leading nowhere is worse than none. */
  cities: { city: string; country: string; events: number }[];
  categories: { category: string; events: number }[];
}
