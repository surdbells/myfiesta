import { eventDate, longEventTime, shortEventTime } from './event-time';

/**
 * Event times, in the event's own zone.
 *
 * Written after the console showed a 5pm Toronto event as 10pm. Angular's
 * DatePipe takes an offset like '+0400' for its timezone argument — an IANA
 * name is not supported and does not error, so it fell back to the machine's
 * own offset. For a ticketing platform that is people arriving at the wrong
 * time, and nothing in the code looked wrong.
 */
describe('event time', () => {
  // 21:00 UTC on 19 October 2026. Toronto is UTC-4 that day, so 5pm.
  const iso = '2026-10-19T21:00:00Z';

  it('renders in the event zone, not the reader machine', () => {
    const rendered = shortEventTime(iso, 'America/Toronto');

    expect(rendered).toContain('5:00');
    expect(rendered).not.toContain('9:00');
  });

  it('gives the same instant a different clock time in a different zone', () => {
    // The same moment is 10pm in Lagos and 5pm in Toronto. Both are correct,
    // and each is what to tell somebody going to that event.
    expect(shortEventTime(iso, 'Africa/Lagos')).toContain('10:00');
    expect(shortEventTime(iso, 'America/Toronto')).toContain('5:00');
  });

  it('uses the rule in force on the event date, not today', () => {
    // 19 January 2027 is EST, not EDT: an hour further from UTC. Formatting
    // with a fixed offset would be wrong for half the year.
    const winter = shortEventTime('2027-01-19T21:00:00Z', 'America/Toronto');

    expect(winter).toContain('4:00');
  });

  it('formats a long form for a single event page', () => {
    const rendered = longEventTime(iso, 'America/Toronto');

    expect(rendered).toContain('2026');
    expect(rendered).toContain('5:00');
  });

  it('drops the time for a past event, where only the date matters', () => {
    const rendered = eventDate(iso, 'America/Toronto');

    expect(rendered).toContain('2026');
    expect(rendered).not.toContain(':');
  });

  it('returns nothing for an unparseable date rather than "Invalid Date"', () => {
    expect(shortEventTime('not-a-date', 'America/Toronto')).toBe('');
  });

  it('still renders something when the zone is unrecognised', () => {
    // Wrong by hours beats blank: the row stays readable and the zone is
    // shown next to it.
    expect(shortEventTime(iso, 'Mars/Olympus_Mons')).not.toBe('');
  });
});
