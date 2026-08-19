import { Money } from './api.types';

/**
 * Minor units to something a person reads.
 *
 * The API never sends a formatted string, deliberately — formatting is a
 * presentation decision that depends on the reader's locale, not the server's.
 * This is the one place that decision is made on the web.
 */
export function formatMoney(money: Money | null, locale = 'en-CA'): string {
  if (!money) return '—';

  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency: money.currency,
  }).format(money.amount / 100);
}

/** "From $25.00", or an honest dash when nothing is on sale. */
export function formatFrom(money: Money | null, currency: Money['currency']): string {
  if (!money) {
    // Still shows the right symbol, which is why events carry a currency even
    // when they have no price to show.
    return new Intl.NumberFormat('en-CA', { style: 'currency', currency })
      .format(0)
      .replace(/[\d.,]+/, '—');
  }

  return formatMoney(money);
}
