import { Component, input, output } from '@angular/core';
import { OrganizerEventDetail, Series } from '../../../core/api.types';

/**
 * A repeating night's settings — when it stops, whether each date puts itself
 * on sale and how long before — under the Repeats panel on its Overview.
 *
 * The SCHED track's own file. The Overview places it once and never edits it
 * again, so the feature fills this in without touching the page. Until then it
 * draws nothing. `series` is null for a night that does not repeat.
 *
 * `changed` hands back the event as it stands after a write, read again
 * from GET /organizer/events/{id} once the part's own call has answered (the
 * API puts each feature's field on it: OrganizerEventExtras), and the Overview
 * reads its series again with it, since shortening one removes dates.
 *
 * `contents`, so an empty part adds no box and no gap to the page.
 */
@Component({
  selector: 'app-series-part',
  host: { class: 'contents' },
  template: ``,
})
export class SeriesPart {
  readonly event = input.required<OrganizerEventDetail>();
  readonly series = input<Series | null>(null);
  readonly changed = output<OrganizerEventDetail>();
}
