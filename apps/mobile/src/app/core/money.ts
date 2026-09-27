import {
  currencySymbol as symbolFor,
  formatMoney as format,
} from '@myfiesta/shared/money';

/** An amount and its currency, never one without the other. */
export interface Money {
  amount: number;
  currency: string;
}

/**
 * Minor units to something a person reads.
 *
 * Amounts travel as integers — cents, kobo — and are divided here, at the
 * edge, and nowhere else. A Lagos event listed beside a Toronto one is exactly
 * when a bare number becomes a wrong number.
 *
 * Written the same way as on the site and in the console — "$25.00",
 * "₦5,000" — whatever language the phone is set to. The phone's locale still
 * chooses the separators; it no longer turns dollars into "CA$" everywhere
 * outside Canada, or naira into "NGN" inside it.
 */
export function formatMoney(money: Money | null | undefined): string {
  return format(money);
}

/** Just the symbol, as a price field's prefix: "$", "₦". */
export function currencySymbol(currency: string): string {
  return symbolFor(currency);
}

/** Minor units as the decimal somebody types: 2500 → "25.00". */
export function toMajor(amount: number | null): string {
  return amount === null ? '' : (amount / 100).toFixed(2);
}

/**
 * What somebody typed, as minor units.
 *
 * Forgiving about what a keyboard produces — "25", "25.5", "25,50" from a
 * phone set to a comma locale, a pasted "$25.00" — and exact about the result:
 * rounded to the cent here, once, rather than carried as a float that turns
 * 19.99 into 1998.9999 somewhere downstream. Null for anything that is not a
 * number at all.
 */
export function toMinor(typed: string): number | null {
  const cleaned = typed.replace(/[^\d.,-]/g, '').replace(',', '.');

  if (cleaned === '' || cleaned === '.' || cleaned === '-') return null;

  const value = Number(cleaned);

  return Number.isFinite(value) ? Math.round(value * 100) : null;
}
