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
 */
export function formatMoney(money: Money | null | undefined): string {
  if (!money) return '—';

  // The reader's locale decides how the symbol is written, exactly as in the
  // two web apps: a phone set to Canada shows $, one set anywhere else shows
  // CA$, and either way the currency is never dropped.
  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: money.currency,
  }).format(money.amount / 100);
}
