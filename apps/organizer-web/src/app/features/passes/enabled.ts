import { Layers } from 'lucide-angular';
import type { FeatureNavEntry } from '../../core/feature-flags';

/**
 * Flex passes (the PASS track, its console screen in wave 3): several shows
 * sold together, each picked at purchase.
 *
 * Off until the track ships it, and this file is the only one it edits to
 * switch it on (core/feature-flags.ts says why).
 */
export const PASSES_NAV: FeatureNavEntry = {
  enabled: false,
  label: 'Passes',
  link: '/passes',
  glyph: Layers,
  after: '/events',
  // A pass is tickets, priced.
  allowed: (session) => session.can('tickets.manage'),
};
