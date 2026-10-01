import { MessageSquareText } from 'lucide-angular';
import type { FeatureNavEntry, FeatureTab } from '../../core/feature-flags';

/**
 * Post-event surveys (the SURVEY track): the organization's survey templates
 * in the sidebar, and one event's feedback as a tab of that event.
 *
 * On: the track has shipped them. This file is the one place to switch them
 * off again (core/feature-flags.ts says why).
 */
export const SURVEYS_NAV: FeatureNavEntry = {
  enabled: true,
  label: 'Surveys',
  link: '/surveys',
  glyph: MessageSquareText,
  after: '/campaigns',
  // A survey is an email to the people who came.
  allowed: (session) => session.can('messages.send'),
};

export const FEEDBACK_TAB: FeatureTab = {
  enabled: true,
  label: 'Feedback',
  path: 'feedback',
  after: 'messages',
  allowed: (session) => session.can('messages.send'),
};
