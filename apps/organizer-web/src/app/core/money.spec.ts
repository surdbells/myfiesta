import { amountProblem, formatMoney, readAmount, toMajorUnits, toMinorUnits } from './money';

const NO_BREAK_SPACE = String.fromCharCode(0x00a0);
const NARROW_NO_BREAK_SPACE = String.fromCharCode(0x202f);

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

  it('reads thousands the way both markets type them', () => {
    // parseFloat stopped at the comma: a ₦5,000 ticket was saved at ₦5.
    expect(toMinorUnits('5,000')).toBe(500000);
    expect(toMinorUnits('5000')).toBe(500000);
    expect(toMinorUnits('1,250.50')).toBe(125050);
    expect(toMinorUnits('1 250')).toBe(125000);
    // The narrow no-break space a French locale or a copied figure puts there,
    // and the ordinary no-break one.
    expect(toMinorUnits(`1${NARROW_NO_BREAK_SPACE}250`)).toBe(125000);
    expect(toMinorUnits(`1${NO_BREAK_SPACE}250 000`)).toBe(125000000);
    expect(toMinorUnits('12.5')).toBe(1250);
    expect(toMinorUnits('0.99')).toBe(99);
    expect(toMinorUnits('₦5,000')).toBe(500000);
    expect(toMinorUnits('$25')).toBe(2500);
  });

  it('is exact, not a float rounded', () => {
    expect(toMinorUnits('19.99')).toBe(1999);
    expect(toMinorUnits('1234.56')).toBe(123456);
  });

  it('asks rather than guessing at a comma before cents', () => {
    // "5,00" is five dollars on a phone set to French and five hundred by the
    // thousands rule. Either reading could be out by a factor of a hundred,
    // so it is neither.
    expect(toMinorUnits('5,00')).toBeNull();
    expect(amountProblem('5,00')).toContain('Use a point for cents');
    expect(toMinorUnits('1 250,50')).toBeNull();
  });

  it('asks rather than guessing at a point before thousands', () => {
    expect(toMinorUnits('5.000')).toBeNull();
    expect(amountProblem('5.000')).toContain('For thousands, use a comma');
    expect(toMinorUnits('1.250.000')).toBeNull();
    // Not rounded to the cent: 19.995 could be a typo for either side of it.
    expect(toMinorUnits('19.995')).toBeNull();
  });

  it('never quietly shortens a figure', () => {
    for (const typed of ['5,000', '50,000', '1,000,000', '5 000']) {
      expect(toMinorUnits(typed)! % 100000).toBe(0);
    }

    // Groups that are not thousands are a question, not a number.
    expect(toMinorUnits('50,0000')).toBeNull();
    expect(amountProblem('50,0000')).toContain('groups of three');
  });

  it('has nothing to say about an empty box, and says what is wrong with anything else', () => {
    // It used to be zero for both — which is a free ticket.
    expect(toMinorUnits('')).toBeNull();
    expect(amountProblem('')).toBeNull();
    expect(readAmount('   ').kind).toBe('empty');

    expect(toMinorUnits('abc')).toBeNull();
    expect(amountProblem('abc')).toBe('Type the amount in numbers, like 5,000 or 25.50.');
    expect(amountProblem('-5')).toBe('An amount cannot be less than zero.');
  });

  it('round-trips a price without drift', () => {
    for (const price of ['0.01', '9.99', '25.00', '1234.56']) {
      expect(toMinorUnits(String(toMajorUnits(toMinorUnits(price)!)))).toBe(toMinorUnits(price));
    }
  });
});
