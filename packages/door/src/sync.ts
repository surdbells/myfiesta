import { OfflineScan, SyncResult } from './types';

/*
 * What a door does with the server's answer to the scans it made with no
 * signal: a refused batch, and the scans it got wrong.
 *
 * Here rather than in either app because both queue scans in the same store
 * and send them to the same endpoint, which answers the same way whichever app
 * sent them. A phone that recovers from that and a console that sits on it all
 * night are two doors.
 */

/** Why the server had turned a ticket away, by its result, as the door says it. */
const REFUSED_BECAUSE: Record<string, string> = {
  duplicate: 'it had already been used',
  void: 'it was cancelled or handed back',
  not_found: 'no ticket has that code',
  wrong_event: 'it is for a different event',
  over_capacity: 'it did not have that many places left',
};

/**
 * Where the door, deciding with no signal, and the server disagreed about a
 * batch it has just taken: the ones the server named, and the ones it could
 * not.
 *
 * A scan is sent first, and queued only when no answer comes back. The answer
 * can be lost after the server has acted: a timeout, or the wifi dropping on
 * the way back. The queued copy goes under the scan's own id, so the server
 * knows it for the scan it has already recorded and a table is not let in
 * twice. It used to answer with what it decided the first time and compare
 * nothing, so a guest let in from this phone's list on a ticket the server had
 * just refused — used at another door since the list was fetched, or refunded
 * — went through the sync with nobody told.
 *
 * The server now compares these itself (CheckInService::reconcile) and names
 * the disagreement in its `conflicts` like any other, with the door's decision
 * in `offline_result`. This is the fallback for an answer with no
 * `offline_result` — a server from before that change — and adds nothing
 * when the server has already spoken.
 */
export function conflictsIn(batch: readonly OfflineScan[], result: SyncResult): SyncResult['conflicts'] {
  const queued = new Map(batch.map((scan) => [scan.client_id, scan]));

  const unnamed = result.data.flatMap((answer) => {
    const scan = queued.get(answer.client_id);

    if (!scan || answer.conflict || answer.offline_result !== null) return [];

    const doorAdmitted = scan.offline_result === 'accepted';

    if (doorAdmitted === answer.accepted) return [];

    const who = answer.ticket?.holder_name || scan.code;
    const why = REFUSED_BECAUSE[answer.result];

    return [
      {
        ...answer,
        offline_result: scan.offline_result,
        conflict: doorAdmitted ? ('admitted_invalid' as const) : ('refused_valid' as const),
        message: doorAdmitted
          ? `${who} was let in with no signal, but the scan had already reached the server, which turned the ticket away${why ? `: ${why}` : ''}.`
          : `${who} was turned away with no signal, but the scan had already reached the server, which let them in, so the ticket now reads as used. They can come in.`,
      },
    ];
  });

  return [...result.conflicts, ...unnamed];
}

/**
 * The scans in a refused batch that the server can never take.
 *
 * The server refuses a batch whole if one scan in it does not validate. So a
 * single scan it will never take — a code longer than any ticket's, typed or
 * read before the door checked codes, or a party of 80 — would hold every scan
 * behind it on the phone all night, and the ticket list would never refresh
 * while they waited.
 *
 * Only the code or the party picks a scan out: those are what a person typed
 * or a camera read. Anything else the server finds wrong is the app's own
 * mistake, and every scan stays queued until the app is fixed rather than
 * being thrown away with it.
 *
 * @param errors the refusal's field errors, keyed as the API keys them, by
 *               each scan's place in the batch: `scans.3.code`.
 */
export function unsendableScans(
  batch: readonly OfflineScan[],
  errors: Record<string, unknown> | null | undefined,
): OfflineScan[] {
  const picked = new Set<OfflineScan>();

  for (const field of Object.keys(errors ?? {})) {
    const position = /^scans\.(\d+)\.(?:code|party)$/.exec(field)?.[1];
    const scan = position === undefined ? undefined : batch[Number(position)];

    if (scan) picked.add(scan);
  }

  return batch.filter((scan) => picked.has(scan));
}

/**
 * What the door is told about somebody it let in that the server has no
 * record of.
 *
 * A scan the server cannot take is never sent again, and with a refusal that
 * loses little. With an admission it loses the fact that somebody went in: the
 * ticket still reads as unused, and could be shown again at another door. So
 * the door hears which ticket, and what puts it right, while the guest is
 * still in the room. The code is the only thing the queue knows the ticket by.
 */
export function unrecordedAdmission(scan: OfflineScan): string {
  return `${scan.code} was let in with no signal, but the server could not record it, so the ticket still reads as unused. Check it in again with how many went in.`;
}
