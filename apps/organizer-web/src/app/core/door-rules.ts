import { DoorListTicket, ScanResult } from './api.types';

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
 * cancelled, nothing left is a duplicate, more people than places is refused
 * rather than quietly rounded down.
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

  const remaining = ticket.admits - ticket.admitted_count;

  if (remaining <= 0) {
    return refusal(
      'duplicate',
      ticket.admits === 1 ? 'Already scanned.' : `All ${ticket.admits} already came in.`,
      about,
    );
  }

  // No party size means everybody left on the ticket, which is what a door
  // holding a group's single ticket means by scanning it once.
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
 */
export function admittedAfter(ticket: DoorListTicket, party: number | null): number {
  return Math.min(ticket.admits, ticket.admitted_count + (party ?? ticket.admits - ticket.admitted_count));
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
