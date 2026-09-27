import { formatMoney, toMajorUnits, toMinorUnits } from './money';

/**
 * Money crosses this boundary and no other.
 *
 * The platform being replaced kept cents in some columns and dollars in
 * others, and formatted currency strings inside its API, so the same value
 * meant different things at different layers.
 */
describe('money', () => {
  it('formats an amount with its own currency, not a guessed one', () => {
    // A Lagos event sitting next to a Toronto one is exactly when guessing
    // goes wrong, so the currency always travels with the amount.
    const cad = formatMoney({ amount: 2500, currency: 'CAD' });
    const ngn = formatMoney({ amount: 250000, currency: 'NGN' });

    // The reader's locale chooses the separators — "2,500.00" in Toronto,
    // "2 500,00" in Montréal — so only the digits are pinned. Pinning the
    // punctuation made this pass or fail on the language of the machine.
    expect(cad.replace(/\D/g, '')).toBe('2500');
    // Whole naira: ₦2,500, not ₦2,500.00.
    expect(ngn.replace(/\D/g, '')).toBe('2500');
    expect(cad.replace(/[\d\s.,]/g, '')).not.toBe(ngn.replace(/[\d\s.,]/g, ''));
  });

  it('writes the symbols both markets write, in any locale', () => {
    // The plain Intl symbol wrote "NGN 5,000.00" in a Canadian locale and
    // "CA$25.00" everywhere outside one. Neither is how anybody writes a price.
    const cad = formatMoney({ amount: 2500, currency: 'CAD' });
    const ngn = formatMoney({ amount: 500000, currency: 'NGN' });

    expect(cad).toContain('$');
    expect(cad).not.toContain('CA');
    expect(ngn).toContain('₦');
    expect(ngn).not.toContain('NGN');
  });

  it('keeps kobo when there are some, and cents always', () => {
    // A discount or VAT can leave kobo; rounding them away would make a
    // breakdown that does not add up.
    expect(formatMoney({ amount: 20625, currency: 'NGN' }).replace(/\D/g, '')).toBe('20625');
    expect(formatMoney({ amount: 2000, currency: 'CAD' }).replace(/\D/g, '')).toBe('2000');
  });

  it('shows a dash rather than zero when there is no amount', () => {
    // "$0.00" is a claim about price. A missing figure is not.
    expect(formatMoney(null)).toBe('—');
    expect(formatMoney(undefined)).toBe('—');
  });

  it('converts what a person types into minor units', () => {
    expect(toMinorUnits('25.00')).toBe(2500);
    expect(toMinorUnits('25.5')).toBe(2550);
    expect(toMinorUnits(0)).toBe(0);
  });

  it('rounds rather than truncating, so a cent is never quietly lost', () => {
    expect(toMinorUnits('19.995')).toBe(2000);
    expect(toMinorUnits('0.005')).toBe(1);
  });

  it('treats unparseable input as zero rather than NaN', () => {
    // NaN reaching the API becomes a price nobody can explain.
    expect(toMinorUnits('')).toBe(0);
    expect(toMinorUnits('abc')).toBe(0);
  });

  it('round-trips a price without drift', () => {
    for (const price of ['0.01', '9.99', '25.00', '1234.56']) {
      expect(toMinorUnits(String(toMajorUnits(toMinorUnits(price))))).toBe(toMinorUnits(price));
    }
  });
});
