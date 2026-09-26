/*
 * How many of a party a scan lets in.
 *
 * Both doors have a "How many" box beside the code, for a table arriving in
 * two groups. Here rather than in either app because what it may hold is the
 * server's rule, and a door that takes a number on one app and refuses it on
 * the other is two doors.
 */

/** The most people one scan can let in: the API's own limit on `party`, scanning and syncing alike. */
export const MOST_AT_ONCE = 50;

/** What was typed into "How many": a number to send, or what to tell the door instead. */
export type PartySize = { ok: true; party: number | null } | { ok: false; message: string };

/**
 * What was typed into "How many", as a scan sends it.
 *
 * Blank is null: everyone still outstanding on the ticket, which is what the
 * server reads a scan with no party as, and what an ordinary one-person ticket
 * wants. Anything else has to be a whole number of people from 1 to 50.
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
    message: `How many has to be a whole number from 1 to ${MOST_AT_ONCE}. Leave it blank to let in everyone on the ticket.`,
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
