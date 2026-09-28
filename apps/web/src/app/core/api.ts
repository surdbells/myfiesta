import { HttpClient, HttpParams } from '@angular/common/http';
import { API_BASE_URL } from './api-base';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import {
  AccessUnlock,
  AnswerValue,
  Attendee,
  CategoryPlace,
  CityPlace,
  ContactDetails,
  Discovery,
  EventDetail,
  EventSummary,
  Facets,
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
  /** Bought inside an organizer's own site, for their reports. */
  embedded?: boolean;
  /**
   * The box by the pay button, as the buyer left it: the terms, the privacy
   * policy and the refund policy, agreed to. The server refuses the order
   * without it and keeps which version was agreed to, and when.
   */
  accept_terms: boolean;
  /**
   * The order the last press placed when its payment page could not be
   * opened. The new order takes over its hold instead of holding the same
   * places again.
   */
  retry_of?: string;
}

/** The windows the listing understands, each in the event's own zone. */
export type When = 'upcoming' | 'today' | 'weekend' | 'month' | 'past';

export interface EventQuery {
  q?: string;
  city?: string;
  country?: string;
  category?: string;
  max_price?: number;
  free?: boolean;
  cursor?: string;
  when?: When;
  /** Calendar days, YYYY-MM-DD, compared with each event's own date. */
  date_from?: string;
  date_to?: string;
  /** Comma-separated states; `on_sale` is everything but sold out. */
  availability?: string;
  sort?: 'soonest' | 'recent';
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

  /** The categories and cities alone: the listing's filters and the city picker. */
  facets(): Observable<Facets> {
    return this.http.get<Facets>(`${this.base}/api/discover/facets`);
  }

  /** A category's own page, by the slug the API gave it. 404 when there is no such category. */
  category(slug: string): Observable<{ data: CategoryPlace }> {
    return this.http.get<{ data: CategoryPlace }>(`${this.base}/api/discover/categories/${encodeURIComponent(slug)}`);
  }

  /** A city's own page. 404 when nothing has been on there lately. */
  city(slug: string): Observable<{ data: CityPlace }> {
    return this.http.get<{ data: CityPlace }>(`${this.base}/api/discover/cities/${encodeURIComponent(slug)}`);
  }

  /**
   * Somebody looked at this event. A count, and nothing about who.
   *
   * Fire and forget: a view that fails to count costs nothing, and a buyer
   * must never wait on it.
   */
  recordView(slug: string, embed: boolean): void {
    this.http.post(`${this.base}/api/events/${slug}/views`, embed ? { embed: true } : {}).subscribe({ error: () => undefined });
  }

  /**
   * Ask for a copy of your data, or to be erased.
   *
   * The answer is the same whether or not the address is known here, so
   * there is nothing in the response worth reading beyond "it worked".
   */
  requestMyData(kind: 'export' | 'erasure', email: string): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/api/privacy/requests`, { kind, email });
  }

  /** Give a ticket back, from the same link that shows it. */
  returnTicket(token: string, ticketId: string): Observable<{ message: string; access: TicketAccess }> {
    return this.http.post<{ message: string; access: TicketAccess }>(`${this.base}/api/tickets/${token}/resale/${ticketId}`, {});
  }

  /** Changed their mind, before anybody took the place. */
  keepTicket(token: string, ticketId: string): Observable<{ message: string; access: TicketAccess }> {
    return this.http.delete<{ message: string; access: TicketAccess }>(`${this.base}/api/tickets/${token}/resale/${ticketId}`);
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

  /** Who operates the platform, for the contact and legal pages. */
  contact(): Observable<{ data: ContactDetails }> {
    return this.http.get<{ data: ContactDetails }>(`${this.base}/api/contact`);
  }
}
