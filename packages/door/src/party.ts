import { ScanResult } from './types';

/*
 * How many of a party a scan lets in.
 *
 * A ticket for more than one is never let in whole on a scan that did not say
 * how many: the door asks (`choose_party`), with a button for everyone still
 * to come and one for each smaller number, and scans again with the answer.
 * "How many" beside the code is the shortcut — a number typed before the scan
 * is sent with it and nothing is asked. Here rather than in either app because
 * what may be sent is the server's rule, and a door that offers a number on
 * one app and refuses it on the other is two doors.
 */

/** The most people one scan can let in: the API's own limit on `party`, scanning and syncing alike. */
export const MOST_AT_ONCE = 50;

/**
 * Up to how many still to come each number is a button of its own. Beyond it,
 * a row of buttons is too many to read at a door in the dark, and the smaller
 * numbers are a stepper instead.
 */
export const EACH_NUMBER_UP_TO = 6;

/** What a door offers when it asks how many of a party are here. */
export interface PartyChoices {
  /** "All N here": everyone still to come, in one tap. Null past what one scan can let in. */
  all: number | null;
  /** Fewer than everyone, a button each, smallest first. Empty when `upTo` is set. */
  each: number[];
  /** Beyond a handful, fewer than everyone as a stepper from 1 to this. Null when `each` has them. */
  upTo: number | null;
}

/**
 * The choices for a ticket with `outstanding` people still to come.
 *
 * Every number from 1 to everyone is offered, and nothing past what the server
 * takes in one scan: a table of 60 is let in 50 at most at a time, as it would
 * be typed.
 */
export function partyChoices(outstanding: number): PartyChoices {
  const all = outstanding >= 1 && outstanding <= MOST_AT_ONCE ? outstanding : null;
  const fewest = Math.min(outstanding - 1, MOST_AT_ONCE);

  if (fewest < 1) return { all, each: [], upTo: null };

  if (outstanding <= EACH_NUMBER_UP_TO) {
    return { all, each: Array.from({ length: fewest }, (_, i) => i + 1), upTo: null };
  }

  return { all, each: [], upTo: fewest };
}

/**
 * The ticket a door is asking about, said beside the question: whose it is,
 * what it is, and how many of it are already inside — "Chidi Nwosu · Table of
 * 4 · 1 of 4 already in". The last part is what tells whoever is holding the
 * phone that this is the rest of a table, not a new one.
 */
export function askingAbout(result: ScanResult): string {
  const ticket = result.ticket;

  if (!ticket) return `${result.remaining} still to come`;

  const inside = ticket.admits - result.remaining;

  return [
    ticket.holder_name ?? 'No name on ticket',
    ticket.type,
    inside > 0 ? `${inside} of ${ticket.admits} already in` : `none of ${ticket.admits} in yet`,
  ]
    .filter(Boolean)
    .join(' · ');
}

/**
 * The verdict on a scan that let in part of a party: "2 of 4 in — 2 still to
 * come". Counted on the ticket rather than on the scan, so the second group
 * of a table reads "3 of 4 in", which is the number the door is keeping. Null
 * for anything else.
 */
export function partOfParty(result: ScanResult): string | null {
  if (!result.accepted || result.remaining <= 0) return null;

  const ticket = result.ticket;

  return ticket
    ? `${ticket.admitted_count} of ${ticket.admits} in — ${result.remaining} still to come`
    : `${result.admitted} in — ${result.remaining} still to come`;
}

/** What was typed into "How many": a number to send, or what to tell the door instead. */
export type PartySize = { ok: true; party: number | null } | { ok: false; message: string };

/**
 * What was typed into "How many", as a scan sends it.
 *
 * Blank is null: the door has not said. An ordinary one-person ticket, or the
 * last place on a table, goes in on that; a ticket with more still to come is
 * asked about. Anything else has to be a whole number of people from 1 to 50.
 *
 * The box says as much with min and max, and nothing enforced it: Angular
 * marks every form novalidate. So 0 went as blank and let the whole table in,
 * and 1.5 went as 1.5 — which the server refuses online, but a door with no
 * signal decided like any other number: somebody walked in, the phone wrote
 * down one and a half of them, and the server then refused the batch of queued
 * scans it sat in. A number no ticket can be scanned with is said, before it
 * goes anywhere, rather than guessed at.
 *
 * @param typed the box's value: a string, or the number Angular reads out of a
 *              number box — null when blank. NaN for a box the browser could
 *              not read at all (Firefox takes letters and then reports it
 *              empty), which has to be refused rather than read as blank.
 */
export function partySize(typed: string | number | null | undefined): PartySize {
  const text = typed === null || typed === undefined ? '' : String(typed).trim();

  if (text === '') return { ok: true, party: null };

  const party = /^\d{1,3}$/.test(text) ? Number(text) : NaN;

  if (party >= 1 && party <= MOST_AT_ONCE) return { ok: true, party };

  return {
    ok: false,
    message: `How many has to be a whole number from 1 to ${MOST_AT_ONCE}. Leave it blank to be asked when the ticket is for more than one.`,
  };
}

/**
 * Whether a key belongs in "How many".
 *
 * Digits, and every key that is not a character — deleting, moving, Tab, Enter
 * — and shortcuts such as paste. A number box also takes a decimal point, a
 * minus sign and an exponent, none of which a number of people has. Pasted text
 * still gets in, which is why `partySize` checks what arrives as well.
 */
export function partyKey(key: string, shortcut = false): boolean {
  return shortcut || key.length !== 1 || /^[0-9]$/.test(key);
}
