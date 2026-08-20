import { Component, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { Discovery, EventSummary } from '../../core/api.types';
import { formatMoney } from '../../core/money';

/**
 * The front page.
 *
 * There was not one: the root rendered the same flat search list as /events, so
 * somebody arriving without a link to a specific event had nothing to look at
 * and no way to browse. The previous platform had six screens covering this.
 *
 * One request fills the whole page. This is the first thing a stranger sees, on
 * a phone, and four round trips is four chances to look broken.
 */
@Component({
  selector: 'app-home',
  imports: [RouterLink],
  templateUrl: './home.html',
  styleUrl: './home.css',
})
export class Home {
  private readonly api = inject(Api);
  private readonly router = inject(Router);

  readonly discovery = signal<Discovery | null>(null);
  readonly loading = signal(true);

  constructor() {
    this.api.discover().subscribe({
      next: (discovery) => {
        this.discovery.set(discovery);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  /** The lead event, given the hero. */
  lead(): EventSummary | null {
    return this.discovery()?.featured[0] ?? null;
  }

  /**
   * The rest of the featured set.
   *
   * Excludes the lead so it does not appear twice — a front page showing the
   * same event in the hero and the first card reads as a bug.
   */
  rest(): EventSummary[] {
    return this.discovery()?.featured.slice(1) ?? [];
  }

  /**
   * Upcoming, minus anything already featured above.
   *
   * Without this the two sections are near-identical on a small catalogue,
   * which is exactly the situation at launch.
   */
  upcoming(): EventSummary[] {
    const shown = new Set(this.discovery()?.featured.map((e) => e.slug));

    return (this.discovery()?.upcoming ?? []).filter((e) => !shown.has(e.slug));
  }

  when(event: EventSummary): string {
    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
      // The venue's zone, never the reader's. A Lagos event says 10pm to
      // somebody reading in Toronto.
      timeZone: event.timezone,
    }).format(new Date(event.starts_at));
  }

  price(event: EventSummary): string {
    if (event.is_sold_out) return 'Sold out';
    if (!event.from_price) return 'Free';

    return `From ${formatMoney(event.from_price)}`;
  }

  browseCity(city: string): void {
    void this.router.navigate(['/events'], { queryParams: { city } });
  }

  browseCategory(category: string): void {
    void this.router.navigate(['/events'], { queryParams: { category } });
  }
}
