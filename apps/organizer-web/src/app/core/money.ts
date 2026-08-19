import { Money } from './api.types';

/**
 * Minor units to something a person reads.
 *
 * Amounts travel as integers — cents, kobo — and are only ever divided here,
 * at the edge. The platform this replaces kept cents in some columns and
 * dollars in others and formatted currency strings in the API, so the same
 * value meant different things at different layers.
 */
export function formatMoney(money: Money | null | undefined): string {
  if (!money) return '—';

  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: money.currency,
  }).format(money.amount / 100);
}

/** What a person types, back to minor units. */
export function toMinorUnits(major: string | number): number {
  const value = typeof major === 'number' ? major : parseFloat(major);

  return Number.isFinite(value) ? Math.round(value * 100) : 0;
}

export function toMajorUnits(minor: number): number {
  return minor / 100;
}
