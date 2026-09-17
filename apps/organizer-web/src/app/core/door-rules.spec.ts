import fixture from '../../../../../packages/contract/fixtures/door-hash.json';
import { DoorListTicket, admittedAfter, decideOffline, hashCode } from '@myfiesta/door';

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
    it('lets the whole party in when no number is given', () => {
      const outcome = decideOffline(ticket({ admits: 4 }), null);

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
      expect(admittedAfter(ticket({ admits: 4, admitted_count: 1 }), null)).toBe(4);
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
