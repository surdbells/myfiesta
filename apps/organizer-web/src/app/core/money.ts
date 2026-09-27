import { formatMoney as format } from '@myfiesta/shared/money';
import { readAmount } from '@myfiesta/shared/money-input';
import { Money } from './api.types';

export { readAmount, type TypedAmount } from '@myfiesta/shared/money-input';

/**
 * Minor units to something a person reads.
 *
 * Amounts travel as integers — cents, kobo — and are only ever divided here,
 * at the edge. The platform this replaces kept cents in some columns and
 * dollars in others and formatted currency strings in the API, so the same
 * value meant different things at different layers.
 *
 * The writing itself is shared with the site and the phone — "$25.00",
 * "₦5,000" — so an organizer reads a price the way their buyers do. The
 * reader's locale still chooses the separators.
 */
export function formatMoney(money: Money | null | undefined): string {
  return format(money);
}

/**
 * What a person typed, back to minor units: "5,000" is 500000.
 *
 * Null when there is no amount to take — nothing typed, or something that
 * could mean more than one amount (see readAmount, shared with the phone). It
 * used to be parseFloat, which stops at the first comma: a ₦5,000 ticket was
 * saved at ₦5, and anything it could not read at all went through as zero,
 * which is free. A box that cannot be read now stops the save and says why
 * (amountProblem) instead.
 */
export function toMinorUnits(typed: string | number | null | undefined): number | null {
  const reading = readAmount(typed);

  return reading.kind === 'amount' ? reading.minor : null;
}

/** What to say under an amount box that cannot be read. Null when it can be, or is empty. */
export function amountProblem(typed: string | number | null | undefined): string | null {
  const reading = readAmount(typed);

  return reading.kind === 'unclear' ? reading.message : null;
}

export function toMajorUnits(minor: number): number {
  return minor / 100;
}
