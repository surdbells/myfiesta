import { DestroyRef, Injectable, inject, signal } from '@angular/core';
import { App as CapacitorApp } from '@capacitor/app';
import { Capacitor } from '@capacitor/core';

export type PartOfDay = 'morning' | 'afternoon' | 'evening';

/**
 * The hour on a clock in `zone`, 0 to 23.
 *
 * Read through Intl rather than worked out from an offset, so summer time is
 * the zone's own rule and never a sum. A zone the browser does not know —
 * an old name, a typo stored years ago — throws a RangeError, and the
 * phone's own zone answers instead; so does no zone at all.
 */
function hourIn(now: Date, zone: string | null | undefined): number {
  const read = (timeZone?: string) =>
    Number(new Intl.DateTimeFormat('en-CA', { hour: 'numeric', hourCycle: 'h23', timeZone }).format(now));

  if (zone) {
    try {
      return read(zone);
    } catch {
      // Unknown here: fall through to the device.
    }
  }

  return read(undefined);
}

/**
 * Morning, afternoon or evening, where the person is.
 *
 * Five in the morning to noon is morning, noon to five is afternoon, and
 * five in the evening until five the next morning is evening — somebody
 * opening the app at two after a night out is still having their evening.
 */
export function partOfDay(now: Date, zone: string | null | undefined): PartOfDay {
  const hour = hourIn(now, zone);

  if (hour >= 5 && hour < 12) return 'morning';
  if (hour >= 12 && hour < 17) return 'afternoon';

  return 'evening';
}

/** "Good morning", "Good afternoon" or "Good evening". */
export function greeting(now: Date, zone: string | null | undefined): string {
  return `Good ${partOfDay(now, zone)}`;
}

/**
 * The time, for what is said by the time of day.
 *
 * A minute is fine enough for a greeting, and a signal that ticks once a
 * minute costs nothing. It ticks again when the app comes back to the front,
 * because a phone put away at lunch and opened after dinner should not say
 * "Good afternoon" until the next minute turns over.
 */
@Injectable({ providedIn: 'root' })
export class Clock {
  readonly now = signal(new Date());

  constructor() {
    const tick = () => this.now.set(new Date());
    const timer = setInterval(tick, 60_000);
    const destroyRef = inject(DestroyRef);

    destroyRef.onDestroy(() => clearInterval(timer));

    if (Capacitor.isNativePlatform()) {
      const listening = CapacitorApp.addListener('resume', tick);

      destroyRef.onDestroy(() => void listening.then((handle) => handle.remove()));
    } else if (typeof document !== 'undefined') {
      // In a browser, while developing: the tab coming back is the same moment.
      const visible = () => {
        if (document.visibilityState === 'visible') tick();
      };

      document.addEventListener('visibilitychange', visible);
      destroyRef.onDestroy(() => document.removeEventListener('visibilitychange', visible));
    }
  }
}
