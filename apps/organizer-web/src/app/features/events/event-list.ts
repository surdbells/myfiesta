import { Component, inject, signal } from '@angular/core';
import { forkJoin } from 'rxjs';
import { RouterLink } from '@angular/router';
import {
  UiBadge,
  UiButton,
  UiEmpty,
  UiErrorState,
  UiPageHeader,
  UiPagination,
  UiSkeleton,
} from '@myfiesta/ui';
import { eventDate, shortEventTime } from '../../core/event-time';
import { Api } from '../../core/api';
import { OrganizerEvent, Page, PageMeta } from '../../core/api.types';
import { SessionStore } from '../../core/session';

@Component({
  selector: 'app-event-list',
  imports: [RouterLink, UiPageHeader, UiButton, UiBadge, UiEmpty, UiErrorState, UiPagination, UiSkeleton],
  templateUrl: './event-list.html',
})
export class EventList {
  private readonly api = inject(Api);
  readonly session = inject(SessionStore);

  readonly when = shortEventTime;
  readonly onDate = eventDate;

  readonly loading = signal(true);
  readonly error = signal<string | null>(null);

  /**
   * Split rather than sorted into one list.
   *
   * An organizer opens this to do something about a night that has not
   * happened yet. Past events are records, and mixing them in means scrolling
   * past history to reach the work.
   *
   * Asked for as two lists and paged separately. It used to be one request
   * split here, and the server stopped at thirty — so an organization with a
   * season of weekly nights saw thirty events and no sign of the rest.
   */
  readonly upcoming = signal<OrganizerEvent[]>([]);
  readonly upcomingMeta = signal<PageMeta | null>(null);

  readonly past = signal<OrganizerEvent[]>([]);
  readonly pastMeta = signal<PageMeta | null>(null);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);

    forkJoin([this.api.events('upcoming'), this.api.events('past')]).subscribe({
      next: ([upcoming, past]) => {
        this.show('upcoming', upcoming);
        this.show('past', past);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Could not load your events. Check your connection and try again.');
      },
    });
  }

  /** Another page of one section, leaving the other where it is. */
  goToPage(when: 'upcoming' | 'past', page: number): void {
    this.api.events(when, page).subscribe({
      next: (result) => this.show(when, result),
      error: () => this.error.set('Could not load more events. Check your connection and try again.'),
    });
  }

  private show(when: 'upcoming' | 'past', page: Page<OrganizerEvent>): void {
    (when === 'upcoming' ? this.upcoming : this.past).set(page.data);
    (when === 'upcoming' ? this.upcomingMeta : this.pastMeta).set(page.meta);
  }

  /**
   * The colour a status is allowed to be.
   *
   * Published is the working state and gets no colour at all — a list where
   * every row is green says nothing. The two that are worth a glance are the
   * ones that mean the event is not selling.
   */
  statusTone(status: OrganizerEvent['status']): 'neutral' | 'warning' | 'danger' {
    return status === 'draft' ? 'warning' : status === 'cancelled' ? 'danger' : 'neutral';
  }

  /**
   * How far through the door an event is.
   *
   * Only meaningful once tickets exist, so it returns null rather than a
   * confident 0% for an event that has sold nothing.
   */
  arrivalRate(event: OrganizerEvent): number | null {
    if (event.tickets_issued === 0) return null;

    return Math.round((event.checked_in / event.tickets_issued) * 100);
  }
}
