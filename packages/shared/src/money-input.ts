/**
 * What somebody typed in an amount box, read the way they meant it.
 *
 * Shared by the console and the phone because the two had each got it wrong a
 * different way. The console read "5,000" with parseFloat, which stops at the
 * comma: a ₦5,000 ticket was saved at ₦5. The phone turned the comma into a
 * decimal point, and made the same ₦5,000 into ₦5.00. Nigerian prices are
 * typed with thousands separators as a matter of course, and Canadians type
 * "1,250.50".
 *
 * So the rules are the ones a price is written by in both markets, and in
 * both currencies:
 *
 *   - Thousands may be grouped with commas or spaces (including the no-break
 *     spaces a phone or a copied figure puts there): "5,000", "1 250".
 *   - One point at most, with up to two digits after it: "12.5", "0.99".
 *   - A currency sign in front or behind is ignored: "$25", "₦5,000", "N5000".
 *
 * Anything that could mean two different amounts is refused with a sentence
 * saying how to type it, never guessed at. "5,00" is the case in point: a
 * phone set to French writes five dollars that way, and read by the rule
 * above it would be five hundred. Reading it as either would put a price on
 * sale that is out by a factor of a hundred, so it is refused. "5.000" is
 * refused for the same reason the other way round: that is five thousand in
 * much of Europe. A figure is never quietly shortened: "5,000" is five
 * thousand or it is a question, never five.
 *
 * The one exception is a keypad that has no point. A phone set to French
 * offers only a comma for cents, and "25,50" is the only way its owner can
 * type twenty-five fifty; refusing it would leave them no way at all. So a
 * caller that knows the keypad writes cents after a comma says so
 * (commaForCents), and then a single comma followed by one or two digits is
 * the cents — "5,00" is five, never five hundred. The console never says so:
 * a desktop keyboard always has a point.
 *
 * The result is exact. The whole part and the cents are read as integers and
 * put together, so "19.99" is 1999, not 1998.9999 rounded.
 */

/** Empty, an amount in minor units (cents, kobo), or a question for the person typing. */
export type TypedAmount =
  | { kind: 'empty' }
  | { kind: 'amount'; minor: number }
  | { kind: 'unclear'; message: string };

export interface ReadingOptions {
  /** The keypad writes cents after a comma, as a phone set to French does. */
  commaForCents?: boolean;
}

/** Whole units longer than this are a slip of the keyboard, not a price. */
const MAX_WHOLE_DIGITS = 12;

const NOT_A_NUMBER = 'Type the amount in numbers, like 5,000 or 25.50.';
const COMMA_FOR_CENTS = 'Use a point for cents, like 5.00. A comma separates thousands, like 5,000.';
const BAD_GROUPS = 'Put commas between groups of three digits, like 1,250,000, or leave them out.';
const SPACES_FOR_THOUSANDS = 'Put spaces between thousands and one comma before the cents, like 1 250,50.';
const POINTS_FOR_THOUSANDS = 'Use commas between thousands, not points, like 1,250,000.';
const THOUSANDS_OR_CENTS = 'For thousands, use a comma, like 5,000. For cents, two digits after the point, like 5.00.';
const TOO_MANY_CENTS = 'Use at most two digits after the point, like 25.50.';
const BELOW_ZERO = 'An amount cannot be less than zero.';
const TOO_LARGE = 'That is more than any amount here can be.';

/**
 * The spaces that are not the space bar: the no-break space, the narrow one a
 * French locale groups thousands with, and the thin one. Read as ordinary
 * spaces, so every rule below only has to know one.
 */
const OTHER_SPACES = String.fromCharCode(0x00a0, 0x202f, 0x2009);

/** A sign in front ("$25", "CA$ 25", "₦5,000", "N5000") or behind ("25 $", as Québec writes it). */
const SIGN_BEFORE = /^(?:CA\$|C\$|CAD|NGN|\$|₦|N(?=[ \d]))\s*/i;
const SIGN_AFTER = /\s*(?:\$|₦|CAD|NGN)$/i;

export function readAmount(
  typed: string | number | null | undefined,
  options: ReadingOptions = {},
): TypedAmount {
  if (typed === null || typed === undefined) return { kind: 'empty' };

  if (typeof typed === 'number') {
    if (!Number.isFinite(typed)) return unclear(NOT_A_NUMBER);
    typed = String(typed);
  }

  let text = [...typed]
    .map((c) => (OTHER_SPACES.includes(c) ? ' ' : c))
    .join('')
    .trim();

  if (text === '') return { kind: 'empty' };

  if (/^[-−]/.test(text)) return unclear(BELOW_ZERO);

  text = text.replace(SIGN_BEFORE, '').replace(SIGN_AFTER, '').trim();

  if (text === '') return unclear(NOT_A_NUMBER);
  if (/^[-−]/.test(text)) return unclear(BELOW_ZERO);
  if (!/^[0-9,. ]+$/.test(text) || !/[0-9]/.test(text)) return unclear(NOT_A_NUMBER);

  if (options.commaForCents && !text.includes('.') && /,[0-9]{1,2}$/.test(text)) {
    // One comma, and it is the cents: "25,50", "1 250,50". A comma anywhere
    // else as well would be thousands and cents with the same mark.
    if (text.indexOf(',') !== text.lastIndexOf(',')) return unclear(SPACES_FOR_THOUSANDS);

    text = text.replace(',', '.');
  }

  const points = text.split('.').length - 1;

  if (points > 1) return unclear(POINTS_FOR_THOUSANDS);

  const [whole = '', cents = ''] = text.split('.');

  if (!/^[0-9]*$/.test(cents)) return unclear(NOT_A_NUMBER);

  if (cents.length > 2) {
    // "5.000", "19.995": thousands written with a point, or a price with a
    // digit too many. Either could be meant, so neither is assumed.
    return unclear(cents.length === 3 && /^[0-9]{1,3}$/.test(whole) ? THOUSANDS_OR_CENTS : TOO_MANY_CENTS);
  }

  const digits = readWhole(whole, points === 1);

  if (typeof digits !== 'string') return digits;

  if (digits.replace(/^0+(?=[0-9])/, '').length > MAX_WHOLE_DIGITS) return unclear(TOO_LARGE);

  const minor = Number(digits || '0') * 100 + Number(cents.padEnd(2, '0'));

  return Number.isSafeInteger(minor) ? { kind: 'amount', minor } : unclear(TOO_LARGE);
}

/**
 * The whole units, separators taken out once they have been checked.
 *
 * Separators have to be where thousands are — every group after the first
 * exactly three digits — and all of one kind. A comma with one or two digits
 * after it and no point anywhere is somebody writing cents with a comma, and
 * is told so rather than told about grouping.
 */
function readWhole(whole: string, hasPoint: boolean): string | TypedAmount {
  if (/^[0-9]*$/.test(whole)) return whole;

  if (!hasPoint && /,[0-9]{1,2}$/.test(whole)) return unclear(COMMA_FOR_CENTS);

  const separators = new Set(whole.replace(/[0-9]/g, ''));
  const [first = '', ...rest] = whole.split(/[, ]/);

  if (separators.size > 1 || !/^[0-9]{1,3}$/.test(first) || rest.some((group) => !/^[0-9]{3}$/.test(group))) {
    return unclear(BAD_GROUPS);
  }

  return first + rest.join('');
}

function unclear(message: string): TypedAmount {
  return { kind: 'unclear', message };
}
