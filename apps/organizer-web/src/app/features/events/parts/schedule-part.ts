import { Component, input, output } from '@angular/core';
import { OrganizerEventDetail } from '../../../core/api.types';

/**
 * Going on sale at a set time (`publish_at`), beside the button that sends an
 * event for review, on its Overview.
 *
 * The SCHED track's own file. The Overview places it once and never edits it
 * again, so the feature fills this in without touching the page. Until then it
 * draws nothing.
 *
 * `changed` hands back the event as it stands after a write, read again
 * from GET /organizer/events/{id} once the part's own call has answered (the
 * API puts each feature's field on it: OrganizerEventExtras), and the Overview
 * takes it from there: its own copy, the workspace header, the series panel.
 *
 * `contents`, so an empty part adds no box and no gap to the page.
 */
@Component({
  selector: 'app-schedule-part',
  host: { class: 'contents' },
  template: ``,
})
export class SchedulePart {
  readonly event = input.required<OrganizerEventDetail>();
  readonly changed = output<OrganizerEventDetail>();
}
