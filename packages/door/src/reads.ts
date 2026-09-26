/*
 * What a door does with what its camera reads.
 *
 * A camera reads whatever QR code is in front of it, as often as it can see
 * it. A door acts on neither of those as they come: only on a code shaped like
 * a ticket's, and on each ticket once while it is held up. Here rather than in
 * either app's door because both read the same tickets through the same kind
 * of camera, and a door that scans twice on one app and once on the other is
 * two doors.
 */

/** How long a ticket has to be out of sight before the camera acts on it again. */
export const SAME_TICKET_AGAIN_AFTER_MS = 4000;

/**
 * A code as the door sends it — trimmed and upper-cased, as the server reads
 * it — or null for anything no ticket could have.
 *
 * Ticket codes are letters, digits and dashes: thirteen on everything this
 * platform issues, and never more than 32, the width of the column every code
 * is kept in, imported ones included, and all the API will take. The camera
 * reads a poster behind the guest, a payment code, or one somebody made to jam
 * the door just as readily as a ticket. And a door with no signal queues every
 * scan it makes, and the server refuses a batch of them whole if one does not
 * validate — so a single code it can never take would hold every scan behind
 * it on the phone for the rest of the night. Nothing that is not shaped like a
 * ticket leaves the door at all.
 */
export function ticketCode(raw: string): string | null {
  const code = raw.trim().toUpperCase();

  return /^[A-Z0-9-]{4,32}$/.test(code) ? code : null;
}

/**
 * One ticket held up to the camera is one scan.
 *
 * The camera reads the same ticket several times a second for as long as it is
 * in frame, and a scan is not free: it admits somebody, and a table ticket
 * scanned again with no number typed admits everyone still outside. So a code
 * is acted on once, and again only once it has been out of sight for a few
 * seconds.
 *
 * Out of sight counts from the last time the camera saw it, not the first. A
 * guest who keeps their phone up through the ID check is still one scan. While
 * the door waits for the answer the ticket counts as in sight whatever the
 * camera sees, and the answer arriving starts the count again: a slow answer is
 * exactly when a guest lowers the phone and raises it again to ask whether it
 * worked. A ticket shown again after it really has been away is scanned again,
 * which is how a door hears that it was already used.
 *
 * A different ticket is acted on straight away, once the door is free.
 */
export class RepeatReads {
  private last = { code: '', at: 0 };
  private waiting = false;

  constructor(private readonly quietMs = SAME_TICKET_AGAIN_AFTER_MS) {}

  /**
   * Whether to act on a code the camera has just seen.
   *
   * @param busy whether the door is still busy with a scan of any kind, a typed
   *             one included — nothing new is started until it is free.
   */
  take(code: string, busy: boolean, now = Date.now()): boolean {
    if (code === this.last.code && (this.waiting || now - this.last.at < this.quietMs)) {
      this.last.at = now;

      return false;
    }

    if (busy) return false;

    this.last = { code, at: now };
    this.waiting = true;

    return true;
  }

  /** The scan of `code` has its answer, whatever it was: its quiet time starts again from now. */
  answered(code: string, now = Date.now()): void {
    if (code !== this.last.code) return;

    this.waiting = false;
    this.last.at = now;
  }
}
