/**
 * Times as an organizer thinks about them: relative to now.
 *
 * "In 6 days", "3 hours ago", "Tomorrow" — the question on a phone is always
 * how long until, or how long since, and a full date makes the reader do the
 * subtraction. Past a fortnight the relative form stops helping and the date
 * comes back.
 */

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

export function ago(iso: string | null | undefined, now = Date.now()): string {
  if (!iso) return '';

  const then = new Date(iso).getTime();

  if (Number.isNaN(then)) return '';

  const gap = now - then;

  if (gap < MINUTE) return 'Just now';
  if (gap < HOUR) return `${Math.floor(gap / MINUTE)} min ago`;
  if (gap < DAY) return `${Math.floor(gap / HOUR)}h ago`;
  if (gap < 2 * DAY) return 'Yesterday';
  if (gap < 14 * DAY) return `${Math.floor(gap / DAY)} days ago`;

  return new Intl.DateTimeFormat('en-CA', { day: 'numeric', month: 'short' }).format(new Date(then));
}

/** How long until something starts: "Tonight", "Tomorrow", "In 6 days", "Started". */
export function until(iso: string, now = Date.now()): string {
  const then = new Date(iso).getTime();

  if (Number.isNaN(then)) return '';

  const gap = then - now;

  if (gap < 0) return gap > -8 * HOUR ? 'On now' : 'Over';
  if (gap < 12 * HOUR) return gap < HOUR ? `In ${Math.max(1, Math.round(gap / MINUTE))} min` : `In ${Math.round(gap / HOUR)}h`;
  if (gap < DAY + 12 * HOUR) return 'Tomorrow';
  if (gap < 14 * DAY) return `In ${Math.round(gap / DAY)} days`;
  if (gap < 60 * DAY) return `In ${Math.round(gap / (7 * DAY))} weeks`;

  return `In ${Math.round(gap / (30 * DAY))} months`;
}

/** A plain count with the right noun: "1 ticket", "12 tickets". */
export function count(n: number, one: string, many = `${one}s`): string {
  return `${n.toLocaleString()} ${n === 1 ? one : many}`;
}

/** A date alone, in the event's zone: "Sat 3 Oct". */
export function dayOf(iso: string, timeZone: string): string {
  try {
    return new Intl.DateTimeFormat('en-CA', { weekday: 'short', day: 'numeric', month: 'short', timeZone }).format(new Date(iso));
  } catch {
    return '';
  }
}
