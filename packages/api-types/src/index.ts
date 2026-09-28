/**
 * Shapes the API actually returns.
 *
 * Hand-written for now and deliberately narrow — only what the console and
 * the phone app read. Shared by both: two apps reading the same endpoints
 * through two hand-written ideas of the response is how one of them ends up
 * showing a field the other stopped sending.
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
  | "payouts.destination"
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
  /** What a new campaign may point at: nights still to come and on sale. */
  events: { id: string; title: string; starts_at: string }[];
  /** How many campaigns each status holds, across the organization. */
  statuses: { value: CampaignStatus; campaigns: number }[];
  /** What past campaigns pointed at, which outlives the list above. */
  written_about: { id: string; title: string; starts_at: string }[];
}

export interface CampaignFilters {
  status?: string;
  audience?: string;
  event_id?: string;
  q?: string;
}

export interface CampaignDraft {
  audience: CampaignAudience;
  event_id: string | null;
  subject: string;
  body: string;
  send: 'draft' | 'now' | 'later';
  scheduled_for: string | null;
}

/**
 * Something another system is told about — exactly WebhookEndpoint::EVENTS
 * (WebhookEventMirrorTest), so every screen that labels them has a label for
 * each.
 */
export type WebhookEventName = 'order.paid' | 'order.refunded' | 'ticket.checked_in' | 'order.disputed';

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

/**
 * Whether the platform is selling for an organization: GET
 * /api/organizer/standing, for the console's banner.
 *
 * Asked on its own rather than read from the sign-in, because a suspension
 * lands while people are signed in. `reason` is only there when myFiesta
 * chose to share it; `support_email` is null when the deployment has none.
 */
export interface OrganizationStanding {
  organization: { id: string; name: string };
  suspended: boolean;
  suspension: {
    /** ISO 8601. */
    since: string | null;
    reason: string | null;
    support_email: string | null;
  } | null;
}

/**
 * Asking for an account: POST /api/auth/register, from the console or the
 * phone.
 *
 * Answered 202 with `SignUpPending` for every address alike — the account is
 * made when the emailed link is opened — except when joining by invitation,
 * which is answered 201 with a `Session` straight away.
 */
export interface SignUp {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  /** The events page to open. Left out by somebody going out, or joining a team. */
  organization?: string;
  /** Joining somebody's organization instead of opening one. */
  invitation?: string;
  /** Said, not inferred from a missing organization. */
  attendee?: boolean;
  device?: string;
  /**
   * The box, ticked: the terms, the privacy policy and the refund policy,
   * agreed to. The server refuses the sign-up with a 422 without it, and the
   * account keeps which version was agreed to, and when.
   */
  accept_terms: boolean;
}

/** The answer to every sign-up without an invitation: check your email. */
export interface SignUpPending {
  message: string;
  pending: true;
}

export interface Session {
  token: string;
  user: { name: string; email: string };
  abilities: string[];
  organizations: Membership[];
}

/** The signed-in person, as GET /api/auth/me has them now. */
export interface Account {
  name: string;
  email: string;
  /**
   * Whether the address is proved. Until it is, putting an event on sale,
   * asking to be paid and changing where payouts go are refused with a 403
   * whose code is `email_unverified`.
   */
  email_verified: boolean;
  phone: string | null;
  timezone: string | null;
  organizations: Membership[];
}

/**
 * What deleting the signed-in account would do, from GET /api/auth/erasure.
 *
 * The same erasure as the privacy page's form. `refused` is set while the
 * person is the only owner of an organization — nothing can be deleted until
 * each of those has another owner or is closed — or while the account has
 * myFiesta staff access, which another administrator removes first.
 */
export interface AccountErasurePreview {
  email: string;
  /** A proved address is deleted on the password; any other is sent a link first. */
  email_verified: boolean;
  refused: string | null;
  organizations: {
    id: string;
    name: string;
    slug: string;
    role: Role;
    only_owner: boolean;
  }[];
  /** How long orders and the ledger are kept, with nobody's name on them. */
  kept_for_years: number;
  /**
   * Whether the audit trail names them — a refund sent, a price changed, on
   * somebody's team. That stays under their name: nobody can edit it.
   */
  history_kept: boolean;
}

/**
 * POST /api/auth/erasure: `completed` (200) has signed out every device,
 * this one included; `pending` (202) waits for the emailed link; `refused`
 * (409) changed nothing.
 */
export interface AccountErasureResult {
  status: 'completed' | 'pending' | 'refused';
  message: string;
}

/**
 * The signed-in account and the terms, the privacy policy and the refund
 * policy: GET /api/auth/terms, and the answer to POST /api/auth/terms with
 * `{ accept_terms: true }`, which a 422 refuses without the box ticked.
 *
 * For accounts nobody asked — made before sign-up asked, brought over from
 * the previous platform — and for new words. Refused to a staff session and
 * to a door pass: neither is anybody agreeing for themselves.
 */
