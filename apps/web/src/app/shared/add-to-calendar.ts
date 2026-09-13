import { Component, computed, input } from '@angular/core';
import { UiIcon } from '@myfiesta/ui';
import { CalendarPlus } from 'lucide-angular';
import { CalendarLinks } from '../core/api.types';

/**
 * "Add to calendar", wherever the date is shown.
 *
 * Two links rather than a menu. Google Calendar takes a link; Apple Calendar
 * and Outlook take the file, and a phone opening the file offers to add it
 * straight away. A dropdown to choose between two things is a tap nobody
 * needed.
 *
 * Both links come from the server, which writes the file too, so the date in
 * somebody's calendar is the same date the ticket says.
 *
 * Nothing is shown once the night is over: a past event in a calendar is
 * clutter, and offering it reads as though the page does not know the date.
 */
@Component({
  selector: 'app-add-to-calendar',
  imports: [UiIcon],
  template: `
    @if (!over()) {
      <p class="m-0 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm" [class.justify-center]="centered()">
        <span class="inline-flex items-center gap-1.5 text-text-muted" [class.basis-full]="stacked()">
          @if (!stacked()) {
            <ui-icon [icon]="icon" size="sm" />
          }
          Add to calendar
        </span>
        <a class="font-medium text-primary-text no-underline hover:underline" [href]="links().google_url" target="_blank" rel="noopener">Google</a>
        <span class="text-text-subtle" aria-hidden="true">·</span>
        <a class="font-medium text-primary-text no-underline hover:underline" [href]="links().ics_url">Apple or Outlook</a>
      </p>
    }
  `,
})
export class AddToCalendar {
  readonly links = input.required<CalendarLinks>();
  /** When the event finishes (or starts, if no end is known). */
  readonly until = input.required<string>();
  readonly centered = input(false);
  /** The label on its own line, for a narrow column. */
  readonly stacked = input(false);

  protected readonly icon = CalendarPlus;

  protected readonly over = computed(() => {
    const at = new Date(this.until()).getTime();

    return Number.isFinite(at) && at < Date.now();
  });
}
