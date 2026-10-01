import fixture from '../../../../../packages/contract/fixtures/door-hash.json';
import {
  CHOOSE_PARTY,
  DoorListTicket,
  ScanResult,
  admittedAfter,
  asksHowMany,
  decideOffline,
  hashCode,
  queuedParty,
} from '@myfiesta/door';

const ticket = (over: Partial<DoorListTicket> = {}): DoorListTicket =>
  ({
    holder_name: 'Ada Okoro',
    type: 'General',
    status: 'valid',
    admits: 1,
    admitted_count: 0,
    ...over,
  }) as DoorListTicket;

/**
 * The door, with no signal.
 *
 * This is the code that admits or refuses people when the venue's wifi is a
 * rumour, and until now nothing tested it. A door that answers differently
 * offline from online is a door whose staff stop trusting either answer, so
 * every case here is one CheckInService decides the same way on the server.
 */
describe('deciding at a door with no signal', () => {
  it('lets one person in on a single ticket', () => {
    const outcome = decideOffline(ticket(), null);

    expect(outcome.accepted).toBe(true);
    expect(outcome.admitted).toBe(1);
    expect(outcome.remaining).toBe(0);
    expect(outcome.message).toBe('Admitted.');
    // Marked, so whoever reads it knows this was decided on the phone.
    expect(outcome.offline).toBe(true);
  });

  it('refuses a code that is not on this phone, and says why it might not be', () => {
    const outcome = decideOffline(null, null);

    expect(outcome.result).toBe('not_found');
    expect(outcome.accepted).toBe(false);
    // A ticket bought minutes ago is genuinely valid and genuinely absent.
    // Door staff need to know which kind of "no" this is.
    expect(outcome.message).toContain('signal is back');
  });

  it('refuses a cancelled ticket whichever way it was cancelled', () => {
    for (const status of ['refunded', 'void']) {
      const outcome = decideOffline(ticket({ status }), null);

      expect(outcome.result).toBe('void');
      expect(outcome.accepted).toBe(false);
      expect(outcome.message).toBe('This ticket was cancelled.');
    }
  });

  it('refuses a ticket handed back for resale, as the server does', () => {
    // It let them in. A ticket given back for its money still opened any
    // door whose signal was down, while its place went on sale again.
    for (const over of [{}, { admits: 4 }]) {
      const outcome = decideOffline(ticket({ status: 'listed', ...over }), null);

      expect(outcome.result).toBe('void');
      expect(outcome.accepted).toBe(false);
      expect(outcome.admitted).toBe(0);
      expect(outcome.message).toBe('This ticket was handed back and is waiting to be resold.');
    }

    // Nor with a number given for a table.
    expect(decideOffline(ticket({ status: 'listed', admits: 4 }), 2).accepted).toBe(false);
  });

  it('calls a second scan of a single ticket a duplicate', () => {
    const outcome = decideOffline(ticket({ admitted_count: 1 }), null);

    expect(outcome.result).toBe('duplicate');
    expect(outcome.message).toBe('Already scanned.');
  });

  it('says how many a group ticket already brought in', () => {
    const outcome = decideOffline(ticket({ admits: 4, admitted_count: 4 }), null);

    expect(outcome.result).toBe('duplicate');
    expect(outcome.message).toBe('All 4 already came in.');
  });

  describe('a ticket that admits several', () => {
    /**
     * No number used to mean everyone the ticket had left. The first of a
     * table held its ticket up, the whole table was counted in, and the rest
     * walked in later unscanned. The server asks now, and so does the list.
     */
    it('lets nobody in when no number is given, and asks how many are here', () => {
      const outcome = decideOffline(ticket({ admits: 4, type: 'Table of 4' }), null);

      expect(outcome.result).toBe(CHOOSE_PARTY);
      expect(asksHowMany(outcome)).toBe(true);
      expect(outcome.accepted).toBe(false);
      expect(outcome.admitted).toBe(0);
      // Everything the question needs, from the list alone.
      expect(outcome.remaining).toBe(4);
      expect(outcome.ticket).toMatchObject({ holder_name: 'Ada Okoro', type: 'Table of 4', admits: 4, admitted_count: 0 });
      // The server's words, for a door that shows them rather than asking.
      expect(outcome.message).toBe(
        'This ticket admits 4, nobody in yet. Put how many are going in now in How many, and scan it again.',
      );
      expect(outcome.offline).toBe(true);
    });

    it('asks again for the rest of a table, saying how many are already in', () => {
      const outcome = decideOffline(ticket({ admits: 4, admitted_count: 1 }), null);

      expect(outcome.result).toBe(CHOOSE_PARTY);
      expect(outcome.remaining).toBe(3);
      expect(outcome.message).toContain('This ticket admits 4, 1 already in.');
    });

    it('lets the last of a table in without a question', () => {
      const outcome = decideOffline(ticket({ admits: 4, admitted_count: 3 }), null);

      expect(outcome.result).toBe('accepted');
      expect(outcome.admitted).toBe(1);
      expect(outcome.remaining).toBe(0);
    });

    it('refuses a table that is spent or cancelled rather than asking about it', () => {
      expect(decideOffline(ticket({ admits: 4, admitted_count: 4 }), null).result).toBe('duplicate');
      expect(decideOffline(ticket({ admits: 4, status: 'void' }), null).result).toBe('void');
    });

    it('lets the whole party in when the door says all of them are here', () => {
      const outcome = decideOffline(ticket({ admits: 4 }), 4);

      expect(outcome.admitted).toBe(4);
      expect(outcome.remaining).toBe(0);
      expect(outcome.message).toBe('Admitted 4. That is everyone.');
    });

    it('lets part of a party in and says who is still outside', () => {
      const outcome = decideOffline(ticket({ admits: 4 }), 2);

      expect(outcome.accepted).toBe(true);
      expect(outcome.admitted).toBe(2);
      expect(outcome.remaining).toBe(2);
      expect(outcome.message).toBe('Admitted 2. 2 still to come.');
      expect(outcome.ticket?.admitted_count).toBe(2);
    });

    it('counts from what already came in rather than from zero', () => {
      const outcome = decideOffline(ticket({ admits: 4, admitted_count: 3 }), 1);

      expect(outcome.accepted).toBe(true);
      expect(outcome.remaining).toBe(0);
      expect(outcome.ticket?.admitted_count).toBe(4);
    });

    it('refuses more people than the ticket has places, rather than rounding down', () => {
      const outcome = decideOffline(ticket({ admits: 4, admitted_count: 3 }), 2);

      // Rounding down would admit one of two friends and say yes, which is a
      // row at the door rather than an answer.
      expect(outcome.accepted).toBe(false);
      expect(outcome.result).toBe('over_capacity');
      expect(outcome.message).toBe('Only 1 place left on this ticket.');
      expect(outcome.remaining).toBe(1);
    });

    it('uses plural words when more than one place is left', () => {
      expect(decideOffline(ticket({ admits: 5, admitted_count: 1 }), 5).message).toBe(
        'Only 4 places left on this ticket.',
      );
    });
  });

  describe('what gets written down afterwards', () => {
    it('matches what was admitted, so nobody is counted twice', () => {
      expect(admittedAfter(ticket({ admits: 4 }), 2)).toBe(2);
      expect(admittedAfter(ticket({ admits: 4, admitted_count: 2 }), 2)).toBe(4);
    });

    it('never exceeds the places on the ticket', () => {
      expect(admittedAfter(ticket({ admits: 2, admitted_count: 1 }), 9)).toBe(2);
    });

    it('takes the rest of the ticket when no number was given', () => {
      // What a queued scan from a door that never asked meant by it.
      expect(admittedAfter(ticket({ admits: 4, admitted_count: 1 }), null)).toBe(4);
    });
  });

  /**
   * The server reads a queued scan with no number as everyone the ticket had
   * left: what doors from before the question meant. So a door that asks puts
   * the number that went in on anything for more than one.
   */
  describe('what a scan made with no signal is queued with', () => {
    const decided = (over: Partial<ScanResult>): ScanResult => ({
      result: 'accepted',
      accepted: true,
      admitted: 1,
      remaining: 0,
      message: 'Admitted.',
      ticket: { holder_name: 'Ada Okoro', type: 'General', admits: 1, admitted_count: 1 },
      offline: true,
      ...over,
    });

    it('is the number asked for, typed or chosen', () => {
      expect(queuedParty(2, decided({ admitted: 2 }))).toBe(2);
      expect(queuedParty(3, decided({ result: 'over_capacity', accepted: false, admitted: 0 }))).toBe(3);
    });

    it('is the number that went in on the last place of a table, let in without a question', () => {
      const last = decideOffline(ticket({ admits: 4, admitted_count: 3 }), null);

      expect(queuedParty(null, last)).toBe(1);
    });

    it('is still no number on an ordinary ticket, or a refusal', () => {
      expect(queuedParty(null, decideOffline(ticket(), null))).toBeNull();
      expect(queuedParty(null, decideOffline(ticket({ admits: 4, admitted_count: 4 }), null))).toBeNull();
    });
  });
});

/**
 * The phone's half of a hash two languages have to agree on.
 *
 * The other half is DoorHashFixtureTest on the API. Both are pinned to the
 * same fixture rather than to each other, because neither can run the other's
 * runtime — and if they ever disagreed, every offline scan would read as a
 * ticket nobody recognises.
 */
describe('hashing a code the way the server hashed the list', () => {
  for (const testCase of fixture.cases) {
    it(testCase.why, async () => {
      await expect(hashCode(testCase.code, fixture.salt, fixture.iterations)).resolves.toBe(testCase.hash);
    });
  }
});
