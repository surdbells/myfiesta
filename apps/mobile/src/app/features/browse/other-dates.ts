import { Component, computed, input } from '@angular/core';
import type { EventPage } from '../../core/discovery';
import { shortEventTime } from '../../core/event-time';
import { MfList, MfRow } from '../../ui';
import { MfAvailability } from './availability';

/**
 * "More dates": the other upcoming dates of a night that repeats, from the
 * page's `other_dates`.
 *
 * Only dates on sale and still to come, soonest first, at most eight (the
 * API's OtherDates): somebody who cannot make this one, or who missed it, is
 * looking for the next few. Each says how much is left the way a card does,
 * so "Sold out" on Friday sends them to Saturday before they open it.
 *
 * Times are the venue's, as the night's own time on this screen is. Each row
 * opens that date's own screen; one event's screen opened over another's is a
 * new screen (LinkedScreenReuse), so it loads that date rather than keeping
 * this one.
 *
 * Nothing at all for a night that does not repeat, or whose other dates are
 * not on sale: no heading over an empty list, and no gap in the column it
 * sits in.
 */
@Component({
  selector: 'mf-other-dates',
  imports: [MfList, MfRow, MfAvailability],
  template: `
    @if (dates().length > 0) {
      <section>
        <mf-list heading="More dates">
          @for (date of dates(); track date.slug) {
            <mf-row [label]="date.when" [link]="['/e', date.slug]">
              <mf-availability [value]="date.availability" />
            </mf-row>
          }
        </mf-list>
      </section>
    }
  `,
  styles: `
    :host {
      display: contents;
    }
  `,
})
export class MfOtherDates {
  /** The night on screen. */
  readonly event = input.required<EventPage>();

  /** The other dates, in the venue's zone, soonest first as the API sent them. */
  readonly dates = computed(() => {
    const night = this.event();

    return (night.other_dates ?? []).map((date) => ({
      slug: date.slug,
      when: shortEventTime(date.starts_at, night.timezone),
      availability: date.availability,
    }));
  });
}