export interface TermsStanding {
  /** The version in force now. */
  current: string;
  /** Whether this account agreed to that version. An older one does not count. */
  accepted: boolean;
  /** What it last agreed to, or null when it never has. */
  accepted_version: string | null;
  /** ISO 8601. When it first agreed to `accepted_version`. */
  accepted_at: string | null;
}

/**
 * The states an event can actually be in.
 *
 * review and scheduled were in the schema and unreachable by any code path.
 * in_review is the review they stood for, now real: sent by the organizer,
 * frozen while it waits, and decided by myFiesta staff. Mirrors
 * App\Enums\EventStatus, which owns the transitions.
 */
export type EventStatus = 'draft' | 'in_review' | 'published' | 'cancelled';

/** One step in an event's review, newest first in `EventReviewState.history`. */
export interface EventReviewStep {
  action: 'submitted' | 'approved' | 'rejected' | 'withdrawn';
  /**
   * How an approval came about: `review` for a decision on the queue, or one
   * of the staff actions and rules that count as one. Null for other steps.
   */
  via: 'review' | 'takedown_lifted' | 'suspension_lifted' | 'series' | 'existing' | 'imported' | null;
  /** The reviewer's words, exactly as they were sent, on a rejection. */
  reason: string | null;
  /** ISO 8601. */
  at: string;
  /** Who: a member of the organization, or "myFiesta" for a decision. */
  by: string | null;
}

/** Where an event stands with myFiesta's review. */
export interface EventReviewState {
  /** When the review now waiting began. Null unless in review. */
  submitted_at: string | null;
  /** The last approval that stands. */
  approved_at: string | null;
  /**
   * What sending it now would do: `publish` puts it straight back on sale
   * (nothing a buyer sees changed since it was approved, or it is the approved
   * night of a series on a new date, and staff have not sent it back since);
   * `review` sends it to the queue. Null when it is not a draft that can be
   * sent — among them every draft of a suspended organization.
   */
  on_submit: 'publish' | 'review' | null;
  /**
   * The organization is suspended: nothing of its can be sent for review or
   * put on sale until that is lifted, so no screen should offer to.
   * Optional for an answer a client kept from before the API said.
   */
  suspended?: boolean;
  /**
   * Whether what a buyer sees is still exactly what was last approved, and
   * staff have not sent it back since. For an event on sale: taken off now,
   * it could go straight back on sale.
   */
  unchanged_since_approval: boolean;
  /**
   * What stops it being sent, one plain sentence each. Empty when ready. Only
   * its dates when `on_submit` is `publish`: the listing is what was approved.
   */
  not_ready: string[];
  /** The rejection not yet answered by sending it again. */
  rejection: { reason: string; at: string } | null;
  history: EventReviewStep[];
}

/** What sending, withdrawing, or taking an event off sale did. */
export interface EventReviewResult {
  status: EventStatus;
  /**
   * `in_review` sent to the queue; `published` straight on sale with an
   * approval that still stands; `withdrawn` taken back; `unpublished` taken
   * off sale; `already` nothing to do.
   */
  outcome: 'in_review' | 'published' | 'withdrawn' | 'unpublished' | 'already';
  message: string;
}

/** A refusal to send an event for review, with every reason at once. */
export interface EventReviewRefusal {
  message: string;
  reasons?: string[];
  code?: 'event_in_review' | 'organization_suspended' | 'email_unverified';
}

