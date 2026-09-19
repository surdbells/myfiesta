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
  | "messages.send"
  | "payouts.request"
  | "team.manage"
  | "organization.brand"
  | "organization.integrations";

/**
 * How an organization appears on the pages it sells from.
 *
 * The slug is here to be shown, never to be sent back: it is in links
 * organizers have already handed out.
 */
export interface Brand {
  name: string;
  slug: string;
  description: string | null;
  logo_url: string | null;
  /** Whether the tick is shown: verified, and still called what was checked. */
  is_verified: boolean;
  /** Renamed since verification, waiting on somebody to agree it is still them. */
  verification_pending_name: boolean;
}

/** Who a campaign is written to. The server decides who is on each. */
export type CampaignAudience = 'followers' | 'past_attendees' | 'abandoned';

export type CampaignStatus = 'draft' | 'scheduled' | 'sending' | 'sent' | 'cancelled';

export interface Campaign {
  id: string;
  audience: CampaignAudience;
  event: { id: string; title: string; slug: string } | null;
  subject: string;
  body: string;
  status: CampaignStatus;
  scheduled_for: string | null;
  sent_at: string | null;
  recipients: number | null;
  /** On the list, but left out: opted out, or written to this week already. */
  suppressed: number | null;
  /** What came in through the email's link. Only once it has been sent. */
  results: { orders: number; tickets: number; revenue: Money } | null;
  created_at: string;
}

export interface CampaignPage extends Page<Campaign> {
  audiences: { value: CampaignAudience; label: string; needs_event: boolean }[];
  events: { id: string; title: string; starts_at: string }[];
}

export interface CampaignDraft {
  audience: CampaignAudience;
  event_id: string | null;
  subject: string;
  body: string;
  send: 'draft' | 'now' | 'later';
  scheduled_for: string | null;
}

/** Something another system is told about. */
export type WebhookEventName = 'order.paid' | 'order.refunded' | 'ticket.checked_in';

export interface WebhookEndpoint {
  id: string;
  url: string;
  events: WebhookEventName[];
  description: string | null;
  enabled: boolean;
  /** Why it is off — by hand, or after too many failures in a row. */
  disabled_reason: string | null;
  consecutive_failures: number;
  last_delivery: { status: WebhookDeliveryStatus; event: string; response_status: number | null; at: string } | null;
  created_at: string;
}

export type WebhookDeliveryStatus = 'pending' | 'succeeded' | 'failed';

export interface WebhookDelivery {
  id: string;
  event: string;
  status: WebhookDeliveryStatus;
  attempts: number;
  response_status: number | null;
  /** The first 500 characters of what came back, or why nothing did. */
  response_excerpt: string | null;
  next_attempt_at: string | null;
  delivered_at: string | null;
  created_at: string;
}

/** Never the key itself: that is shown once, when it is made. */
export interface ApiKeySummary {
  id: string;
  name: string;
  last_four: string;
  last_used_at: string | null;
  created_at: string;
}

export interface Integrations {
  events: WebhookEventName[];
  endpoints: WebhookEndpoint[];
  keys: ApiKeySummary[];
}

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

/**
 * Something sold with a ticket that is not one.
 *
 * A table, a bottle, a shirt. It has a price and a stock and it goes on the
 * same order — and it admits nobody, which is the whole distinction between
 * this and a ticket type.
 */
export interface AddOn {
  id: string;
  name: string;
  description: string | null;
  price: Money;
  /** Null is unlimited, and is not the same as zero. */
  quantity_available: number | null;
  max_per_order: number | null;
  status: 'on_sale' | 'closed';
  sort_order: number;
  /** How many have been paid for. */
  sold: number;
  /** What is left once baskets in progress are counted. Null where unlimited. */
  remaining: number | null;
}

/**
 * Something the checkout asks the people coming.
 *
 * The same row a wedding asks its guests with — one question model for both
 * halves of the product, so there is one editor and one validator rather than
 * two of each drifting apart.
 */
