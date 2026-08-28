import { CommonModule } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { UiIcon } from '@myfiesta/ui';
import { BadgeCheck, CalendarDays, Clock, Heart, MapPin, Share2 } from 'lucide-angular';
import { Api } from '../../core/api';
import { EventDetail as EventDetailModel } from '../../core/api.types';
import { CheckoutStore } from '../../core/checkout-store';
import { Saves } from '../../core/saves';
import { formatMoney } from '../../core/money';
import { Seo } from '../../core/seo';

/**
 * The page a shared link lands on. It sells the night; the buying moved to
 * its own two steps at {slug}/tickets and {slug}/checkout, so this page's
 * whole job is the answer to "do I want to go" — and one green button.
 */
@Component({
  selector: 'mf-event-detail',
  standalone: true,
  imports: [CommonModule, RouterLink, UiIcon],
  templateUrl: './event-detail.html',
})
export class EventDetail {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly seo = inject(Seo);
  private readonly store = inject(CheckoutStore);
  readonly saves = inject(Saves);

  protected readonly whenIcon = CalendarDays;
  protected readonly timeIcon = Clock;
  protected readonly whereIcon = MapPin;
  protected readonly verifiedIcon = BadgeCheck;
  protected readonly saveIcon = Heart;
  protected readonly shareIcon = Share2;

  readonly event = signal<EventDetailModel | null>(null);
  readonly notFound = signal(false);
  readonly shared = signal(false);

  readonly formatMoney = formatMoney;

  constructor() {
    // A promoter's ref rides the shared link; kept for the order so the
    // promoter gets credit even though checkout is two pages away.
    this.store.ref.set(this.route.snapshot.queryParamMap.get('ref'));

    const slug = this.route.snapshot.paramMap.get('slug')!;

    this.api.event(slug).subscribe({
      next: ({ data }) => {
        this.event.set(data);
        this.seo.forEvent(data, `https://myfiesta.ca/${data.slug}`);
      },
      error: () => this.notFound.set(true),
    });
  }

  anythingOnSale(): boolean {
    return (this.event()?.ticket_types ?? []).some((t) => t.status === 'on_sale');
  }

  startingFrom(): string {
    const event = this.event();
    if (!event) return '';
    if (event.is_sold_out) return 'Sold out';
    if (!event.from_price) return 'Free';

    return formatMoney(event.from_price);
  }

  /** A plain search link — no keys, no geocoding, and it opens their maps app. */
  mapUrl(): string {
    const event = this.event();
    if (!event) return '';

    const where = [event.venue?.name, event.venue?.address, event.city]
      .filter(Boolean)
      .join(', ');

    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(where)}`;
  }

  toggleSave(): void {
    const event = this.event();
    if (event) this.saves.toggle(event.slug);
  }

  async share(): Promise<void> {
    const event = this.event();
    if (!event) return;

    const url = `https://myfiesta.ca/${event.slug}`;

    try {
      if (navigator.share) {
        await navigator.share({ title: event.title, url });
      } else {
        await navigator.clipboard.writeText(url);
        this.shared.set(true);
        setTimeout(() => this.shared.set(false), 2000);
      }
    } catch {
      // Dismissed the sheet; nothing to clean up.
    }
  }

  when(): string {
    const event = this.event();
    if (!event) return '';

    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      timeZone: event.timezone,
    }).format(new Date(event.starts_at));
  }

  /** The calendar date alone, spelled out for the details card. */
  dateLong(): string {
    const event = this.event();
    if (!event) return '';

    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
      timeZone: event.timezone,
    }).format(new Date(event.starts_at));
  }

  /** Doors to close, in the venue's zone with the abbreviation that proves it. */
  timeRange(): string {
    const event = this.event();
    if (!event) return '';

    const clock = (iso: string, zoneName: boolean) =>
      new Intl.DateTimeFormat('en-CA', {
        hour: 'numeric',
        minute: '2-digit',
        ...(zoneName ? { timeZoneName: 'short' } : {}),
        timeZone: event.timezone,
      }).format(new Date(iso));

    return event.ends_at
      ? clock(event.starts_at, false) + ' – ' + clock(event.ends_at, true)
      : clock(event.starts_at, true);
  }

  organizerInitial(): string {
    return (this.event()?.organizer.name.trim().charAt(0) ?? '?').toUpperCase();
  }
}
