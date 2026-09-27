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
 * in frame, and a scan is not free: it admits somebody, or asks the door how
 * many of a table are here when it has just been told. So a code is acted on
 * once, and again only once it has been out of sight for a few seconds.
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

  /**
   * The door is scanning `code` again itself, not because the camera saw it:
   * it asked how many of a party are here and has the answer.
   *
   * Sent by the door straight away, never through `take` — the ticket was read
   * moments ago and is very likely still in view, so the camera's own read of
   * it would be held back as a repeat and the answer would go nowhere. Counted
   * from here as in sight until `answered`, as a camera scan is, so a guest
   * still holding the ticket up when the camera comes back is not asked the
   * same question about the rest of their table.
   */
  again(code: string, now = Date.now()): void {
    this.last = { code, at: now };
    this.waiting = true;
  }

  /** The scan of `code` has its answer, whatever it was: its quiet time starts again from now. */
  answered(code: string, now = Date.now()): void {
    if (code !== this.last.code) return;

    this.waiting = false;
    this.last.at = now;
  }
}

/** How long "That QR code is not a ticket" stays up after the camera last saw one. */
export const NOT_A_TICKET_SAID_FOR_MS = 3000;

/**
 * How recently a ticket has to have been in view for another code beside it to
 * be taken as something behind it, not what was held up.
 */
export const TICKET_IN_VIEW_WITHIN_MS = 2000;

/**
 * Saying so when the camera is shown a QR code that is not a ticket's.
 *
 * Not a scan — nothing is sent and nothing buzzes — but said over the preview
 * for a few seconds: a door holding up a guest's payment code or the event's
 * own poster otherwise stares at a camera that does nothing and learns nothing.
 *
 * Not while a ticket is in view as well, or was a moment ago. The camera reads
 * everything in the frame, and then the other code is only the poster on the
 * wall behind the guest; a door told "that is not a ticket" while it checks a
 * good one in stops believing the screen. And taken down the moment a ticket
 * is seen.
 *
 * Here rather than in either door so both say it on the same timing. No
 * framework in it, like the rest of this package: each door hands over what to
 * do when the note goes up or comes down, and keeps the note itself.
 */
export class NotATicketNote {
  private ticketSeenAt = -Infinity;
  private timer: ReturnType<typeof setTimeout> | undefined;

  constructor(private readonly show: (showing: boolean) => void) {}

  /** The camera saw a ticket's code. */
  sawTicket(now = Date.now()): void {
    this.ticketSeenAt = now;
    this.clear();
  }

  /** The camera saw a code that is not a ticket's. */
  sawSomethingElse(now = Date.now()): void {
    if (now - this.ticketSeenAt < TICKET_IN_VIEW_WITHIN_MS) return;

    clearTimeout(this.timer);
    this.show(true);
    this.timer = setTimeout(() => this.clear(), NOT_A_TICKET_SAID_FOR_MS);
  }

  /** Taken down: a ticket came into view, the camera stopped, or the door screen went. */
  clear(): void {
    clearTimeout(this.timer);
    this.timer = undefined;
    this.show(false);
  }
}
