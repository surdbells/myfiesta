import { describe, expect, it } from 'vitest';
import { amountProblem, currencySymbol, formatMoney, toMajor, toMinor, writesCentsAfterComma } from './money';

const NARROW_NO_BREAK_SPACE = String.fromCharCode(0x202f);

/**
 * What an organizer types in a price box on the phone.
 *
 * It used to turn the comma into a decimal point, so ₦5,000 went on sale as
 * ₦5.00. Each case below is pinned with the keypad it was typed on — one with
 * a point (false), or one that writes cents after a comma (true), as a phone
 * set to French does — so the answer does not depend on the language of the
 * machine running the tests.
 */
describe('toMinor', () => {
  it('reads thousands the way both markets type them', () => {
    expect(toMinor('5,000', false)).toBe(500000);
    expect(toMinor('5000', false)).toBe(500000);
    expect(toMinor('1,250.50', false)).toBe(125050);
    expect(toMinor('1 250', false)).toBe(125000);
    expect(toMinor(`1${NARROW_NO_BREAK_SPACE}250`, false)).toBe(125000);
    expect(toMinor('12.5', false)).toBe(1250);
    expect(toMinor('0.99', false)).toBe(99);
    expect(toMinor('₦5,000', false)).toBe(500000);
  });

  it('never turns 5,000 into 5, whichever keypad typed it', () => {
    expect(toMinor('5,000', false)).toBe(500000);
    expect(toMinor('5,000', true)).toBe(500000);
  });

  it('asks about 5,00 on a keypad with a point, rather than guess five or five hundred', () => {
    expect(toMinor('5,00', false)).toBeNull();
    expect(amountProblem('5,00', false)).toBe('Use a point for cents, like 5.00. A comma separates thousands, like 5,000.');
  });

  it('reads 5,00 as five on a keypad that has only a comma — never as five hundred', () => {
    expect(toMinor('5,00', true)).toBe(500);
    expect(toMinor('1 250,50', true)).toBe(125050);
    // The same mark for thousands and for cents is still a question.
    expect(toMinor('1,250,50', true)).toBeNull();
  });

  it('has nothing to say about an empty box, and says what is wrong with anything else', () => {
    expect(toMinor('', false)).toBeNull();
    expect(amountProblem('', false)).toBeNull();
    expect(toMinor('abc', false)).toBeNull();
    expect(amountProblem('abc', false)).toBe('Type the amount in numbers, like 5,000 or 25.50.');
    expect(toMinor('5.000', false)).toBeNull();
  });
});

describe('toMajor', () => {
  it('groups thousands, so what was read can be seen', () => {
    expect(toMajor(500000, false)).toBe('5,000.00');
    expect(toMajor(125050, false)).toBe('1,250.50');
    expect(toMajor(99, false)).toBe('0.99');
    expect(toMajor(500000, true)).toBe('5 000,00');
  });

  it('reads back as the same amount on either keypad', () => {
    for (const amount of [0, 99, 2500, 125050, 500000, 123456789]) {
      expect(toMinor(toMajor(amount, false), false)).toBe(amount);
      expect(toMinor(toMajor(amount, true), true)).toBe(amount);
    }
  });
});

describe('writesCentsAfterComma', () => {
  it('knows a French keypad from an English one', () => {
    expect(writesCentsAfterComma('fr-CA')).toBe(true);
    expect(writesCentsAfterComma('en-CA')).toBe(false);
    expect(writesCentsAfterComma('en-NG')).toBe(false);
  });
});

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
