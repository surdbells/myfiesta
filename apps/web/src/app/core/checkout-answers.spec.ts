import { describe, expect, it } from 'vitest';
import { answered, attendeesFor, missing, slotsFor, withAnswer } from './checkout-answers';
import type { Question, QuoteLine } from './api.types';

const line = (id: string, name: string, quantity: number): QuoteLine =>
  ({
    ticket_type_id: id,
    name,
    quantity,
    unit_price: { amount: 1000, currency: 'CAD' },
    line_total: { amount: 1000 * quantity, currency: 'CAD' },
    discount: { amount: 0, currency: 'CAD' },
  }) as QuoteLine;

const ask = (over: Partial<Question> = {}): Question => ({
  id: 'q1',
  label: 'Name on the ticket',
  type: 'text',
  options: [],
  required: true,
  per_attendee: false,
  ...over,
});

/**
 * Three rules with one right answer each, tested without a browser: which
 * ticket is the second General, whether something counts as answered, and what
 * the server is sent.
 */
describe('checkout answers', () => {
  describe('a slot per ticket', () => {
    it('numbers each ticket within its own tier, in the order they will be minted', () => {
      const slots = slotsFor([line('a', 'Early Bird', 2), line('b', 'General', 1)]);

      // The position within a tier is what ties an answer to the ticket that
      // person ends up holding; the position overall is what the server's
      // refusal counts when it says "ticket 2".
      expect(slots.map((slot) => slot.key)).toEqual(['a:0', 'a:1', 'b:0']);
      expect(slots.map((slot) => slot.name)).toEqual(['Early Bird', 'Early Bird', 'General']);
    });

    it('has nothing to fill in for an empty basket', () => {
      expect(slotsFor([])).toEqual([]);
    });
  });

  describe('what counts as an answer', () => {
    it('takes no for one', () => {
      // "No, I do not need step-free access" is what an organizer plans
      // around. Treating false as blank would refuse the order.
      expect(answered(false)).toBe(true);
      expect(answered(true)).toBe(true);
    });

    it('does not take a space, an empty box, or nothing at all', () => {
      expect(answered('   ')).toBe(false);
      expect(answered([])).toBe(false);
      expect(answered(undefined)).toBe(false);
      expect(answered(null)).toBe(false);
    });
  });

  describe('what is still missing', () => {
    const slots = slotsFor([line('a', 'General', 2)]);

    it('is nothing when the organizer asks nothing', () => {
      expect(missing([], {}, slots, {})).toBe(false);
    });

    it('holds the order until a required question is answered', () => {
      expect(missing([ask()], {}, slots, {})).toBe(true);
      expect(missing([ask()], { q1: 'Instagram' }, slots, {})).toBe(false);
    });

    it('lets an optional question stay unanswered', () => {
      expect(missing([ask({ required: false })], {}, slots, {})).toBe(false);
    });

    it('asks about every person, not just the first', () => {
      const question = ask({ per_attendee: true });

      expect(missing([question], {}, slots, { 'a:0': { q1: 'Ada' } })).toBe(true);
      expect(missing([question], {}, slots, { 'a:0': { q1: 'Ada' }, 'a:1': { q1: 'Tunde' } })).toBe(false);
    });
  });

  describe('what the server is sent', () => {
    it('is one entry per ticket, carrying its own answers', () => {
      const slots = slotsFor([line('a', 'Early Bird', 2), line('b', 'General', 1)]);

      expect(attendeesFor(slots, { 'a:0': { q1: 'Ada' }, 'b:0': { q1: 'Zara' } })).toEqual([
        { ticket_type_id: 'a', answers: { q1: 'Ada' } },
        // Nobody typed anything for this one; it still has to be there, or
        // the count no longer matches what is being bought.
        { ticket_type_id: 'a', answers: {} },
        { ticket_type_id: 'b', answers: { q1: 'Zara' } },
      ]);
    });
  });

  describe('recording an answer', () => {
    it('keeps what was said', () => {
      expect(withAnswer({}, 'q1', 'Ada')).toEqual({ q1: 'Ada' });
      expect(withAnswer({}, 'q1', ['Friday', 'Sunday'])).toEqual({ q1: ['Friday', 'Sunday'] });
      expect(withAnswer({}, 'q1', false)).toEqual({ q1: false });
    });

    it('forgets a field that was emptied again', () => {
      // A blank stored against a label is something an organizer reads as
      // though somebody had written it.
      expect(withAnswer({ q1: 'Ada' }, 'q1', '')).toEqual({});
      expect(withAnswer({ q1: ['Friday'] }, 'q1', [])).toEqual({});
      expect(withAnswer({ q1: 'Ada' }, 'q1', null)).toEqual({});
    });

    it('leaves the other answers alone', () => {
      expect(withAnswer({ q1: 'Ada', q2: 'Yes' }, 'q1', 'Tunde')).toEqual({ q1: 'Tunde', q2: 'Yes' });
    });
  });
});
