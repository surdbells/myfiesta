import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { localZone } from '@myfiesta/shared/zoned-time';

const app = vi.hoisted(() => ({
  native: false,
  resumed: [] as (() => void)[],
  removed: 0,
}));

vi.mock('@capacitor/core', () => ({
  Capacitor: { isNativePlatform: () => app.native, getPlatform: () => (app.native ? 'ios' : 'web') },
  registerPlugin: () => ({}),
  WebPlugin: class {},
}));

vi.mock('@capacitor/app', () => ({
  App: {
    addListener: async (event: string, listener: () => void) => {
      if (event === 'resume') app.resumed.push(listener);

      return {
        remove: async () => {
          app.removed++;
        },
      };
    },
  },
}));

import { Clock, greeting, partOfDay } from './greeting';

/**
 * "Good evening", where the person is.
 *
 * The home screen used to greet from the phone's clock, so somebody whose
 * account says Vancouver, reading on a phone still set to Toronto after a
 * trip, was told "Evening" at four in the afternoon. The zone on the account
 * wins; the phone's own is the fallback when there is none, or when the one
 * on file is not a zone this phone knows.
 */
describe('partOfDay', () => {
  // 2026-07-15 (summer time in both zones): Toronto is UTC-4, Vancouver UTC-7.
  const at = (iso: string) => new Date(iso);

  it('changes at five in the morning, noon and five in the evening', () => {
    const zone = 'America/Toronto';

    expect(partOfDay(at('2026-07-15T08:59:00Z'), zone)).toBe('evening'); // 04:59
    expect(partOfDay(at('2026-07-15T09:00:00Z'), zone)).toBe('morning'); // 05:00
    expect(partOfDay(at('2026-07-15T15:59:00Z'), zone)).toBe('morning'); // 11:59
    expect(partOfDay(at('2026-07-15T16:00:00Z'), zone)).toBe('afternoon'); // 12:00
    expect(partOfDay(at('2026-07-15T20:59:00Z'), zone)).toBe('afternoon'); // 16:59
    expect(partOfDay(at('2026-07-15T21:00:00Z'), zone)).toBe('evening'); // 17:00
    expect(partOfDay(at('2026-07-16T03:59:00Z'), zone)).toBe('evening'); // 23:59
    expect(partOfDay(at('2026-07-16T04:00:00Z'), zone)).toBe('evening'); // 00:00
  });

  it('reads the hour in the zone it is given, not on the phone', () => {
    // 20:00 UTC: four in the afternoon in Toronto, one in Vancouver, nine at night in Lagos.
    const now = at('2026-07-15T20:00:00Z');

    expect(partOfDay(now, 'America/Toronto')).toBe('afternoon');
    expect(partOfDay(now, 'America/Vancouver')).toBe('afternoon');
    expect(partOfDay(now, 'Africa/Lagos')).toBe('evening');
    // 15:00 UTC: eight in the morning in Vancouver, already the afternoon in Halifax.
    expect(partOfDay(at('2026-07-15T15:00:00Z'), 'America/Vancouver')).toBe('morning');
    expect(partOfDay(at('2026-07-15T15:00:00Z'), 'America/Halifax')).toBe('afternoon');
  });

  it('follows summer time by the zone’s own rule', () => {
    // 09:30 UTC is 04:30 in Toronto in winter (UTC-5) and 05:30 in summer (UTC-4).
    expect(partOfDay(at('2026-01-15T09:30:00Z'), 'America/Toronto')).toBe('evening');
    expect(partOfDay(at('2026-07-15T09:30:00Z'), 'America/Toronto')).toBe('morning');
  });

  it('falls back to the phone’s zone for one it does not know', () => {
    const now = at('2026-07-15T20:00:00Z');

    expect(partOfDay(now, 'Mars/Olympus_Mons')).toBe(partOfDay(now, localZone()));
    expect(partOfDay(now, 'not a zone')).toBe(partOfDay(now, localZone()));
  });

  it('uses the phone’s zone when the account has none', () => {
    // The specs run with TZ=UTC, so the phone's zone is UTC here.
    expect(partOfDay(at('2026-07-15T09:00:00Z'), null)).toBe('morning');
    expect(partOfDay(at('2026-07-15T13:00:00Z'), undefined)).toBe('afternoon');
    expect(partOfDay(at('2026-07-15T02:00:00Z'), '')).toBe('evening');
    expect(partOfDay(at('2026-07-15T13:00:00Z'), null)).toBe(partOfDay(at('2026-07-15T13:00:00Z'), localZone()));
  });
});

describe('greeting', () => {
  it('says it the way a person would', () => {
    expect(greeting(new Date('2026-07-15T13:00:00Z'), 'America/Toronto')).toBe('Good morning');
    expect(greeting(new Date('2026-07-15T18:00:00Z'), 'America/Toronto')).toBe('Good afternoon');
    expect(greeting(new Date('2026-07-15T23:00:00Z'), 'America/Toronto')).toBe('Good evening');
  });
});

/**
 * The clock the greeting reads.
 *
 * A phone put away at lunch and opened after dinner should not go on saying
 * "Good afternoon": the clock moves every minute, and again the moment the
 * app comes back to the front.
 */
describe('Clock', () => {
  const lunch = new Date('2026-07-15T16:00:00Z'); // noon in Toronto
  const dinner = new Date('2026-07-15T23:30:00Z'); // half past seven

  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(lunch);
    app.native = false;
    app.resumed = [];
    app.removed = 0;
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    vi.useRealTimers();
  });

  const zoned = (clock: Clock) => greeting(clock.now(), 'America/Toronto');

  it('moves on every minute', () => {
    const clock = TestBed.inject(Clock);
    expect(zoned(clock)).toBe('Good afternoon');

    vi.setSystemTime(dinner);
    expect(zoned(clock)).toBe('Good afternoon');

    vi.advanceTimersByTime(60_000);
    expect(zoned(clock)).toBe('Good evening');
  });

  it('moves the moment the app comes back to the front on a phone', async () => {
    app.native = true;
    const clock = TestBed.inject(Clock);
    await Promise.resolve();

    expect(app.resumed).toHaveLength(1);

    vi.setSystemTime(dinner);
    app.resumed[0]();

    expect(zoned(clock)).toBe('Good evening');
  });

  it('moves when the tab comes back, in a browser', () => {
    const clock = TestBed.inject(Clock);
    vi.setSystemTime(dinner);

    // Hidden: nothing to greet yet.
    const hidden = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
    document.dispatchEvent(new Event('visibilitychange'));
    expect(zoned(clock)).toBe('Good afternoon');

    hidden.mockReturnValue('visible');
    document.dispatchEvent(new Event('visibilitychange'));
    expect(zoned(clock)).toBe('Good evening');

    hidden.mockRestore();
  });

  it('stops ticking and listening once the app is done with it', async () => {
    app.native = true;
    const clock = TestBed.inject(Clock);
    await Promise.resolve();

    TestBed.resetTestingModule();
    await Promise.resolve();
    await Promise.resolve();

    vi.setSystemTime(dinner);
    vi.advanceTimersByTime(120_000);

    expect(zoned(clock)).toBe('Good afternoon');
    expect(app.removed).toBe(1);
  });
});
