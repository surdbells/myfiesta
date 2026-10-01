import { DoorListTicket, ScanResult } from './types';

/**
 * The answer to a scan that did not say how many, on a ticket with more than
 * one person still to come: nobody goes in, and the door asks how many are
 * here — all of them, or some. A question rather than a verdict, so it is
 * never counted, never buzzed as a refusal and never queued: see `asksHowMany`.
 */
export const CHOOSE_PARTY = 'choose_party';

/**
 * What a door decides when it cannot ask the server.
 *
 * Pulled out of the storage it used to live inside, for two reasons. It is the
 * riskiest code in the console — it admits or refuses people, and a door that
 * answers differently offline from online is a door whose staff stop trusting
 * either answer — and while it sat behind IndexedDB the only way to exercise
 * it was to run a browser.
 *
 * It follows CheckInService on the API line for line: refunded or void is
 * cancelled, handed back for resale is refused, nothing left is a duplicate,
 * no number with more than one place left is a question, more people than
 * places is refused rather than quietly rounded down.
 */
export function decideOffline(ticket: DoorListTicket | null, party: number | null): ScanResult {
  if (!ticket) {
    return refusal(
      'not_found',
      'Not on this phone’s list. If they bought in the last few minutes, it will scan once signal is back.',
    );
  }

  const about = {
    holder_name: ticket.holder_name,
    type: ticket.type,
    admits: ticket.admits,
    admitted_count: ticket.admitted_count,
  };

  if (ticket.status === 'refunded' || ticket.status === 'void') {
    return refusal('void', 'This ticket was cancelled.', about);
  }

  // Given back and waiting for somebody else to take the place. The server
  // has always turned it away; this door let it in, so a ticket handed back
  // for its money still opened the door wherever the signal was down. Named
  // rather than called cancelled, as the server names it: the person holding
  // it may have given it back by mistake and can keep it from their email.
  if (ticket.status === 'listed') {
    return refusal('void', 'This ticket was handed back and is waiting to be resold.', about);
  }

  const remaining = ticket.admits - ticket.admitted_count;

  if (remaining <= 0) {
    return refusal(
      'duplicate',
      ticket.admits === 1 ? 'Already scanned.' : `All ${ticket.admits} already came in.`,
      about,
    );
  }

  // A table's ticket is held up by whoever of the table is at the front. No
  // number used to mean everybody left on it, so the first of four scanned
  // counted all four in and the other three walked in later, unscanned. Now
  // the door is asked, as the server asks: the list carries how many the
  // ticket admits and how many are in, which is all the question needs.
  if (party === null && remaining > 1) {
    return refusal(CHOOSE_PARTY, howManyMessage(ticket.admits, remaining), about, { remaining });
  }

  // No number and one place left: every ordinary ticket, and the last of a table.
  const wanted = party ?? remaining;

  if (wanted > remaining) {
    return refusal(
      'over_capacity',
      remaining === 1 ? 'Only 1 place left on this ticket.' : `Only ${remaining} places left on this ticket.`,
      about,
      { remaining },
    );
  }

  const left = remaining - wanted;

  return {
    ...refusal(
      'accepted',
      ticket.admits === 1
        ? 'Admitted.'
        : left === 0
          ? `Admitted ${wanted}. That is everyone.`
          : `Admitted ${wanted}. ${left} still to come.`,
      { ...about, admitted_count: ticket.admits - left },
    ),
    accepted: true,
    admitted: wanted,
    remaining: left,
  };
}

/**
 * How many the ticket has admitted once this scan is counted.
 *
 * Kept beside the decision because the two have to agree: a phone that admits
 * three and then records two has just let somebody in twice.
 *
 * No number is everyone the ticket has left. That is what a queued scan from a
 * door that never asked meant, and what counting it back onto a fresh list has
 * to mean; this door only ever lets people in on no number when one place is
 * left.
 */
export function admittedAfter(ticket: DoorListTicket, party: number | null): number {
  return Math.min(ticket.admits, ticket.admitted_count + (party ?? ticket.admits - ticket.admitted_count));
}

/**
 * Whether a scan's answer is the question of how many are here, rather than
 * a verdict. The door puts the question and scans again with the number; this
 * answer is not an admission, not a refusal, and not a scan to send later.
 */
export function asksHowMany(result: Pick<ScanResult, 'result'>): boolean {
  return result.result === CHOOSE_PARTY;
}

/**
 * The party an offline scan is queued with.
 *
 * What was asked for, when a number was — typed, or chosen when the door asked.
 * Otherwise, once people went in on a ticket for more than one, the number that
 * did: the last place on a table, let in without a question. The server reads a
 * queued scan with no number as everyone the ticket has left, which is what
 * doors from before the question meant by it. Where this phone had counted
 * somebody the server never heard of — an admission it could not send — that
 * would count the one guest let in now as two. A single ticket keeps no number,
 * as it always has.
 */
export function queuedParty(party: number | null, outcome: ScanResult): number | null {
  if (party !== null) return party;

  return outcome.accepted && (outcome.ticket?.admits ?? 1) > 1 ? outcome.admitted : null;
}

/**
 * The question, in the server's words, for a door that shows them instead of
 * asking: put how many in "How many" and scan again.
 */
function howManyMessage(admits: number, remaining: number): string {
  const inside = admits - remaining;

  return `This ticket admits ${admits}, ${inside === 0 ? 'nobody in yet' : `${inside} already in`}. Put how many are going in now in How many, and scan it again.`;
}

/**
 * A code hashed the way the server hashed the list.
 *
 * Trimmed, upper-cased, PBKDF2-SHA256 with the event's salt, hex. This has to
 * match PHP's `hash_pbkdf2` byte for byte: if the two ever disagreed, every
 * offline scan would read as a ticket nobody recognises, at a door, with a
 * queue behind it. `door-hash.json` is the fixture both sides are checked
 * against.
 *
 * WebCrypto only exists in a secure context — https, localhost, or a Capacitor
 * WebView. A console opened over plain http on a venue's wifi has none, which
 * is why the caller checks before promising anybody an offline door.
 */
export async function hashCode(code: string, salt: string, iterations: number): Promise<string> {
  const encoder = new TextEncoder();

  const key = await crypto.subtle.importKey(
    'raw',
    encoder.encode(code.trim().toUpperCase()),
    'PBKDF2',
    false,
    ['deriveBits'],
  );

  const bits = await crypto.subtle.deriveBits(
    { name: 'PBKDF2', hash: 'SHA-256', salt: encoder.encode(salt), iterations },
    key,
    256,
  );

  return Array.from(new Uint8Array(bits), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

function refusal(
  result: string,
  message: string,
  ticket: ScanResult['ticket'] = null,
  extra: Partial<ScanResult> = {},
): ScanResult {
  return {
    result,
    accepted: false,
    admitted: 0,
    remaining: 0,
    message,
    ticket,
    offline: true,
    ...extra,
  };
}
