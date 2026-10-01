import { Hourglass } from 'lucide-angular';
import type { FeatureNavEntry } from '../../core/feature-flags';

/**
 * Demand (the WAIT track): who is waiting for which upcoming event, and how
 * many of them went on to buy.
 *
 * Off until the track ships it, and this file is the only one it edits to
 * switch it on (core/feature-flags.ts says why).
 */
export const DEMAND_NAV: FeatureNavEntry = {
  enabled: false,
  label: 'Demand',
  link: '/demand',
  glyph: Hourglass,
  after: '/events',
  // Waitlists are people, by name and address.
  allowed: (session) => session.can('attendees.view'),
};
