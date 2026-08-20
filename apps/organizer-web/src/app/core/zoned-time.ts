/**
 * Turning "21:00 on 19 October, in Toronto" into an instant.
 *
 * A datetime-local input gives a wall-clock string with no zone attached:
 * "2026-10-19T21:00". An organizer means that time at their venue, so the
 * conversion needs the event's zone and the offset in force on that date —
 * which is not necessarily the offset in force today, and never the offset the
 * browser happens to be in.
 *
 * Getting this wrong is not subtle in effect and is completely invisible in
 * code: the event is simply listed at a different time than the one typed, and
 * the first person to notice is somebody standing outside a venue.
 *
 * Doing it without a date library means asking Intl what a given instant looks
 * like in the target zone, then correcting by the difference.
 */

/** Offset of a zone, in minutes, at a particular instant. */
function offsetMinutesAt(instant: Date, timeZone: string): number {
  // Reading the parts back in the target zone tells us what clock it shows.
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    hour12: false,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).formatToParts(instant);

  const get = (type: string) => Number(parts.find((p) => p.type === type)?.value);

  // Reassembled as if that wall clock were UTC, the gap from the real instant
  // is the offset.
  const asUtc = Date.UTC(
    get('year'),
    get('month') - 1,
    get('day'),
    // Midnight comes back as hour 24 in some runtimes.
    get('hour') % 24,
    get('minute'),
    get('second'),
  );

  return (asUtc - instant.getTime()) / 60000;
}

/**
 * A wall-clock string in a zone, to an ISO instant.
 *
 * @param local  "2026-10-19T21:00", as a datetime-local input produces
 * @param timeZone  IANA name, e.g. "America/Toronto"
 */
export function zonedWallClockToIso(local: string, timeZone: string): string | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(local);

  if (!match) {
    return null;
  }

  const [, year, month, day, hour, minute] = match.map(Number);

  // First guess: treat the wall clock as UTC.
  const guess = new Date(Date.UTC(year, month - 1, day, hour, minute));

  // Correct by the offset that applies around then. Applied twice because the
  // offset itself can change across the correction — a 1am clock change is the
  // case where one pass lands an hour out.
  const first = new Date(guess.getTime() - offsetMinutesAt(guess, timeZone) * 60000);
  const second = new Date(guess.getTime() - offsetMinutesAt(first, timeZone) * 60000);

  return Number.isNaN(second.getTime()) ? null : second.toISOString();
}

/**
 * The reverse: an instant back to the wall clock a datetime-local input wants.
 *
 * Needed to edit an event. The stored value is a UTC instant and the organizer
 * has to see the time they originally typed — 9pm at their venue, not the same
 * moment expressed in the browser's zone or in UTC. Loading an edit form that
 * silently shifts the time by five hours, and then saving it, moves the event.
 *
 * @returns "2026-10-19T21:00", or null if the instant cannot be read
 */
export function isoToZonedWallClock(iso: string, timeZone: string): string | null {
  const instant = new Date(iso);

  if (Number.isNaN(instant.getTime())) {
    return null;
  }

  try {
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone,
      hour12: false,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    }).formatToParts(instant);

    const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '';

    // Midnight comes back as hour 24 in some runtimes, which no input accepts.
    const hour = get('hour') === '24' ? '00' : get('hour');

    return `${get('year')}-${get('month')}-${get('day')}T${hour}:${get('minute')}`;
  } catch {
    return null;
  }
}

/** How a zone reads right now, for a picker: "Toronto — EDT". */
export function describeZone(timeZone: string, on: Date = new Date()): string {
  const city = timeZone.split('/').pop()?.replace(/_/g, ' ') ?? timeZone;

  try {
    const abbreviation = new Intl.DateTimeFormat('en-US', {
      timeZone,
      timeZoneName: 'short',
    })
      .formatToParts(on)
      .find((p) => p.type === 'timeZoneName')?.value;

    return abbreviation ? `${city} — ${abbreviation}` : city;
  } catch {
    return city;
  }
}

/** The browser's own zone, as a sensible default rather than an assumption. */
export function localZone(): string {
  return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
}

/**
 * Zones the platform actually sells in, plus wherever the organizer is.
 *
 * A full IANA list is 400 entries and helps nobody; these are the launch
 * markets, and localZone() is prepended so somebody elsewhere is not stuck.
 */
export const COMMON_ZONES = [
  'America/Toronto',
  'America/Vancouver',
  'America/Edmonton',
  'America/Winnipeg',
  'America/Halifax',
  'America/St_Johns',
  // Lagos only. Africa/Abuja is a deprecated alias for it, and browsers
  // return no abbreviation for an alias — so it rendered without the offset
  // every other entry shows, which reads as a broken row rather than the same
  // zone twice.
  'Africa/Lagos',
  'Europe/London',
  'America/New_York',
  'UTC',
] as const;
