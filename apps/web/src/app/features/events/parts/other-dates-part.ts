import { Component, computed, input } from '@angular/core';
import { UiIcon } from '@myfiesta/ui';
import { ChevronRight } from 'lucide-angular';
import type { Availability } from '@myfiesta/shared/availability';
import { EventDetail } from '../../../core/api.types';
import { AvailabilityBadge } from '../../../shared/availability-badge';

/** One date as the rail shows it: the day, the time, and where it links. */
interface DateRow {
  slug: string;
  href: string;
  day: string;
  time: string;
  label: string;
  availability: Availability | null;
}

/**
 * "More dates": the other dates of a repeating night, on its event page
 * (`other_dates`, null for a night that does not repeat).
 *
 * The scheduling feature's own file. The event page places it once, in the
 * rail under the night's own date, and never edits it again.
 *
 * Only dates on sale and still to come, soonest first, at most eight (the
 * API's OtherDates): somebody who cannot make this one is looking for the
 * next few. Each says how much is left the way a card does, so "Sold out"
 * on Friday sends them to Saturday before they open it.
 *
 * Each date is a link to its own page by its address, loaded fresh. The event
 * page reads its night once, from the address it was opened at, so a link the
 * router handled in place would change the address and leave this night on
 * the screen.
 *
 * Times are the venue's, with the zone named, as the night's own date above
 * is: somebody in Toronto looking at a Vancouver night needs to know it is
 * 9pm there.
 *
 * `contents`, so the part adds no box of its own to the rail's grid: with no
 * other dates it takes no row and no gap, and the page looks as it did
 * without it.
 */
@Component({
  selector: 'app-other-dates-part',
  host: { class: 'contents' },
  imports: [UiIcon, AvailabilityBadge],
  template: `
    @if (rows().length > 0) {
      <section
        class="more-dates rounded-(--radius-card) border border-border-subtle bg-surface-raised p-6 shadow-(--shadow-card)"
        aria-labelledby="more-dates-title"
      >
        <h2 id="more-dates-title" class="text-lg">More dates</h2>
        <p class="mt-1 text-sm text-text-muted">This night repeats. Pick another date if this one does not suit you.</p>

        <ul class="mt-4 grid gap-2" role="list">
          @for (row of rows(); track row.slug) {
            <li>
              <a
                class="date-link group flex items-center gap-3 rounded-md border border-border-subtle px-4 py-3 text-sm text-text no-underline hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                [href]="row.href"
                [attr.aria-label]="row.label"
              >
                <span class="min-w-0 flex-1">
                  <span class="block font-medium">{{ row.day }}</span>
                  <span class="mt-[2px] block text-text-muted">{{ row.time }}</span>
                </span>
                <app-availability [value]="row.availability" />
                <ui-icon class="shrink-0 text-text-subtle group-hover:text-primary-text" [icon]="chevronIcon" size="sm" />
              </a>
            </li>
          }
        </ul>
      </section>
    }
  `,
})
export class OtherDatesPart {
  readonly event = input.required<EventDetail>();

  protected readonly chevronIcon = ChevronRight;

  /** The other dates, in the venue's zone, soonest first as the API sent them. */
  readonly rows = computed<DateRow[]>(() => {
    const event = this.event();
    const zone = event.timezone;

    return (event.other_dates ?? []).map((date) => {
      const at = new Date(date.starts_at);
      const day = new Intl.DateTimeFormat('en-CA', { weekday: 'long', day: 'numeric', month: 'long', timeZone: zone }).format(at);
      const time = new Intl.DateTimeFormat('en-CA', { hour: 'numeric', minute: '2-digit', timeZoneName: 'short', timeZone: zone }).format(at);

      return {
        slug: date.slug,
        href: '/' + encodeURIComponent(date.slug),
        day,
        time,
        label: `${event.title}, ${day} at ${time}`,
        availability: date.availability,
      };
    });
  });
}
