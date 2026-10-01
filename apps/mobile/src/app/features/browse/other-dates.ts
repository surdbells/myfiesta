import { Component, input } from '@angular/core';
import type { EventPage } from '../../core/discovery';

/**
 * "More dates": the other upcoming dates of a night that repeats, from the
 * page's `other_dates`.
 *
 * A place held on the event screen so the feature that fills it never has to
 * edit that screen. It shows nothing yet, and takes no room while it does: no
 * heading over an empty list, and no gap in the column it sits in.
 */
@Component({
  selector: 'mf-other-dates',
  template: ``,
  styles: `
    :host {
      display: contents;
    }
  `,
})
export class MfOtherDates {
  /** The night on screen. */
  readonly event = input.required<EventPage>();
}
