import { formatFrom, formatMoney } from './money';

/**
 * Money arrives from the API as minor units and a currency, never as a
 * formatted string. These pin the one place the web turns that into text.
 */
describe('formatMoney', () => {
  it('reads minor units as dollars', () => {
    expect(formatMoney({ amount: 2500, currency: 'CAD' })).toBe('$25.00');
  });

  it('keeps the currency visible for naira, so the two are never confused', () => {
    expect(formatMoney({ amount: 150000, currency: 'NGN' })).toContain('1,500.00');
    expect(formatMoney({ amount: 150000, currency: 'NGN' })).toContain('NGN');
  });

  it('says nothing rather than zero when there is no amount', () => {
    expect(formatMoney(null)).toBe('—');
  });

  it('does not drift on amounts floating point cannot hold exactly', () => {
    expect(formatMoney({ amount: 1999, currency: 'CAD' })).toBe('$19.99');
    expect(formatMoney({ amount: 7260, currency: 'CAD' })).toBe('$72.60');
  });
});

describe('formatFrom', () => {
  it('shows the right symbol with a dash when nothing is on sale', () => {
    expect(formatFrom(null, 'CAD')).toBe('$—');
    expect(formatFrom(null, 'NGN')).toContain('NGN');
  });

  it('shows the lowest price otherwise', () => {
    expect(formatFrom({ amount: 2000, currency: 'CAD' }, 'CAD')).toBe('$20.00');
  });
});
