import { CommonModule } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { EventSummary } from '../../core/api.types';
import { formatFrom } from '../../core/money';
import { Seo } from '../../core/seo';

/**
 * Browse and search.
 *
 * The platform this replaces had no search at all — no endpoint, no filters, no
 * sort — and returned every published event in one unpaginated response.
 */
@Component({
  selector: 'mf-event-list',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './event-list.html',
  styleUrl: './event-list.css',
})
export class EventList {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly seo = inject(Seo);

  readonly events = signal<EventSummary[]>([]);
  readonly loading = signal(true);
  readonly query = signal('');
  readonly city = signal('');
  readonly freeOnly = signal(false);

  readonly formatFrom = formatFrom;

  constructor() {
    this.seo.forListing(
      'What is on',
      'Find events near you and get tickets in a couple of taps.',
      'https://myfiesta.ca/events',
    );

    // Filters live in the URL so a filtered search can be shared and comes
    // back the same, and so the back button behaves.
    this.route.queryParamMap.subscribe((params) => {
      this.query.set(params.get('q') ?? '');
      this.city.set(params.get('city') ?? '');
      this.freeOnly.set(params.get('free') === '1');
      this.load();
    });
  }

  search(): void {
    this.router.navigate([], {
      queryParams: {
        q: this.query() || null,
        city: this.city() || null,
        free: this.freeOnly() ? '1' : null,
      },
      queryParamsHandling: 'merge',
    });
  }

  private load(): void {
    this.loading.set(true);

    this.api
      .events({
        q: this.query() || undefined,
        city: this.city() || undefined,
        free: this.freeOnly() || undefined,
      })
      .subscribe({
        next: (page) => {
          this.events.set(page.data);
          this.loading.set(false);
        },
        error: () => this.loading.set(false),
      });
  }

  when(event: EventSummary): string {
    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
      // Rendered in the event's own zone, not the reader's. A Lagos event at
      // 10pm should say 10pm to someone browsing from Toronto.
      timeZone: event.timezone,
    }).format(new Date(event.starts_at));
  }
}
