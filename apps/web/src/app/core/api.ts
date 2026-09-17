import { HttpClient, HttpParams } from '@angular/common/http';
import { API_BASE_URL } from './api-base';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import {
  AccessUnlock,
  AnswerValue,
  Attendee,
  Discovery,
  EventDetail,
  EventSummary,
  OrderCreated,
  OrderStatus,
  OrganizerPage,
  Page,
  Quote,
  TicketAccess,
} from './api.types';

/**
 * What is being bought.
 *
 * Nothing with a currency sign in it: the client says which tickets and how
 * many, and every figure is computed from rows in the database. The same shape
 * prices a basket and places an order, so the two cannot describe different
 * baskets.
 */
export interface Basket {
  items: { ticket_type_id: string; quantity: number }[];
  /** Sold beside a ticket and admitting nobody. */
  add_ons?: { add_on_id: string; quantity: number }[];
  code?: string;
  ref?: string;
  access_code?: string;
}

export interface NewOrder extends Basket {
  buyer: { name: string; email: string; phone?: string };
  /** What the buyer answered for the order, keyed by question id. */
  answers?: Record<string, AnswerValue>;
  /** One entry per ticket, in the order they were filled in. */
  attendees?: Attendee[];
}

export interface EventQuery {
  q?: string;
  city?: string;
  country?: string;
  category?: string;
  max_price?: number;
  free?: boolean;
  cursor?: string;
}

/**
 * The only place this app knows an API exists.
 *
 * URLs live here rather than scattered through components — the platform this
 * replaces kept a hand-maintained list of 80 endpoint strings in a class each
 * client copied, and they drifted.
 */
@Injectable({ providedIn: 'root' })
export class Api {
  private readonly http = inject(HttpClient);

  /** Absolute in both halves — see API_BASE_URL for why. */
  private readonly base = inject(API_BASE_URL);

  events(query: EventQuery = {}): Observable<Page<EventSummary>> {
    let params = new HttpParams();

    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '') {
        params = params.set(key, String(value));
      }
    }

    return this.http.get<Page<EventSummary>>(`${this.base}/api/events`, { params });
  }

  event(slug: string): Observable<{ data: EventDetail }> {
    return this.http.get<{ data: EventDetail }>(`${this.base}/api/events/${slug}`);
  }

  /** An organizer's own page: who they are, what is on, what has been. */
  organizer(slug: string): Observable<{ data: OrganizerPage }> {
    return this.http.get<{ data: OrganizerPage }>(`${this.base}/api/organizers/${slug}`);
  }

  /** Price a basket. Free to call on every change: it reserves nothing. */
  quote(slug: string, basket: Basket): Observable<Quote> {
    return this.http.post<Quote>(`${this.base}/api/events/${slug}/quote`, basket);
  }

  /** Join a sold-out event's waitlist. */
  joinWaitlist(slug: string, body: { email: string; name?: string; quantity: number }): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/api/events/${slug}/waitlist`, body);
  }

  /** A presale code: what it opens on this event, or a 422 saying why not. */
  unlock(slug: string, code: string): Observable<AccessUnlock> {
    return this.http.post<AccessUnlock>(`${this.base}/api/events/${slug}/access`, { code });
  }

  /**
   * Place the order.
   *
   * One object rather than eight positional arguments: the list had reached
   * the point where two optional codes sat next to each other and the only
   * thing telling them apart was their position. Answers made it eight.
   */
  order(slug: string, order: NewOrder): Observable<OrderCreated> {
    return this.http.post<OrderCreated>(`${this.base}/api/events/${slug}/orders`, order);
  }

  /**
   * Order status, polled after returning from a payment page.
   *
   * The return itself proves nothing — a signed webhook decides — so the client
   * asks until the status settles.
   */
  /**
   * The front page, in one request.
   *
   * Four sections from four endpoints would be four chances to look broken on
   * a phone, and this is the first screen a stranger sees.
   */
  discover(city?: string): Observable<Discovery> {
    const params = city ? new HttpParams().set('city', city) : undefined;

    return this.http.get<Discovery>(`${this.base}/api/discover`, { params });
  }

  orderStatus(reference: string): Observable<OrderStatus> {
    return this.http.get<OrderStatus>(`${this.base}/api/orders/${reference}`);
  }

  /**
   * The tickets behind an emailed link.
   *
   * The token is the whole credential — there is no account to sign into,
   * because guest checkout is how most people buy.
   */
  ticketsByToken(token: string): Observable<TicketAccess> {
    return this.http.get<TicketAccess>(`${this.base}/api/tickets/${token}`);
  }
}
