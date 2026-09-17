import type { AnswerValue, Attendee, Question, QuoteLine } from './api.types';

/**
 * Turning a basket and a form into the answers an order is placed with.
 *
 * Kept out of the checkout screen because none of it is about a screen: which
 * ticket is the second General, whether a question counts as answered, and
 * what the server is sent are three rules with one right answer each, and they
 * are worth testing without a browser.
 */

/** One ticket being bought, and where its answers live while they are typed. */
export interface Slot {
  /** Unique to this ticket, and stable while the basket does not change. */
  readonly key: string;
  readonly ticketTypeId: string;
  /** The tier's name, for the heading above the questions. */
  readonly name: string;
}

/**
 * One slot per ticket, in the order the server will mint them.
 *
 * The order matters twice over. The server numbers its refusals by position —
 * "answer for ticket 2" has to point at something a reader can find — and the
 * position within a ticket type is what later ties an answer to the ticket
 * that person actually holds.
 */
export function slotsFor(lines: readonly QuoteLine[]): Slot[] {
  return lines
    // Tickets only. An add-on admits nobody, so there is nobody on it to ask
    // a question of — two bottles are not two more people.
    .filter((line) => line.kind !== 'add_on')
    .flatMap((line) =>
      Array.from({ length: line.quantity }, (_, index) => ({
        key: `${line.ticket_type_id}:${index}`,
        ticketTypeId: line.ticket_type_id!,
        name: line.name,
      })),
    );
}

/**
 * Whether something was actually said.
 *
 * A boolean is an answer either way: "no, I do not need step-free access" is
 * the thing an organizer plans around, and treating false as blank would
 * refuse the order.
 */
export function answered(value: AnswerValue | undefined | null): boolean {
  if (value === undefined || value === null) return false;
  if (typeof value === 'string') return value.trim() !== '';
  if (Array.isArray(value)) return value.length > 0;

  return true;
}

/**
 * What is still missing, said the way the server says it.
 *
 * The server decides — it reads the questions off its own rows and refuses an
 * order without them. This is the same rule applied early, so the answer
 * arrives beside the field rather than at the payment step.
 */
export function missing(
  questions: readonly Question[],
  orderAnswers: Record<string, AnswerValue>,
  slots: readonly Slot[],
  attendeeAnswers: Record<string, Record<string, AnswerValue>>,
): boolean {
  const forOrder = questions.filter((question) => !question.per_attendee);
  const forEach = questions.filter((question) => question.per_attendee);

  if (forOrder.some((question) => question.required && !answered(orderAnswers[question.id]))) {
    return true;
  }

  return slots.some((slot) =>
    forEach.some(
      (question) => question.required && !answered(attendeeAnswers[slot.key]?.[question.id]),
    ),
  );
}

/** One entry per ticket, in the order they were filled in. */
export function attendeesFor(
  slots: readonly Slot[],
  attendeeAnswers: Record<string, Record<string, AnswerValue>>,
): Attendee[] {
  return slots.map((slot) => ({
    ticket_type_id: slot.ticketTypeId,
    answers: attendeeAnswers[slot.key] ?? {},
  }));
}

/**
 * Record an answer, or take one back.
 *
 * An emptied field is an unanswered question rather than an empty answer: a
 * blank stored against a label is something an organizer reads as though
 * somebody had written it.
 */
export function withAnswer(
  answers: Record<string, AnswerValue>,
  questionId: string,
  value: AnswerValue | null,
): Record<string, AnswerValue> {
  const next = { ...answers };

  if (value === null || !answered(value)) {
    delete next[questionId];
  } else {
    next[questionId] = value;
  }

  return next;
}
