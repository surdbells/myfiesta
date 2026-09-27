import { formatFrom, formatMoney } from './money';

/**
 * Money arrives from the API as minor units and a currency, never as a
 * formatted string. These pin the one place the web turns that into text.
 */
describe('formatMoney', () => {
  it('reads minor units as dollars', () => {
    expect(formatMoney({ amount: 2500, currency: 'CAD' })).toBe('$25.00');
  });

  it('writes naira with the naira sign, the way Nigerian prices are written', () => {
    // The plain Intl symbol in a Canadian locale is "NGN 5,000.00", which is
    // not how anybody in Lagos reads a price.
    expect(formatMoney({ amount: 500000, currency: 'NGN' })).toBe('₦5,000');
    expect(formatMoney({ amount: 150000, currency: 'NGN' })).toBe('₦1,500');
  });

  it('keeps kobo when an amount has some, so a breakdown still adds up', () => {
    // 7.5% VAT on ₦2,750 is ₦206.25. Rounding it to ₦206 would leave a total
    // that is a quarter of a naira off its own lines.
    expect(formatMoney({ amount: 20625, currency: 'NGN' })).toBe('₦206.25');
    expect(formatMoney({ amount: 500050, currency: 'NGN' })).toBe('₦5,000.50');
  });

  it('keeps the two currencies apart at a glance', () => {
    expect(formatMoney({ amount: 2500, currency: 'CAD' })).not.toContain('₦');
    expect(formatMoney({ amount: 2500, currency: 'NGN' })).not.toContain('$');
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
    expect(formatFrom(null, 'NGN')).toBe('₦—');
  });

  it('shows the lowest price otherwise', () => {
    expect(formatFrom({ amount: 2000, currency: 'CAD' }, 'CAD')).toBe('$20.00');
    expect(formatFrom({ amount: 500000, currency: 'NGN' }, 'NGN')).toBe('₦5,000');
  });
});
