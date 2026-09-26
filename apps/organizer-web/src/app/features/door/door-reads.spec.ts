import { describe, expect, it } from 'vitest';
import { RepeatReads, SAME_TICKET_AGAIN_AFTER_MS, ticketCode } from '@myfiesta/door';

/**
 * What a door does with what its camera reads, from `@myfiesta/door`.
 *
 * Until the console's camera worked, nothing it read could reach the server.
 * Now it reads whatever is held up to it, several times a second, on every
 * browser — and a door acts on a scan by letting somebody in.
 */
describe('what a door acts on', () => {
  describe('a code shaped like a ticket', () => {
    it('is sent as the server reads it: trimmed and upper-cased', () => {
      expect(ticketCode('  wfy7-f77k4ejw\n')).toBe('WFY7-F77K4EJW');
      // The format the last platform printed, kept on imported tickets.
      expect(ticketCode('MFST-9K2L4XQ7')).toBe('MFST-9K2L4XQ7');
    });

    it('is 32 characters at most, the width of the column every code is kept in', () => {
      expect(ticketCode('A'.repeat(32))).toBe('A'.repeat(32));
      expect(ticketCode('A'.repeat(33))).toBeNull();
    });

    it('is not a link, a payment code or anything else a camera can read', () => {
      for (const raw of [
        'https://example.com/pay?ref=123',
        'WIFI:S:Venue;T:WPA;P:secret;;',
        'WFY7 F77K4EJW',
        'WFY7_F77K4EJW',
        '​WFY7-F77K4EJW',
        '',
        '   ',
        'AB',
      ]) {
        expect(ticketCode(raw), JSON.stringify(raw)).toBeNull();
      }
    });
  });

  describe('one ticket held up is one scan', () => {
    const second = 1000;

    it('acts on a ticket once for as long as it stays in view, however long that is', () => {
      const reads = new RepeatReads();

      expect(reads.take('WFY7-F77K4EJW', false, 0)).toBe(true);
      reads.answered('WFY7-F77K4EJW', 300);

      // Held up through the ID check, read five times a second for a minute.
      for (let at = 500; at <= 60 * second; at += 200) {
        expect(reads.take('WFY7-F77K4EJW', false, at)).toBe(false);
      }
    });

    it('does not act again while the answer is slow in coming, whatever the camera sees', () => {
      const reads = new RepeatReads();

      expect(reads.take('WFY7-F77K4EJW', false, 0)).toBe(true);

      // Lowered at once, and raised again five seconds later to ask whether it worked.
      expect(reads.take('WFY7-F77K4EJW', true, 5 * second)).toBe(false);

      // The answer, after the six seconds the door waits before deciding offline.
      reads.answered('WFY7-F77K4EJW', 6 * second);

      expect(reads.take('WFY7-F77K4EJW', false, 6 * second + 500)).toBe(false);
    });

    it('acts again on a ticket shown again after it has been away, so a used one is heard about', () => {
      const reads = new RepeatReads();

      reads.take('WFY7-F77K4EJW', false, 0);
      reads.answered('WFY7-F77K4EJW', 400);

      expect(reads.take('WFY7-F77K4EJW', false, 400 + SAME_TICKET_AGAIN_AFTER_MS)).toBe(true);
    });

    it('acts on a different ticket straight away, once the door is free', () => {
      const reads = new RepeatReads();

      reads.take('WFY7-F77K4EJW', false, 0);

      // Still waiting on the first: not started, and not remembered as seen.
      expect(reads.take('MFST-9K2L4XQ7', true, 200)).toBe(false);

      reads.answered('WFY7-F77K4EJW', 400);

      expect(reads.take('MFST-9K2L4XQ7', false, 600)).toBe(true);
    });

    it('starts nothing while the door is busy with a typed code', () => {
      const reads = new RepeatReads();

      expect(reads.take('WFY7-F77K4EJW', true, 0)).toBe(false);
      expect(reads.take('WFY7-F77K4EJW', false, 200)).toBe(true);
    });
  });
});
