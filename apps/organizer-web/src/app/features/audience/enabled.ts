import { ChartPie } from 'lucide-angular';
import type { FeatureNavEntry, FeatureTab } from '../../core/feature-flags';

/**
 * Audience (the INSIGHT track): who buys, across the organization by month in
 * the sidebar, and for one event as a tab of that event.
 *
 * Off until the track ships them, and this file is the only one it edits to
 * switch them on (core/feature-flags.ts says why).
 */
export const AUDIENCE_NAV: FeatureNavEntry = {
  enabled: false,
  label: 'Audience',
  link: '/audience',
  glyph: ChartPie,
  after: '/events',
  // The endpoints answer to attendees.view.
  allowed: (session) => session.can('attendees.view'),
};

export const AUDIENCE_TAB: FeatureTab = {
  enabled: false,
  label: 'Audience',
  path: 'audience',
  after: 'guests',
  allowed: (session) => session.can('attendees.view'),
};
