import { describe, expect, it } from 'vitest';
import {
  EACH_NUMBER_UP_TO,
  MOST_AT_ONCE,
  ScanResult,
  askingAbout,
  partOfParty,
  partyChoices,
  partyKey,
  partySize,
} from '@myfiesta/door';

/**
 * "How many", from `@myfiesta/door`, for the console and the phone alike.
 *
 * The box said min 1 and max 50, and the form was novalidate: 0 went as blank
 * and let a whole table in, and 1.5 was decided offline like any other number.
 */
describe('how many a scan lets in', () => {
  it('is no number when left blank: the one place left, or the question when there are more', () => {
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
        'How many has to be a whole number from 1 to 50. Leave it blank to be asked when the ticket is for more than one.',
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

/**
 * What a door offers when a table's ticket is scanned with nothing in "How
 * many": everyone in one tap, and every smaller number.
 */
describe('asking how many of a party are here', () => {
  it('offers everyone and a button for each smaller number, for a handful', () => {
    expect(EACH_NUMBER_UP_TO).toBe(6);
    expect(partyChoices(4)).toEqual({ all: 4, each: [1, 2, 3], upTo: null });
    expect(partyChoices(2)).toEqual({ all: 2, each: [1], upTo: null });
    expect(partyChoices(6)).toEqual({ all: 6, each: [1, 2, 3, 4, 5], upTo: null });
  });

  it('offers a stepper instead beyond a handful, since a row of buttons is too many to read in the dark', () => {
    expect(partyChoices(7)).toEqual({ all: 7, each: [], upTo: 6 });
    expect(partyChoices(20)).toEqual({ all: 20, each: [], upTo: 19 });
  });

  it('offers nothing past what the server lets in on one scan', () => {
    expect(partyChoices(50)).toEqual({ all: 50, each: [], upTo: 49 });
    // A table of 60 goes in fifty at a time at most, as it would be typed.
    expect(partyChoices(60)).toEqual({ all: null, each: [], upTo: MOST_AT_ONCE });
  });

  const table = (admits: number, inside: number, over: Partial<ScanResult> = {}): ScanResult => ({
    result: 'choose_party',
    accepted: false,
    admitted: 0,
    remaining: admits - inside,
    message: '',
    ticket: { holder_name: 'Chidi Nwosu', type: `Table of ${admits}`, admits, admitted_count: inside },
    ...over,
  });

  it('says whose ticket it is, what it is and how many are already in', () => {
    expect(askingAbout(table(4, 0))).toBe('Chidi Nwosu · Table of 4 · none of 4 in yet');
    expect(askingAbout(table(4, 1))).toBe('Chidi Nwosu · Table of 4 · 1 of 4 already in');
  });

  it('says after part of a table went in how many are in and how many are still to come', () => {
    const first = table(4, 2, { result: 'accepted', accepted: true, admitted: 2, remaining: 2 });
    const second = table(4, 3, { result: 'accepted', accepted: true, admitted: 1, remaining: 1 });

    expect(partOfParty(first)).toBe('2 of 4 in — 2 still to come');
    // Counted on the ticket, not the scan: the number the door is keeping.
    expect(partOfParty(second)).toBe('3 of 4 in — 1 still to come');
    // Nothing to say once everyone is in, or on anything but an admission.
    expect(partOfParty(table(4, 4, { result: 'accepted', accepted: true, admitted: 2, remaining: 0 }))).toBeNull();
    expect(partOfParty(table(4, 1))).toBeNull();
  });
});
