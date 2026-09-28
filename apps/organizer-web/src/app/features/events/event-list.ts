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
import { formatMoney } from '../../core/money';
import { Api } from '../../core/api';
import { OrganizerEvent, Page, PageMeta } from '../../core/api.types';
import { SessionStore } from '../../core/session';
import { eventStatusLabel } from './event-status';

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
  readonly cash = formatMoney;

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
   * ones that mean the event is not selling. Waiting for review is on its
   * way, not stuck, so it is the brand colour rather than a warning.
   */
  statusTone(status: OrganizerEvent['status']): 'neutral' | 'brand' | 'warning' | 'danger' {
    return status === 'draft' ? 'warning' : status === 'cancelled' ? 'danger' : status === 'in_review' ? 'brand' : 'neutral';
  }

  readonly statusLabel = eventStatusLabel;

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

  /** How much of the room has gone, where the room has a size. */
  sold(event: OrganizerEvent): number | null {
    if (!event.capacity) return null;

    return Math.min(1, event.tickets_issued / event.capacity);
  }

  /**
   * How long until the doors, in the words somebody would say it.
   *
   * The unit an organizer thinks in changes with the distance: a night three
   * months out is "in 3 months" and one this evening is "tonight", and the
   * difference between those two is the whole reason this screen is opened.
   */
  countdown(event: OrganizerEvent): string | null {
    const start = new Date(event.starts_at).getTime();
    const hours = (start - Date.now()) / 3_600_000;

    if (hours < 0) return null;
    if (hours < 6) return 'Doors soon';
    if (hours < 24) return 'Tonight';
    if (hours < 48) return 'Tomorrow';

    const days = Math.round(hours / 24);
    if (days < 14) return `In ${days} days`;
    if (days < 60) return `In ${Math.round(days / 7)} weeks`;

    return `In ${Math.round(days / 30)} months`;
  }

  /**
   * When the last ticket sold, and whether that is worth saying.
   *
   * Only for events still to come, and only once something has sold: "nothing
   * since Tuesday" is a prompt to do something, and the same sentence about a
   * night that already happened is noise.
   */
  lastSale(event: OrganizerEvent): string | null {
    if (!event.last_sale_at || new Date(event.starts_at).getTime() < Date.now()) return null;

    const hours = (Date.now() - new Date(event.last_sale_at).getTime()) / 3_600_000;

    if (hours < 1) return 'Sold in the last hour';
    if (hours < 24) return `Last sold ${Math.round(hours)}h ago`;

    const days = Math.round(hours / 24);

    return days === 1 ? 'Last sold yesterday' : `Nothing sold in ${days} days`;
  }

  /** Whether the lull is long enough to be worth flagging rather than stating. */
  stalled(event: OrganizerEvent): boolean {
    if (!event.last_sale_at || event.status !== 'published') return false;
    if (new Date(event.starts_at).getTime() < Date.now()) return false;

    const days = (Date.now() - new Date(event.last_sale_at).getTime()) / 86_400_000;

    return days >= 7;
  }

  /** Of the people who looked, how many bought. Null before views were counted. */
  conversion(event: OrganizerEvent): number | null {
    if (!event.views) return null;

    return event.orders / event.views;
  }

  percent(rate: number | null): string {
    return rate === null ? '—' : `${(rate * 100).toFixed(rate < 0.1 ? 1 : 0)}%`;
  }
}