export interface OrganizerEvent {
  id: string;
  slug: string;
  title: string;
  kind: 'ticketed' | 'invitation';
  status: EventStatus;
  /**
   * A draft only because myFiesta suspended the organization while it was on
   * sale: not one the organizer is still writing, and it goes back on sale by
   * itself when the suspension is lifted, unless it has changed since.
   * Optional for a row a client kept from before the API said.
   */
  off_sale_by_suspension?: boolean;
  starts_at: string;
  timezone: string;
  city: string;
  currency: 'CAD' | 'NGN';
  tickets_issued: number;
  checked_in: number;
  orders: number;
  /** Null where a tier is unlimited: there is no proportion of an open room. */
  capacity: number | null;
  /**
   * What the organizer earned: after their own discounts, net of tax.
   *
   * Null for a member who may not see money — marketing and door staff are
   * shown how full the room is, never what it took. Null is not zero.
   */
  revenue: Money | null;
  /** Page views, counted once a visit. Absent for anything before they were. */
  views: number;
  /** When the last ticket sold. Null if none has. */
  last_sale_at: string | null;
  /** The poster at thumbnail size, if one was uploaded. */
  poster_url: string | null;
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
  /** Whether ticket holders may hand a ticket back to be resold. */
  resale_enabled: boolean;
  /** How close to the doors returns stop being accepted. */
  resale_closes_hours: number;
  poster_url: string | null;
  /** Where it stands with myFiesta's review. */
  review: EventReviewState;
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
export interface InsightSummary {
  orders: number;
  tickets: number;
  revenue: Money;
  views: number;
  /** Opened inside the organizer's own website. */
  embed_views: number;
  /** Online orders over views. Null when nothing was counted. */
  conversion: number | null;
  people: number;
  arrived: number;
  /** Arrived over people. Null until the doors have opened. */
  attendance: number | null;
  started: boolean;
}

export type InsightSource = 'direct' | 'link' | 'campaign' | 'embed' | 'door';

export interface SalesInsights {
  summary: InsightSummary;
  sources: { source: InsightSource; orders: number; tickets: number; revenue: Money }[];
  previous: { id: string; title: string; starts_at: string; summary: InsightSummary } | null;
  pace: {
    this: { days_before: number; tickets: number }[];
    previous: { days_before: number; tickets: number }[] | null;
  };
}

export interface SalesReport {
  currency: string;
  timezone: string;
  /** Looked, bought, came, from where, and against the last night. */
  insights: SalesInsights;
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
  /** Null for somebody sold a ticket at the door. */
  email: string | null;
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
  /** Null for a sale at the door, where nobody was asked for one. */
  buyer_email: string | null;
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

/**
 * Money owed back to myFiesta in the statement's currency, and how it is
 * coming back.
 *
 * An advance (a payout of more than was owed) or refunds after a payout take
 * the balance below zero; the next sales pay it back before anything more is
 * paid out. The parts always add up:
 * advanced − repaid − recovered + added = outstanding.
 */
export interface PayoutOverdraft {
  /** What is owed to myFiesta now. Zero once it has been paid back. */
  outstanding: Money;
  /** How much myFiesta advanced. Null when refunds, not an advance, took the balance below zero. */
  advanced: Money | null;
  advanced_at: string | null;
  /** Paid back by sales since. */
  recovered: Money;
  /** Paid back by a transfer to myFiesta since. */
  repaid: Money;
  /** Put on top by refunds and chargebacks since. */
  added: Money;
  /** When it started: the advance, or the last payout before refunds overtook sales. */
  since: string | null;
  /** The whole position in one sentence, the same one myFiesta staff read. */
  summary: string;
  /** What pays it back, while anything is outstanding. */
  recovery: string | null;
}

export interface PayoutStatement {
  currency: Money['currency'];
  /** Below zero while the organization owes myFiesta money; see `overdraft`. */
  balance: Money;
  settled: Money;
  /** Null when nothing is owed back and no advance is being paid back. */
  overdraft: PayoutOverdraft | null;
  events: PayoutEventRow[];
  settlements: SettlementRow[];
  destination: PayoutDestination | null;
  /** Payout requests, newest first. */
  requests: PayoutRequestRow[];
  /** Whether this member may ask to be paid: owners and finance. */
  can_request: boolean;
  /**
   * Whether this member may change where payouts are sent: owners only.
   * Everybody who can see the statement sees the destination; only an owner
   * is offered the form.
   */
  can_change_destination: boolean;
}

/** An organizer asking to be paid, and what became of it. */
export interface PayoutRequestRow {
  id: string;
  amount: Money;
  paid_amount: Money | null;
  /** The part of the payment myFiesta advanced beyond what was owed, when it did. */
  advance: Money | null;
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
  /** Null for a sale at the door, where nobody was asked for one. */
  buyer_email: string | null;
  status: 'paid' | 'partially_refunded' | 'refunded' | 'pending';
  paid_at: string | null;
  tickets_count: number;
  event: { id: string; title: string } | null;
  total: Money;
  refunded: Money;
  /**
   * Facts about this buyer worth knowing before the night, never a score.
   *
   * Empty on almost every order. The server decides what counts; the console
   * only says it in words.
   */
  signals: OrderSignal[];
}

export type OrderSignal = 'previous_chargeback' | 'many_orders' | 'disputed';

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
  /** Only the ones that exist and apply. */
  registrations: { label: string; number: string }[];
}

/**
 * What was paid, to whom, and each tax on it.
 *
 * Read from the order's own copy of how it was priced, so it says what was
 * charged at the time whatever the settings say now. Never carries a ticket
 * code. Adds up: subtotal, less discount, plus every tax not `included`, plus
 * the service charge, is the total.
 */
export interface Receipt {
  reference: string;
  issued_at: string;
  currency: 'CAD' | 'NGN';
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
