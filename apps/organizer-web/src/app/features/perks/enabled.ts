import type { FeatureTab } from '../../core/feature-flags';

/**
 * Perks (the POINTS track): what an event's attendees can spend their Fiesta
 * Points on, and who has claimed what, as a tab of that event.
 *
 * Off until the track ships it, and this file is the only one it edits to
 * switch it on (core/feature-flags.ts says why).
 */
export const PERKS_TAB: FeatureTab = {
  enabled: false,
  label: 'Perks',
  path: 'perks',
  after: 'extras',
  // Sold beside a ticket, like the extras, only for points.
  allowed: (session) => session.can('tickets.manage'),
};
