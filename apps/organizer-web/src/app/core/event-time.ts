/**
 * Event times, rendered in the event's own zone.
 *
 * Not Angular's DatePipe. Its `timezone` argument takes an offset such as
 * '+0400', or 'UTC' — an IANA name like 'America/Toronto' is not supported and
 * does not error. It silently falls back, and the console showed a 5pm event
 * as 10pm: the machine's own offset applied to a UTC instant. For a ticketing
 * platform that is people arriving at the wrong time.
 *
 * Intl.DateTimeFormat understands IANA zones properly, including the DST rule
 * in force on the event's date rather than today's. The public site already
 * formats this way; this exists so the two cannot drift.
 *
 * The zone is always the event's, never the reader's. A Lagos event at 10pm
 * says 10pm to somebody in Toronto, because that is when to turn up.
 */

function format(iso: string, timeZone: string, options: Intl.DateTimeFormatOptions): string {
  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) {
    return '';
  }

  try {
    return new Intl.DateTimeFormat('en-CA', { ...options, timeZone }).format(date);
  } catch {
    // An unrecognised zone would otherwise throw and blank the whole row.
    // Falling back to UTC is wrong by hours; saying nothing is wrong by
    // everything, so UTC it is — and the zone is shown alongside.
    return new Intl.DateTimeFormat('en-CA', { ...options, timeZone: 'UTC' }).format(date);
  }
}

/** "Mon 19 Oct, 5:00 pm" — for a list, where space is short. */
export function shortEventTime(iso: string, timeZone: string): string {
  return format(iso, timeZone, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
  });
}

/** "Monday 19 October 2026, 5:00 pm" — for a page about one event. */
export function longEventTime(iso: string, timeZone: string): string {
  return format(iso, timeZone, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

/** "19 Oct 2026" — for past events, where the time no longer matters. */
export function eventDate(iso: string, timeZone: string): string {
  return format(iso, timeZone, { day: 'numeric', month: 'short', year: 'numeric' });
}
