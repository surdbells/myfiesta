import { HttpClient, HttpErrorResponse, HttpInterceptorFn, HttpParams } from '@angular/common/http';
import { Injectable, InjectionToken, inject } from '@angular/core';
import { DOCUMENT } from '@angular/common';
import { Router } from '@angular/router';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import {
  EventSummary,
  GuestPage,
  IssueResult,
  EventImage,
  EventImages,
  OrganizerEvent,
  OrganizerEventDetail,
  RefundResult,
  SoldOrder,
  PromoCode,
  Session,
  TicketType,
} from './api.types';
import { SessionStore } from './session';

/**
 * Where the API lives, supplied at runtime rather than compiled in.
 *
 * One bundle then serves staging and production, which is what stops a staging
 * build being promoted with the wrong host inside it.
 */
export const API_BASE_URL = new InjectionToken<string>('API_BASE_URL', {
  providedIn: 'root',
  factory: () => {
    const meta = inject(DOCUMENT).querySelector<HTMLMetaElement>('meta[name="api-base"]');

    return meta?.content || 'http://127.0.0.1:8000';
  },
});

/**
 * Attaches the token, and reacts when the server stops accepting it.
 *
 * A 401 means the token is gone or expired — the session is cleared rather
 * than kept, because a console that looks signed in and fails every action is
 * worse than one that asks you to sign in again.
 *
 * A 403 is left alone. That is the server saying this account may not do this
 * particular thing, which is information, not a broken session.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const session = inject(SessionStore);
  const router = inject(Router);
  const token = session.token;

  const request = token ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } }) : req;

  return next(request).pipe(
    catchError((error: HttpErrorResponse) => {
      if (error.status === 401 && session.signedIn()) {
        session.clear();
        void router.navigate(['/sign-in']);
      }

      return throwError(() => error);
    }),
  );
};

@Injectable({ providedIn: 'root' })
export class Api {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  signIn(email: string, password: string): Observable<Session> {
    return this.http.post<Session>(`${this.base}/api/auth/login`, {
      email,
      password,
      device: 'organizer-console',
    });
  }

  signOut(): Observable<unknown> {
    return this.http.post(`${this.base}/api/auth/logout`, {});
  }

  events(): Observable<{ data: OrganizerEvent[] }> {
    return this.http.get<{ data: OrganizerEvent[] }>(`${this.base}/api/organizer/events`);
  }

  event(id: string): Observable<OrganizerEventDetail> {
    return this.http.get<OrganizerEventDetail>(`${this.base}/api/organizer/events/${id}`);
  }

  createEvent(body: Record<string, unknown>): Observable<unknown> {
    return this.http.post(`${this.base}/api/organizer/events`, body);
  }

  updateEvent(id: string, body: Record<string, unknown>): Observable<unknown> {
    return this.http.patch(`${this.base}/api/organizer/events/${id}`, body);
  }

  publish(id: string, status: 'draft' | 'published'): Observable<{ status: string }> {
    return this.http.post<{ status: string }>(`${this.base}/api/organizer/events/${id}/publish`, {
      status,
    });
  }

  summary(id: string): Observable<EventSummary> {
    return this.http.get<EventSummary>(`${this.base}/api/organizer/events/${id}/summary`);
  }

  ticketTypes(eventId: string): Observable<{ data: TicketType[] }> {
    return this.http.get<{ data: TicketType[] }>(
      `${this.base}/api/organizer/events/${eventId}/ticket-types`,
    );
  }

  createTicketType(eventId: string, body: Record<string, unknown>): Observable<TicketType> {
    return this.http.post<TicketType>(
      `${this.base}/api/organizer/events/${eventId}/ticket-types`,
      body,
    );
  }

  updateTicketType(
    eventId: string,
    id: string,
    body: Record<string, unknown>,
  ): Observable<TicketType> {
    return this.http.patch<TicketType>(
      `${this.base}/api/organizer/events/${eventId}/ticket-types/${id}`,
      body,
    );
  }

  deleteTicketType(eventId: string, id: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(
      `${this.base}/api/organizer/events/${eventId}/ticket-types/${id}`,
    );
  }

  // --- issuing by hand ----------------------------------------------------

  issueTicket(eventId: string, body: Record<string, unknown>): Observable<IssueResult> {
    return this.http.post<IssueResult>(
      `${this.base}/api/organizer/events/${eventId}/tickets`,
      body,
    );
  }

  // --- guests -------------------------------------------------------------

  guests(eventId: string, search?: string): Observable<GuestPage> {
    const params = search ? new HttpParams().set('q', search) : undefined;

    return this.http.get<GuestPage>(`${this.base}/api/organizer/events/${eventId}/guests`, {
      params,
    });
  }

  // --- pictures -----------------------------------------------------------

  images(eventId: string): Observable<EventImages> {
    return this.http.get<EventImages>(`${this.base}/api/organizer/events/${eventId}/images`);
  }

  /**
   * Multipart, and no Content-Type header set by hand.
   *
   * The browser has to write that header itself, because it carries the
   * multipart boundary — setting it manually produces a request the server
   * cannot parse, with an error that says nothing about why.
   */
  uploadImage(
    eventId: string,
    file: File,
    kind: 'banner' | 'gallery',
    caption?: string,
  ): Observable<EventImage> {
    const body = new FormData();

    body.append('file', file);
    body.append('kind', kind);

    if (caption) body.append('caption', caption);

    return this.http.post<EventImage>(`${this.base}/api/organizer/events/${eventId}/images`, body);
  }

  captionImage(eventId: string, id: string, caption: string | null): Observable<EventImage> {
    return this.http.patch<EventImage>(
      `${this.base}/api/organizer/events/${eventId}/images/${id}`,
      { caption },
    );
  }

  deleteImage(eventId: string, id: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(
      `${this.base}/api/organizer/events/${eventId}/images/${id}`,
    );
  }

  reorderImages(eventId: string, ids: string[]): Observable<{ gallery: EventImage[] }> {
    return this.http.post<{ gallery: EventImage[] }>(
      `${this.base}/api/organizer/events/${eventId}/images/order`,
      { ids },
    );
  }

  // --- orders and refunds -------------------------------------------------

  orders(eventId: string): Observable<{ data: SoldOrder[] }> {
    return this.http.get<{ data: SoldOrder[] }>(
      `${this.base}/api/organizer/events/${eventId}/orders`,
    );
  }

  /**
   * Refund by ticket, never by amount.
   *
   * Omitting ticket_ids refunds the whole order. There is deliberately no way
   * to send a figure: the server works out what those tickets are worth, the
   * same way it works out what a buyer owes.
   */
  refund(
    eventId: string,
    orderId: string,
    body: { ticket_ids?: string[]; reason?: string | null },
  ): Observable<RefundResult> {
    return this.http.post<RefundResult>(
      `${this.base}/api/organizer/events/${eventId}/orders/${orderId}/refunds`,
      body,
    );
  }

  // --- codes --------------------------------------------------------------

  codes(eventId: string): Observable<{ data: PromoCode[] }> {
    return this.http.get<{ data: PromoCode[] }>(
      `${this.base}/api/organizer/events/${eventId}/codes`,
    );
  }

  createCode(eventId: string, body: Record<string, unknown>): Observable<PromoCode> {
    return this.http.post<PromoCode>(`${this.base}/api/organizer/events/${eventId}/codes`, body);
  }

  deactivateCode(eventId: string, id: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(
      `${this.base}/api/organizer/events/${eventId}/codes/${id}`,
    );
  }
}
