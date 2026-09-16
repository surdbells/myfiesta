/**
 * Event times, always in the event's own zone.
 *
 * A Lagos event at 10pm says 10pm to somebody reading in Toronto, because that
 * is when to turn up. Showing it in the reader's zone would be technically
 * accurate and practically useless.
 *
 * Intl carries the IANA database on every platform this app runs on, so unlike
 * the Flutter app there is no timezone package to load at startup.
 */
function format(iso: string, timeZone: string, options: Intl.DateTimeFormatOptions): string {
  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return '';

  try {
    return new Intl.DateTimeFormat('en-CA', { ...options, timeZone }).format(date);
  } catch {
    // An unrecognised zone would throw and blank the line. UTC is wrong by
    // hours; saying nothing is wrong by everything.
    return new Intl.DateTimeFormat('en-CA', { ...options, timeZone: 'UTC' }).format(date);
  }
}

/** "Fri 28 Aug, 10:00 pm" — for a list. */
export function shortEventTime(iso: string, timeZone: string): string {
  return format(iso, timeZone, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
  });
}

/** "Friday 28 August 2026, 10:00 pm" — for a screen about one event. */
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
