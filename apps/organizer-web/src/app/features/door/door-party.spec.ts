import { describe, expect, it } from 'vitest';
import { MOST_AT_ONCE, partyKey, partySize } from '@myfiesta/door';

/**
 * "How many", from `@myfiesta/door`, for the console and the phone alike.
 *
 * The box said min 1 and max 50, and the form was novalidate: 0 went as blank
 * and let a whole table in, and 1.5 was decided offline like any other number.
 */
describe('how many a scan lets in', () => {
  it('is everyone still outstanding when left blank', () => {
    for (const blank of ['', '   ', null, undefined]) {
      expect(partySize(blank), JSON.stringify(blank)).toEqual({ ok: true, party: null });
    }
  });

  it('is a whole number from 1 to the most the server takes at once', () => {
    expect(MOST_AT_ONCE).toBe(50);
    expect(partySize('1')).toEqual({ ok: true, party: 1 });
    expect(partySize(' 2 ')).toEqual({ ok: true, party: 2 });
    // What Angular reads out of a number box.
    expect(partySize(4)).toEqual({ ok: true, party: 4 });
    expect(partySize(50)).toEqual({ ok: true, party: 50 });
  });

  it('is refused, and said, for anything that is not a number of people', () => {
    for (const typed of [1.5, '1.5', '1,5', 0, '0', -2, '-2', 51, '80', 'two', '1e1', NaN, Infinity]) {
      const size = partySize(typed);

      expect(size.ok, String(typed)).toBe(false);
      expect(size.ok ? null : size.message).toBe(
        'How many has to be a whole number from 1 to 50. Leave it blank to let in everyone on the ticket.',
      );
    }
  });

  it('keeps the keys a number box allows but no party needs out of the box', () => {
    for (const key of ['0', '7', 'Backspace', 'Delete', 'ArrowLeft', 'Tab', 'Enter', 'Unidentified']) {
      expect(partyKey(key), key).toBe(true);
    }

    for (const key of ['.', ',', '-', '+', 'e', 'E', ' ', 'a']) {
      expect(partyKey(key), key).toBe(false);
    }

    // Paste, select all: what arrives that way is checked by partySize.
    expect(partyKey('v', true)).toBe(true);
  });
});
