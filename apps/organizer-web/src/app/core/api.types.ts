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

export interface Membership {
  id: string;
  name: string;
  slug: string;
  role: Role;
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
  discount_currency: string | null;
  ref_slug: string | null;
  promoter_name: string | null;
  redemption_count: number;
  max_redemptions: number | null;
  is_active: boolean;
  event_scoped: boolean;
  /** Whether it would actually work right now — the question being asked. */
  usable: boolean;
}