export interface EventQuestion {
  id: string;
  label: string;
  type: 'text' | 'choice' | 'multi_choice' | 'boolean';
  /** What may be chosen. Empty for a question that is typed into. */
  options: string[];
  required: boolean;
  /** Asked once for the order, or once about each person on it. */
  per_attendee: boolean;
  sort_order: number;
  /**
   * Whether anybody has answered it.
   *
   * Once they have, the wording can still be corrected and the shape cannot:
   * turning a typed question into a choice would leave every answer already
   * given outside the list of things it was possible to say.
   */
  answered: boolean;
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

  sold_out: boolean;
  /** A price ladder: this tier goes on sale when that one sells out. */
  opens_after: { id: string; name: string } | null;
  /** Still waiting for that tier to sell out. */
  waiting: boolean;
}

/**
 * The money behind one event, read from the ledger.
 *
 * Every figure arrives as an amount with a currency so the console never has
 * to decide what a number means.
 */
/** Where an event's sales came from. See SalesReport on the API for what each part counts. */
export interface SalesReport {
  currency: string;
  timezone: string;
  ticket_types: {
    id: string;
    name: string;
    status: string;
    price: Money;
    /** Null for a tier with no limit. */
    capacity: number | null;
    sold: number;
    comps: number;
    people: number;
    arrived: number;
    revenue: Money;
  }[];
  /**
   * What was sold beside the tickets.
   *
   * Apart from the tiers on purpose: an extra sells no places and fills no
   * room. A removed one still appears when it sold something, because an
   * organizer taking a bottle off the list does not unsell the ones bought.
   */
  add_ons: {
    id: string;
    name: string;
    status: string;
    price: Money;
    capacity: number | null;
    sold: number;
    revenue: Money;
  }[];
  /**
   * What was taken in a doorway.
   *
   * Money the organizer already holds: it went into their own tin, onto
   * their own terminal or straight into their bank, so it is counted here
   * and is deliberately not part of what the platform owes them.
   */
  door: {
    currency: string;
    tickets: number;
    total: Money;
    by_method: { method: string; orders: number; total: Money }[];
    by_till: { label: string; orders: number; total: Money }[];
  };
  /** Every day from the first sale, quiet days included; revenue in minor units. */
  days: { date: string; orders: number; tickets: number; revenue: number }[];
  codes: {
    code_id: string | null;
    code: string | null;
    label: string | null;
    promoter: string | null;
    ref_slug: string | null;
    deleted: boolean;
    orders: number;
    tickets: number;
    discount: Money;
    revenue: Money;
  }[];
}

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

/** An answer as it is read back: the question, and what was said. */
export interface GivenAnswer {
  label: string | null;
  value: string;
}

