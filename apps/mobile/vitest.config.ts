import { defineConfig } from 'vitest/config';

/**
 * Every spec file in a module graph of its own.
 *
 * The Angular builder shares one graph between all the files a worker runs,
 * to feel like Karma. The phone's specs stand in for Capacitor's plugins with
 * vi.mock, and a mock only reaches modules loaded after it: in a shared graph
 * whichever file ran first decided what Preferences, Capacitor or the
 * notifications plugin were for every file after it. A machine with a worker
 * for each file never saw that. CI's runners have two or four cores, so the
 * reminders, theme and held-tickets specs failed there, or the scanner's,
 * depending on which file happened to go first.
 *
 * Isolation costs each file its own start-up, a few seconds across the suite.
 */
export default defineConfig({
  test: {
    isolate: true,
  },
});
