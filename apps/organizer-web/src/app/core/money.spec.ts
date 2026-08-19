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
    expect(formatMoney({ amount: 2500, currency: 'CAD' })).toContain('25.00');
    expect(formatMoney({ amount: 250000, currency: 'NGN' })).toContain('2,500.00');
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
