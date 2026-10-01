import { MessageSquareText } from 'lucide-angular';
import type { FeatureNavEntry, FeatureTab } from '../../core/feature-flags';

/**
 * Post-event surveys (the SURVEY track): the organization's survey templates
 * in the sidebar, and one event's feedback as a tab of that event.
 *
 * Off until the track ships them, and this file is the only one it edits to
 * switch them on (core/feature-flags.ts says why).
 */
export const SURVEYS_NAV: FeatureNavEntry = {
  enabled: false,
  label: 'Surveys',
  link: '/surveys',
  glyph: MessageSquareText,
  after: '/campaigns',
  // A survey is an email to the people who came.
  allowed: (session) => session.can('messages.send'),
};

export const FEEDBACK_TAB: FeatureTab = {
  enabled: false,
  label: 'Feedback',
  path: 'feedback',
  after: 'messages',
  allowed: (session) => session.can('messages.send'),
};
