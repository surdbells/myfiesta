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

/**
 * The states an event can actually be in.
 *
 * review and scheduled were in the schema and unreachable by any code path.
 * Mirrors App\Enums\EventStatus, which owns the transitions.
 */
export type EventStatus = 'draft' | 'published' | 'cancelled';

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
  /** Null is unlimited, and is not the same as zero. */
  quantity_available: number | null;
  status: 'on_sale' | 'sold_out' | 'hidden' | 'closed';

  /** Tickets that exist against the door. A refund gives its place back. */
  sold: number;
  /** Null where the tier is unlimited — there is nothing to count down. */
  remaining: number | null;

  sales_start_at: string | null;
  sales_end_at: string | null;
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
  /**
   * What buyers paid the platform on top of the ticket price.
   *
   * Not deducted from `net`. It is here so an organizer can reconcile against
   * what a buyer tells them they were charged.
   */
  service_charge: Money;
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

/**
 * Where a page of a list sits in the whole of it.
 *
 * The same on every organizer list that pages. Read by ui-pagination: a list
 * that stops at its first page with nothing to say so is how an order that
 * needed refunding went missing.
 */
export interface PageMeta {
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
  next: string | null;
}

export interface Page<T> {
  data: T[];
  meta: PageMeta;
}

export interface GuestPage {
  data: Guest[];
  meta: PageMeta & { checked_in: number };
}

/** An event as a filter offers it: every one, not a page of them. */
export interface EventOption {
  id: string;
  title: string;
  starts_at: string;
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
  max_per_customer: number | null;
  /** The window the code works in. Enforced at checkout; null means always. */
  starts_at: string | null;
  ends_at: string | null;
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
  /** Upcoming only; past_count says how many have already happened. */
  occurrences: SeriesOccurrence[];
  past_count: number;
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
  /** Decided on this phone from its saved list, with no connection. */
  offline?: boolean;
  /** What the door did offline, echoed back when a queued scan is synced. */
  offline_result?: string | null;
  /** Where the offline door and the server disagreed. */
  conflict?: 'admitted_invalid' | 'refused_valid' | null;
}

/** One ticket in the list a door phone keeps for when signal goes. */
export interface DoorListTicket {
  /** PBKDF2 of the code — enough to recognise one, never enough to show one. */
  hash: string;
  status: string;
  admits: number;
  admitted_count: number;
  holder_name: string | null;
  type: string | null;
}

export interface DoorList {
  event_id: string;
  salt: string;
  iterations: number;
  generated_at: string;
  tickets: DoorListTicket[];
}

/** A scan the door made offline, waiting on the phone to be sent. */
export interface OfflineScan {
  client_id: string;
  event_id: string;
  code: string;
  party: number | null;
  offline_result: string;
  scanned_at: string;
}

export interface SyncResult {
  data: (ScanResult & { client_id: string })[];
  conflicts: (ScanResult & { client_id: string })[];
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

/** What cancelling an event would involve, shown before it is confirmed. */
export interface CancellationPreview {
  ticket_holders: number;
  orders_to_refund: number;
  refund_total: Money;
}

export interface CancellationResult {
  message: string;
  status: EventStatus;
  notified: number;
  refunded: number;
  /** Refunds a provider refused. These need a person. */
  failed: number;
}

/** The next event, with enough to know whether it is going well. */
export interface NextEvent {
  id: string;
  title: string;
  starts_at: string;
  timezone: string;
  city: string;
  tickets_issued: number;
  /** Null where any ticket type is unlimited — there is no denominator then. */
  capacity: number | null;
}

export interface AttentionItem {
  event_id: string;
  title: string;
  reason: string;
  detail: string;
  severity: 'danger' | 'warning';
}

export interface Overview {
  organization: { id: string; name: string };
  currency: Money['currency'];
  /** Null where this person may not see money, which is not the same as zero. */
  money: {
    balance: Money;
    settled: Money;
    sold_7d: Money;
    sold_30d: Money;
    orders_7d: number;
  } | null;
  selling: {
    upcoming_events: number;
    draft_events: number;
    tickets_upcoming: number;
  };
  next_event: NextEvent | null;
  attention: AttentionItem[];
  /** A month of days, zero-filled — null where this person may not see money. */
  sales_by_day: { date: string; net: Money; orders: number }[] | null;
  /** Newest first — null where this person may not see money. */
  recent_orders: {
    reference: string;
    buyer_name: string;
    event_title: string;
    total: Money;
    paid_at: string;
  }[] | null;
  /** Every upcoming event's progress; net is null per row without money. */
  selling_events: {
    id: string;
    title: string;
    starts_at: string;
    timezone: string;
    city: string;
    poster_url: string | null;
    tickets_issued: number;
    capacity: number | null;
    net: Money | null;
  }[];
}

/**
 * An upload still in flight.
 *
 * Distinguished from the finished image by the `uploading` flag rather than
 * by shape, so a caller narrows on one property instead of guessing from
 * which fields happen to be present.
 */
export interface UploadProgress {
  uploading: true;
  /** Null where the total size is unknown — show an indeterminate bar. */
  percent: number | null;
}

/** Where an organization's money is sent. Masked — never the full number. */
export interface PayoutDestination {
  rail: 'interac' | 'bank_transfer';
  currency: Money['currency'];
  interac_email: string | null;
  bank_name: string | null;
  account_name: string | null;
  /** The only part of an account number this API will ever return. */
  account_last_four: string | null;
  verified_at: string | null;
}

/** One night's contribution to the balance. */
export interface PayoutEventRow {
  /** Null for entries not tied to an event — an adjustment, a bulk payment. */
  event_id: string | null;
  title: string;
  starts_at: string | null;
  balance: Money;
  gross: Money;
  settled: Money;
}

/** Money actually sent. */
export interface SettlementRow {
  id: string;
  amount: Money;
  rail: string;
  /** full, partial or overdraft — the reason a balance did not reach zero. */
  type: 'full' | 'partial' | 'overdraft';
  status: string;
  note: string | null;
  event: { id: string; title: string } | null;
  settled_at: string | null;
}

export interface PayoutStatement {
  currency: Money['currency'];
  balance: Money;
  settled: Money;
  events: PayoutEventRow[];
  settlements: SettlementRow[];
  destination: PayoutDestination | null;
}

/**
 * One order, seen from the organization rather than from inside an event.
 *
 * Carries the event it was bought for, which is the whole reason this list
 * exists: support is handed a reference or an address, never the night.
 */
export interface OrganizationOrder {
  id: string;
  reference: string;
  buyer_name: string;
  buyer_email: string;
  status: 'paid' | 'partially_refunded' | 'refunded' | 'pending';
  paid_at: string | null;
  tickets_count: number;
  event: { id: string; title: string } | null;
  total: Money;
  refunded: Money;
}

export interface OrganizationOrderPage {
  data: OrganizationOrder[];
  meta: {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
    /** Null where the page spans currencies — two sets of money do not add. */
    summary: { gross: Money; refunded: Money; net: Money } | null;
  };
}
