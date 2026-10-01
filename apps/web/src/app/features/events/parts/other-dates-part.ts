import { Component, input } from '@angular/core';
import { EventDetail } from '../../../core/api.types';

/**
 * "More dates": the other dates of a repeating night, on its event page
 * (`other_dates`, null for a night that does not repeat).
 *
 * The scheduling feature's own file. The event page places it once, in the
 * rail under the night's own date, and never edits it again, so the feature
 * fills this in without touching the page. Until then it draws nothing.
 *
 * `contents`, so the part adds no box of its own to the rail's grid: empty,
 * it takes no row and no gap, and the page looks as it did without it.
 */
@Component({
  selector: 'app-other-dates-part',
  host: { class: 'contents' },
  template: ``,
})
export class OtherDatesPart {
  readonly event = input.required<EventDetail>();
}
