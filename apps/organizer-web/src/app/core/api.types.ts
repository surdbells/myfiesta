/**
 * Shapes the API actually returns.
 *
 * Hand-written for now and deliberately narrow — only what this console reads.
 * packages/contract generates the full TypeScript client, and once the
 * organizer paths are added to that spec these come from there instead. Until
 * then a conformance test guards the public half and this half is small enough
 * to keep honest by hand.
 */

/** Never a bare number. An amount without its currency is a guess. */
export interface Money {
  amount: number;
  currency: 'CAD' | 'NGN';
}

export type Role = 'owner' | 'manager' | 'finance' | 'marketing' | 'door';

/**
 * Every capability the platform recognises.
 *
 * Mirrors App\Enums\Permission on the API, which is the authority. This type
 * exists so a typo at a call site is a compile error rather than a silently
 * false permission check.
 */
export type Permission =
  | "events.view"
  | "events.create"
  | "events.edit"
  | "events.publish"
  | "events.cancel"
  | "events.delete"
  | "tickets.manage"
  | "codes.manage"
  | "attendees.view"
  | "door.scan"
  | "money.view"
  | "refunds.process"
  | "messages.send";

export interface Membership {
  id: string;
  name: string;
  slug: string;
  role: Role;
  /** Resolved by the server. The console reads this and never derives it. */
  permissions: Permission[];
  verified?: boolean;
}

export interface Session {
  token: string;
  user: { name: string; email: string };
  abilities: string[];
  organizations: Membership[];
}

export type EventStatus = 'draft' | 'review' | 'scheduled' | 'published' | 'cancelled';

export interface OrganizerEvent {
  id: string;
  slug: string;
  title: string;
  kind: 'ticketed' | 'invitation';
  status: EventStatus;
  starts_at: string;
  timezone: string;
  city: string;
  currency: 'CAD' | 'NGN';
  tickets_issued: number;
  checked_in: number;
}

/**
 * One event in full, as the edit form needs it.
 *
 * Deliberately not the same shape as the list. A list row shows a title, a date
 * and two counts; a form has to round-trip every field it may change, and
 * conflating the two would send a description and an address for every row of a
 * table that displays neither.
 */
export interface OrganizerEventDetail extends OrganizerEvent {
  description: string | null;
  ends_at: string | null;
  subdivision: string | null;
  country: string;
  category: string | null;
  min_age: number | null;
  id_required: boolean;
  poster_url: string | null;
}

export interface TicketType {
  id: string;
  name: string;
  description: string | null;
  price: Money;
  admits: number;
  max_per_order: number | null;
  quantity_available?: number | null;
  status: 'on_sale' | 'sold_out' | 'hidden' | 'closed';
}

/**
 * The money behind one event, read from the ledger.
 *
 * Every figure arrives as an amount with a currency so the console never has
 * to decide what a number means.
 */
export interface EventSummary {
  currency: 'CAD' | 'NGN';
  gross: Money;
  discounts: Money;
  tax: Money;
  commission: Money;
  refunds: Money;
  net: Money;
  orders: number;
  tickets_issued: number;
  checked_in: number;
}

/** Tickets minted by hand: comps, guest list, cash at the door. */
export interface IssueResult {
  message: string;
  tickets: { id: string; code: string; admits: number; holder_name: string }[];
}

export interface Guest {
  id: string;
  name: string;
  email: string;
  ticket_type: string | null;
  checked_in: boolean;
  checked_in_at: string | null;
}

export interface GuestPage {
  data: Guest[];
  meta: { total: number; checked_in: number; next: string | null };
}

/**
 * One object covering discounts and promoter attribution, because in nightlife
 * the discount code is how a promoter proves they drove the sale.
 */
export interface PromoCode {
  id: string;
  code: string;
  label: string | null;
  discount_type: 'percentage' | 'fixed' | null;
  discount_value: number | null;
  discount_currency: Money['currency'] | null;
  ref_slug: string | null;
  promoter_name: string | null;
  redemption_count: number;
  max_redemptions: number | null;
  is_active: boolean;
  event_scoped: boolean;
  /** Whether it would actually work right now — the question being asked. */
  usable: boolean;
}

/**
 * An order as the refunds screen needs it.
 *
 * Ticket codes are deliberately absent — this list is read on a laptop in an
 * office, and a ticket code is the thing that opens a door.
 */
export interface OrderTicket {
  id: string;
  holder_name: string | null;
  ticket_type_name: string | null;
  status: string;
  refundable: boolean;
}

export interface SoldOrder {
  id: string;
  reference: string;
  buyer_name: string;
  buyer_email: string;
  status: 'paid' | 'partially_refunded' | 'refunded';
  paid_at: string | null;
  currency: Money['currency'];
  total: Money;
  refunded: Money;
  refundable: Money;
  tickets: OrderTicket[];
}

export interface RefundResult {
  id: string;
  status: string;
  amount: Money;
  tax: Money;
  reason: string | null;
  ticket_ids: string[];
  created_at: string;
}

/** A picture on an event — the banner, or one of the gallery. */
export interface EventImage {
  id: string;
  kind: 'banner' | 'gallery';
  url: string;
  thumb_url: string;
  display_url: string;
  caption: string | null;
  width: number | null;
  height: number | null;
  position: number;
}

export interface EventImages {
  banner: EventImage | null;
  gallery: EventImage[];
}

/** A scheduled nudge to everyone holding a ticket. */
export interface Reminder {
  id: string;
  offset_minutes: number;
  label: string;
  send_at: string;
  status: 'scheduled' | 'sending' | 'sent' | 'cancelled';
  sent_at: string | null;
  recipients: number | null;
}

/** One date in a repeating event. */
export interface SeriesOccurrence {
  id: string;
  slug: string;
  title: string;
  starts_at: string;
  series_occurs_at: string;
  status: string;
  /** The organizer moved this one off the date the rule scheduled. */
  moved: boolean;
  is_source: boolean;
}

export interface Series {
  id: string;
  rrule: string;
  status: 'active' | 'paused' | 'ended';
  timezone: string;
  generated_through: string | null;
  source_event_id: string;
  occurrences: SeriesOccurrence[];
  skipped: { occurs_at: string; reason: string | null }[];
}

/** What the door gets back from one scan. */
export interface ScanResult {
  result: string;
  /** Whether anybody went in. A table can be partly admitted. */
  accepted: boolean;
  admitted: number;
  /** Still outstanding on this ticket — what keeps a table open. */
  remaining: number;
  message: string;
  ticket: {
    holder_name: string | null;
    type: string | null;
    admits: number;
    admitted_count: number;
  } | null;
}

/** One message an organizer sent to their ticket holders. */
export interface AttendeeMessage {
  id: string;
  subject: string;
  body: string;
  /** Overrides an opt-out. For news somebody needs before they travel. */
  important: boolean;
  status: string;
  sent_at: string | null;
  recipients: number | null;
  suppressed: number | null;
}

/** Who holds a ticket, and how many of them are reachable. */
export interface MessageAudience {
  holders: number;
  reachable: number;
}