export interface Guest {
  id: string;
  name: string;
  email: string;
  ticket_type: string | null;
  checked_in: boolean;
  checked_in_at: string | null;
  /**
   * What this person was asked at checkout, and what the buyer answered for
   * the order they are on. Which of the two it was is not a distinction
   * anybody reading a guest list is making.
   */
  answers: GivenAnswer[];
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
/** What one code has sold in one currency. revenue is after its discount, before tax and fees. */
export interface CodeSales {
  currency: Money['currency'];
  orders: number;
  tickets: number;
  revenue: number;
  discount: number;
}

/** One person on the team. */
export interface TeamMember {
  id: string;
  name: string;
  email: string;
  role: string;
  joined_at: string | null;
  is_you: boolean;
}

export interface TeamPage {
  members: TeamMember[];
  invitations: { id: string; email: string; role: string; invited_by: string | null; expires_at: string; expired: boolean }[];
  /** Every role, with what it can do — said where the choice is made. */
  roles: { value: string; label: string; description: string }[];
}

/** What an invitation link offers, for the join page. */
export interface InvitationDetails {
  organization: string;
  role: string;
  role_label: string;
  role_description: string;
  email: string;
  invited_by: string | null;
  state: 'open' | 'accepted' | 'revoked' | 'expired';
  has_account: boolean;
}

/** The night a door pass opens, and the rule the door enforces. */
export interface DoorPassEvent {
  id: string;
  title: string;
  starts_at: string;
  ends_at: string | null;
  timezone: string;
  venue: string | null;
  city: string | null;
  min_age: number | null;
  id_required: boolean;
}

/** What a door link offers, before it is opened. */
export interface DoorPassPreview {
  label: string;
  state: DoorPassState;
  expires_at: string;
  event: DoorPassEvent;
}

/** A door pass opened on this phone. */
export interface DoorPassSession {
  token: string;
  label: string;
  expires_at: string;
  event: DoorPassEvent;
}

export type DoorPassState = 'waiting' | 'active' | 'expired' | 'revoked' | 'ended';

/** A door pass, as the organizer sees it. The link itself is only ever returned once. */
export interface DoorPass {
  id: string;
  label: string;
  state: DoorPassState;
  issued_by: string | null;
  created_at: string;
  claimed_at: string | null;
  last_used_at: string | null;
  expires_at: string;
  revoked_at: string | null;
  scans: number;
  admitted_scans: number;
}

/** Who is waiting for a sold-out event. */
export interface WaitlistPage {
  data: { id: string; name: string | null; email: string; quantity: number; status: 'waiting' | 'notified' | 'purchased'; joined_at: string; notified_at: string | null }[];
  meta: PageMeta;
  summary: { waiting: number; waiting_tickets: number; notified: number; purchased: number };
  /** Whether anything can be bought now — telling the list is refused until it can. */
  on_sale: boolean;
}

/** A batch of single-use codes, looked after as one thing. */
export interface CodeBatch {
  id: string;
  name: string;
  prefix: string;
  quantity: number;
  used: number;
  turned_off: number;
  created_at: string;
}

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
  /** The fewest tickets (of the ones it covers) an order needs for it to apply. */
  min_quantity: number | null;
  /** The ticket types it discounts. Empty means every one. */
  ticket_types: { id: string; name: string }[];
  /** Presale: the tiers it opens to whoever holds it. */
  unlocks: { id: string; name: string }[];
  /** Paid orders using it, per currency. */
  sales: CodeSales[];
  /** The window the code works in. Enforced at checkout; null means always. */
  starts_at: string | null;
  ends_at: string | null;
  is_active: boolean;
  event_scoped: boolean;
  /** Whether it would actually work right now — the question being asked. */
  usable: boolean;
}

/** A code on the organization-wide Discount codes screen, with the event it is for. */
export interface OrganizationCode extends PromoCode {
  /** Null for a code that works on every event. */
  event: { id: string; title: string; starts_at: string; timezone: string; status: string } | null;
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
/*
 * The door's shapes are defined once, in @myfiesta/door, and re-exported here
 * so every existing import keeps working.
 *
 * They were declared in this file and again in the phone app. Two apps scan
 * the same tickets against the same server, and a field that drifts between
 * them is a door that stops recognising people.
 */
export type { ScanResult, DoorListTicket, DoorList, OfflineScan, SyncResult } from '@myfiesta/door';

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
  /** Payout requests, newest first. */
  requests: PayoutRequestRow[];
  /** Whether this member may ask to be paid: owners and finance. */
  can_request: boolean;
}

/** An organizer asking to be paid, and what became of it. */
export interface PayoutRequestRow {
  id: string;
  amount: Money;
  paid_amount: Money | null;
  status: 'pending' | 'paid' | 'rejected' | 'cancelled';
  note: string | null;
  /** Written by platform staff for the organizer: why it was not paid, or a note on what was. */
  decision_note: string | null;
  requested_by: string | null;
  requested_at: string;
  decided_at: string | null;
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
