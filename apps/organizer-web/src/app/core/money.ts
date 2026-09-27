import { formatMoney as format } from '@myfiesta/shared/money';
import { Money } from './api.types';

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

/** What a person types, back to minor units. */
export function toMinorUnits(major: string | number): number {
  const value = typeof major === 'number' ? major : parseFloat(major);

  return Number.isFinite(value) ? Math.round(value * 100) : 0;
}

export function toMajorUnits(minor: number): number {
  return minor / 100;
}
