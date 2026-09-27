import { describe, expect, it } from 'vitest';
import { currencySymbol, formatMoney } from './money';

/**
 * How a price reads on the phone.
 *
 * The phone is in the hands of people in both markets with their phones set to
 * whatever language they like, and the plain Intl symbol followed the phone
 * rather than the price: "CA$25.00" on a phone set to anything but Canada,
 * "NGN 5,000.00" on one that was. Only digits and symbols are pinned here —
 * the phone's locale still chooses the separators.
 */
describe('formatMoney', () => {
  it('writes dollars as $ and naira as ₦', () => {
    const cad = formatMoney({ amount: 2500, currency: 'CAD' });
    const ngn = formatMoney({ amount: 500000, currency: 'NGN' });

    expect(cad).toContain('$');
    expect(cad).not.toContain('CA');
    expect(ngn).toContain('₦');
    expect(ngn).not.toContain('NGN');
  });

  it('writes naira whole, the way prices are set in Nigeria', () => {
    expect(formatMoney({ amount: 500000, currency: 'NGN' }).replace(/\D/g, '')).toBe('5000');
  });

  it('keeps kobo and cents when there are some', () => {
    // A discount or VAT can leave kobo, and a breakdown has to add up.
    expect(formatMoney({ amount: 20625, currency: 'NGN' }).replace(/\D/g, '')).toBe('20625');
    expect(formatMoney({ amount: 2500, currency: 'CAD' }).replace(/\D/g, '')).toBe('2500');
  });

  it('is a dash, not a zero, when there is no amount', () => {
    expect(formatMoney(null)).toBe('—');
    expect(formatMoney(undefined)).toBe('—');
  });
});

describe('currencySymbol', () => {
  it('prefixes a price field with the symbol people write', () => {
    expect(currencySymbol('CAD')).toBe('$');
    expect(currencySymbol('NGN')).toBe('₦');
  });
});
