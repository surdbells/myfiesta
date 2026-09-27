import { DOCUMENT } from '@angular/common';
import { Injectable, inject } from '@angular/core';
import { Api } from './api';
import { SessionStore } from './session';
import type { Money } from './money';

/** An event as a list card shows it. */
export interface EventCard {
  slug: string;
  title: string;
  starts_at: string;
  timezone: string;
  city: string;
  country: string;
  currency: string;
  category: string | null;
  poster_url: string | null;
  organizer: { name: string | null; slug: string | null };
  from_price: Money | null;
  /** The summary's own name for it — nothing on sale, whether it sold out or
      the organizer closed sales. Absent on an event page, which says it per
      tier instead. */
  is_sold_out?: boolean;
}

export interface TicketTypeCard {
  id: string;
  name: string;
  description: string | null;
  price: Money;
  remaining: number | null;
  sold_out: boolean;
  waiting: boolean;
  opens_after: { id: string; name: string } | null;
  max_per_order: number | null;
}

/** One event's page. */
export interface EventPage extends EventCard {
  description: string | null;
  description_text: string | null;
  ends_at: string | null;
  dress_code: string | null;
  min_age: number | null;
  id_required: boolean;
  venue: { name: string; address: string | null; city: string } | null;
  organizer: {
    name: string | null;
    slug: string | null;
    description?: string | null;
    is_verified?: boolean;
    logo_url?: string | null;
    /** Whether this reader follows them. False for a guest. */
    following?: boolean;
  };
  /** Whether this reader saved it. False for a guest. */
  saved?: boolean;
  gallery: { url: string; thumb_url: string; caption: string | null }[];
  ticket_types: TicketTypeCard[];
  calendar: { ics_url: string; google_url: string };
}

/**
 * An organizer's own page: who they are, what is on, what has been.
 *
 * The screen following somebody finally leads to. Until it existed, the list
 * of who you follow was a list of names with nowhere to go.
 */
export interface OrganizerPage {
  slug: string;
  name: string;
  description: string | null;
  is_verified: boolean;
  logo_url: string | null;
  /** Whether this reader follows them. False for a guest. */
  following: boolean;
  upcoming: EventCard[];
  /** The most recent nights that have already happened, newest first. */
  past: EventCard[];
}

export interface Discovery {
  featured: EventCard[];
  upcoming: EventCard[];
  cities: { city: string; country: string; events: number }[];
  categories: { value: string; label: string; events?: number }[];
}

/**
 * Browsing, which needs no account.
 *
 * Guest checkout is the primary path on this platform, so everything here is
 * reachable signed out — the app asks who somebody is only when it has to,
 * which is when they want the tickets they already hold.
 */
@Injectable({ providedIn: 'root' })
export class Discover {
  private readonly api = inject(Api);
  private readonly document = inject(DOCUMENT);
  private readonly session = inject(SessionStore);

  /**
   * Where the public site lives — the checkout, and any link worth sharing.
   *
   * Read from a meta tag like the API base, so one build serves staging and
   * production; `npm run sync` stamps it from PUBLIC_URL
   * (tools/stamp-mobile-api-base.cjs). Without one it is derived from the API
   * host, which on this platform is the same host with an api. prefix — and
   * the stamp refuses a store build whose API is shaped otherwise.
   */
  siteBase(): string {
    const meta = this.document.querySelector<HTMLMetaElement>('meta[name="site-base"]');

    if (meta?.content) return meta.content.replace(/\/+$/, '');

    const api = this.api.base;

    if (api.includes('//api.')) return api.replace('//api.', '//');

    // Development: the public site runs beside the API on its own port.
    return 'http://localhost:4320';
  }

  /** What the home screen is made of, in one request. */
  home(city?: string | null): Promise<Discovery> {
    return this.api.public<Discovery>('/api/discover', city ? { city } : undefined);
  }

  /** Search and filters. Future events only, soonest first. */
  async search(
    filters: { q?: string; city?: string | null; category?: string | null; free?: boolean; page?: number } = {},
  ): Promise<{ data: EventCard[]; hasMore: boolean }> {
    const query: Record<string, string> = { per_page: '24' };

    if (filters.q?.trim()) query['q'] = filters.q.trim();
    if (filters.city) query['city'] = filters.city;
    if (filters.category) query['category'] = filters.category;
    if (filters.free) query['free'] = '1';
    if (filters.page) query['page'] = String(filters.page);

    const body = await this.api.public<{ data: EventCard[]; meta?: { current_page: number; last_page: number } }>(
      '/api/events',
      query,
    );

    return {
      data: body.data,
      hasMore: !!body.meta && body.meta.current_page < body.meta.last_page,
    };
  }

  /**
   * Nights that have already happened, most recent first.
   *
   * Worth a shelf of its own: the gallery from last month is what sells the
   * next one, and somebody who missed it is exactly who should see it.
   */
  async past(city?: string | null): Promise<EventCard[]> {
    const from = new Date();
    from.setMonth(from.getMonth() - 3);

    const body = await this.api.public<{ data: EventCard[] }>('/api/events', {
      from: from.toISOString(),
      to: new Date().toISOString(),
      sort: 'recent',
      per_page: '10',
      ...(city ? { city } : {}),
    });

    return body.data;
  }

  /**
   * One event, read as whoever is holding the phone.
   *
   * The token goes unless this phone is a door pass — see below.
   */
  event(slug: string): Promise<EventPage> {
    return this.api
      .asReader<{ data: EventPage }>(`/api/events/${encodeURIComponent(slug)}`, undefined, this.readerToken())
      .then((body) => body.data);
  }

  /**
   * One organizer, read as whoever is holding the phone.
   *
   * The token goes for the same reason it goes to an event page: it is what
   * decides whether the button says Follow or Following. A guest gets the
   * page all the same.
   */
  organizer(slug: string): Promise<OrganizerPage> {
    return this.api
      .asReader<{ data: OrganizerPage }>(
        `/api/organizers/${encodeURIComponent(slug)}`,
        undefined,
        this.readerToken(),
      )
      .then((body) => body.data);
  }

  /** Nights kept for later. Soonest first; past ones drop off on their own. */
  async saved(): Promise<EventCard[]> {
    const body = await this.api.mine<{ data: EventCard[] }>('/api/me/saved');

    return body.data;
  }

  save(slug: string, on: boolean): Promise<{ saved: boolean }> {
    const path = `/api/events/${encodeURIComponent(slug)}/save`;

    return on ? this.api.put(path) : this.api.remove(path);
  }

  following(): Promise<{ data: { slug: string; name: string; is_verified?: boolean }[] }> {
    return this.api.mine('/api/me/following');
  }

  follow(slug: string, on: boolean): Promise<{ following: boolean }> {
    const path = `/api/organizers/${encodeURIComponent(slug)}/follow`;

    return on ? this.api.put(path) : this.api.remove(path);
  }

  private readerToken(): string | null {
    const who = this.session.session();

    // Anything but a door pass. Every login carries the attendee ability —
    // an organizer browses and saves nights like everybody else — but a door
    // token is for a door, and sending it here would have the API answer a
    // question nobody asked.
    return who && who.scope !== 'door' ? who.token : null;
  }

  /** Join the waitlist for a night with nothing left to buy. */
  waitlist(slug: string, body: { name: string; email: string; quantity: number }): Promise<{ message: string }> {
    return this.api.publicPost(`/api/events/${encodeURIComponent(slug)}/waitlist`, body);
  }
}
