/**
 * Minor units to something a person reads, the same way in all three apps.
 *
 * Framework-free and shared because the three copies had drifted into the same
 * mistake: the plain Intl symbol, which in a Canadian locale writes naira as
 * "NGN 5,000.00" and everywhere outside Canada writes dollars as "CA$25.00".
 * Nobody in Lagos reads a price as "NGN", and nobody in Toronto reads one as
 * "CA$".
 *
 * The narrow symbol is what both markets write by hand: ₦ for naira, $ for
 * dollars. "$" alone is only ambiguous where US dollars are also on sale, and
 * nothing here is sold in anything but CAD and NGN — an event's currency is its
 * country's.
 *
 * Naira drop their kobo when there are none. Nigerian prices are set and
 * written in whole naira — "₦5,000", never "₦5,000.00" — and organizers type
 * them that way. An amount that does carry kobo (a percentage discount, VAT on
 * an odd price) keeps both digits, so nothing is ever rounded where somebody
 * could add it up. Dollars always show cents, which is how Canadian prices are
 * written.
 */

/** An amount in minor units — cents, kobo — and the currency it is in. */
export interface MoneyValue {
  amount: number;
  currency: string;
}

/** Currencies whose prices are written in whole units unless there is a remainder. */
const WHOLE_UNITS = new Set(['NGN']);

/**
 * "$25.00", "₦5,000", "₦206.25" — or a dash when there is no amount.
 *
 * `locale` decides the separators and where the symbol sits ("25,00 $" in
 * Montréal); undefined means the reader's own. The server-rendered site passes
 * one explicitly, because the server and the browser must agree to the
 * character or hydration replaces the text.
 */
export function formatMoney(money: MoneyValue | null | undefined, locale?: string): string {
  if (!money) return '—';

  return format(money.amount, money.currency, locale);
}

/** The symbol alone, as a price field's prefix: "$", "₦". */
export function currencySymbol(currency: string, locale?: string): string {
  const part = new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    currencyDisplay: 'narrowSymbol',
  })
    .formatToParts(0)
    .find((piece) => piece.type === 'currency');

  return part?.value ?? currency;
}

function format(amount: number, currency: string, locale?: string): string {
  const whole = WHOLE_UNITS.has(currency) && amount % 100 === 0;

  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    currencyDisplay: 'narrowSymbol',
    ...(whole ? { minimumFractionDigits: 0, maximumFractionDigits: 0 } : {}),
  }).format(amount / 100);
}
