import { HttpClient, HttpParams } from '@angular/common/http';
import { API_BASE_URL } from './api-base';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import {
  EventDetail,
  EventSummary,
  OrderCreated,
  OrderStatus,
  Page,
  Quote,
  TicketAccess,
} from './api.types';

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

  quote(
    slug: string,
    items: { ticket_type_id: string; quantity: number }[],
    code?: string,
    ref?: string,
  ): Observable<Quote> {
    return this.http.post<Quote>(`${this.base}/api/events/${slug}/quote`, { items, code, ref });
  }

  order(
    slug: string,
    items: { ticket_type_id: string; quantity: number }[],
    buyer: { name: string; email: string; phone?: string },
    code?: string,
    ref?: string,
  ): Observable<OrderCreated> {
    return this.http.post<OrderCreated>(`${this.base}/api/events/${slug}/orders`, {
      items,
      buyer,
      code,
      ref,
    });
  }

  /**
   * Order status, polled after returning from a payment page.
   *
   * The return itself proves nothing — a signed webhook decides — so the client
   * asks until the status settles.
   */
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
