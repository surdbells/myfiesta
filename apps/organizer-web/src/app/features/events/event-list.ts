import { Component, computed, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { OrganizerEvent } from '../../core/api.types';
import { SessionStore } from '../../core/session';

@Component({
  selector: 'app-event-list',
  imports: [RouterLink, DatePipe],
  templateUrl: './event-list.html',
  styleUrl: './event-list.css',
})
export class EventList {
  private readonly api = inject(Api);
  readonly session = inject(SessionStore);

  readonly events = signal<OrganizerEvent[]>([]);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);

  /**
   * Split rather than sorted into one list.
   *
   * An organizer opens this to do something about a night that has not
   * happened yet. Past events are records, and mixing them in means scrolling
   * past history to reach the work.
   */
  readonly upcoming = computed(() =>
    this.events().filter((e) => new Date(e.starts_at) >= new Date()),
  );

  readonly past = computed(() => this.events().filter((e) => new Date(e.starts_at) < new Date()));

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);

    this.api.events().subscribe({
      next: ({ data }) => {
        this.events.set(data);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Could not load your events. Check your connection and try again.');
      },
    });
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
