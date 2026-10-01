import { LayoutTemplate } from 'lucide-angular';
import type { FeatureNavEntry } from '../../core/feature-flags';

/**
 * Event templates (the CLONE track): an event kept as a starting point, and
 * new events made from it.
 *
 * Off until the track ships it, and this file is the only one it edits to
 * switch it on (core/feature-flags.ts says why).
 */
export const TEMPLATES_NAV: FeatureNavEntry = {
  enabled: false,
  label: 'Templates',
  link: '/templates',
  glyph: LayoutTemplate,
  after: '/events',
  // A template is a way of creating events.
  allowed: (session) => session.can('events.create'),
};
