import { formatMoney as format } from '@myfiesta/shared/money';
import { Money } from './api.types';

/**
 * The one locale money is written in on this site.
 *
 * Fixed rather than the reader's, because the page is written twice — on the
 * server and again in the browser — and the two must agree to the character
 * or hydration throws the server's text away. en-CA puts the symbol in front
 * and groups by thousands, which reads right in both markets.
 */
const LOCALE = 'en-CA';

/**
 * Minor units to something a person reads.
 *
 * The API never sends a formatted string, deliberately — formatting is a
 * presentation decision that depends on the reader's locale, not the server's.
 * How it is written — "$25.00", "₦5,000" — is shared with the console and the
 * phone, so a buyer and an organizer read the same price the same way.
 */
export function formatMoney(money: Money | null): string {
  return format(money, LOCALE);
}

/** "From $25.00", or an honest dash when nothing is on sale. */
export function formatFrom(money: Money | null, currency: Money['currency']): string {
  if (!money) {
    // Still shows the right symbol, which is why events carry a currency even
    // when they have no price to show.
    return format({ amount: 0, currency }, LOCALE).replace(/[\d.,]+/, '—');
  }

  return formatMoney(money);
}
