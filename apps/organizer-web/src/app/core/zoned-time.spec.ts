import { describeZone, isoToZonedWallClock, zonedWallClockToIso } from './zoned-time';

/**
 * The conversion an organizer never sees and entirely depends on.
 *
 * They type a time meaning it at their venue. If this is wrong the event is
 * simply listed at a different hour than the one they entered, nothing in the
 * code looks broken, and the first person to notice is standing outside a door.
 */
describe('zonedWallClockToIso', () => {
  it('reads a summer Toronto evening as EDT, not UTC', () => {
    // 19 October 2026 is still EDT: UTC-4, so 9pm local is 01:00 UTC next day.
    expect(zonedWallClockToIso('2026-10-19T21:00', 'America/Toronto')).toBe(
      '2026-10-20T01:00:00.000Z',
    );
  });

  it('uses the offset in force on the event date, not today', () => {
    // 19 January is EST: UTC-5. A single fixed offset would be an hour out for
    // half the year.
    expect(zonedWallClockToIso('2027-01-19T21:00', 'America/Toronto')).toBe(
      '2027-01-20T02:00:00.000Z',
    );
  });

  it('handles a zone with no daylight saving', () => {
    // Lagos is UTC+1 all year, so 9pm local is 20:00 UTC.
    expect(zonedWallClockToIso('2026-10-19T21:00', 'Africa/Lagos')).toBe(
      '2026-10-19T20:00:00.000Z',
    );
  });

  it('treats the same wall clock in two zones as two different instants', () => {
    const toronto = zonedWallClockToIso('2026-10-19T21:00', 'America/Toronto');
    const lagos = zonedWallClockToIso('2026-10-19T21:00', 'Africa/Lagos');

    // Both organizers typed 9pm and both meant it. They are five hours apart.
    expect(toronto).not.toBe(lagos);
    expect(new Date(toronto!).getTime() - new Date(lagos!).getTime()).toBe(5 * 3600 * 1000);
  });

  it('survives an event on the night the clocks change', () => {
    // Toronto goes back at 2am on 1 November 2026. An event at 1am that night
    // is the case a single-pass correction lands an hour out on.
    const iso = zonedWallClockToIso('2026-11-01T01:00', 'America/Toronto');

    expect(iso).not.toBeNull();
    expect(new Date(iso!).toISOString()).toMatch(/^2026-11-01T0[45]:00/);
  });

  it('passes UTC through unchanged', () => {
    expect(zonedWallClockToIso('2026-10-19T21:00', 'UTC')).toBe('2026-10-19T21:00:00.000Z');
  });

  it('accepts the seconds a browser sometimes appends', () => {
    expect(zonedWallClockToIso('2026-10-19T21:00:00', 'UTC')).toBe('2026-10-19T21:00:00.000Z');
  });

  it('returns null for something that is not a date', () => {
    // Null so the caller can refuse to submit, rather than sending an instant
    // nobody chose.
    expect(zonedWallClockToIso('', 'UTC')).toBeNull();
    expect(zonedWallClockToIso('tomorrow night', 'UTC')).toBeNull();
  });
});

describe('isoToZonedWallClock', () => {
  it('shows the organizer the time they originally typed', () => {
    // 01:00 UTC on the 20th is 9pm on the 19th in Toronto, which is what they
    // entered. An edit form showing 1am would move the event on save.
    expect(isoToZonedWallClock('2026-10-20T01:00:00.000Z', 'America/Toronto')).toBe(
      '2026-10-19T21:00',
    );
  });

  it('uses the offset in force on the event date', () => {
    expect(isoToZonedWallClock('2027-01-20T02:00:00.000Z', 'America/Toronto')).toBe(
      '2027-01-19T21:00',
    );
  });

  it('round-trips without moving the event', () => {
    // The property that matters: loading an edit form and saving it unchanged
    // must leave the instant exactly where it was.
    for (const zone of ['America/Toronto', 'Africa/Lagos', 'UTC']) {
      for (const iso of ['2026-10-20T01:00:00.000Z', '2027-01-20T02:00:00.000Z']) {
        const wall = isoToZonedWallClock(iso, zone)!;
        expect(zonedWallClockToIso(wall, zone)).toBe(iso);
      }
    }
  });

  it('handles midnight without producing hour 24', () => {
    const wall = isoToZonedWallClock('2026-10-19T23:00:00.000Z', 'Africa/Lagos');

    // Some runtimes report midnight as 24:00, which no input will accept.
    expect(wall).toBe('2026-10-20T00:00');
  });

  it('returns null rather than a wrong date for unusable input', () => {
    expect(isoToZonedWallClock('not a date', 'UTC')).toBeNull();
  });
});

describe('describeZone', () => {
  it('names the city and how it currently reads', () => {
    const described = describeZone('America/Toronto', new Date('2026-07-01T12:00:00Z'));

    expect(described).toContain('Toronto');
    expect(described).toContain('EDT');
  });

  it('tidies underscores out of a zone name', () => {
    expect(describeZone('America/St_Johns')).toContain('St Johns');
  });

  it('does not throw on a zone it does not know', () => {
    expect(describeZone('Mars/Olympus_Mons')).toContain('Olympus Mons');
  });
});
