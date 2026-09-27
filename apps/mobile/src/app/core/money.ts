import {
  currencySymbol as symbolFor,
  formatMoney as format,
} from '@myfiesta/shared/money';
import { readAmount, type TypedAmount } from '@myfiesta/shared/money-input';

export { readAmount, type TypedAmount };

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

/**
 * Whether this phone writes cents after a comma, as one set to French does.
 *
 * Its decimal keypad then offers a comma and no point, so "25,50" is the only
 * way its owner can type cents, and it is read that way (readAmount's
 * commaForCents). Everywhere else a comma is thousands.
 */
export function writesCentsAfterComma(locale?: string): boolean {
  const decimal = new Intl.NumberFormat(locale).formatToParts(1.5).find((part) => part.type === 'decimal');

  return decimal?.value === ',';
}

/**
 * Minor units as somebody would type them: 2500 → "25.00", 500000 → "5,000.00".
 *
 * Grouped in thousands, because on blur this is how somebody sees that "5000"
 * was taken as five thousand, and written so the box reads it back the same:
 * commas and a point, or on a phone that writes cents after a comma, spaces
 * and a comma ("5 000,00").
 */
export function toMajor(amount: number | null, commaForCents = writesCentsAfterComma()): string {
  if (amount === null) return '';

  const size = Math.abs(amount);
  const whole = Math.floor(size / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, commaForCents ? ' ' : ',');
  const cents = String(size % 100).padStart(2, '0');

  return `${amount < 0 ? '-' : ''}${whole}${commaForCents ? ',' : '.'}${cents}`;
}

/**
 * What somebody typed, as minor units: "5,000" is 500000.
 *
 * It used to turn the comma into a decimal point — a Lagos organizer typing
 * ₦5,000 put a ticket on sale at ₦5.00. The reading is now the one the
 * console uses (readAmount, shared): commas and spaces between thousands, one
 * point before the cents, a currency sign ignored. Null for an empty box and
 * for anything that could mean more than one amount, like "5,00" on a phone
 * whose keypad has a point; amountProblem says why.
 */
export function toMinor(typed: string, commaForCents = writesCentsAfterComma()): number | null {
  const reading = readAmount(typed, { commaForCents });

  return reading.kind === 'amount' ? reading.minor : null;
}

/** What to say under an amount box that cannot be read. Null when it can be, or is empty. */
export function amountProblem(typed: string, commaForCents = writesCentsAfterComma()): string | null {
  const reading = readAmount(typed, { commaForCents });

  return reading.kind === 'unclear' ? reading.message : null;
}
