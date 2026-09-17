import {
  HttpClient,
  HttpErrorResponse,
  HttpEventType,
  HttpInterceptorFn,
  HttpParams,
} from '@angular/common/http';
import { Injectable, InjectionToken, inject } from '@angular/core';
import { DOCUMENT } from '@angular/common';
import { Router } from '@angular/router';
import { Observable, throwError } from 'rxjs';
import { catchError, map } from 'rxjs/operators';
import {
  AttendeeMessage,
  Brand,
  CancellationPreview,
  CancellationResult,
  DoorList,
  EventSummary,
  SalesReport,
  OfflineScan,
  SyncResult,
  MessageAudience,
  GuestPage,
  IssueResult,
  EventImage,
  EventImages,
  EventOption,
  EventQuestion,
  OrganizationOrderPage,
  Page,
  OrganizerEvent,
  OrganizerEventDetail,
  RefundResult,
  Reminder,
  ScanResult,
  Series,
  SeriesOccurrence,
  SoldOrder,
  PromoCode,
  OrganizationCode,
  CodeBatch,
  WaitlistPage,
  TeamPage,
  InvitationDetails,
  DoorPass,
  DoorPassPreview,
  DoorPassSession,
  Session,
  TicketType,
  PayoutStatement,
  PayoutDestination,
  UploadProgress,
  Overview,
} from './api.types';
import { SessionStore } from './session';
import { DoorPassStore } from './door-pass';

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
  const base = inject(API_BASE_URL);
  const doorPass = inject(DoorPassStore);

  // A door pass on this phone, for its own door. Sent instead of any organizer
  // session, and never with the organization header: it acts for one event's
  // door, not for an organization.
  const passToken = doorPass.tokenFor(req.url, base);

  if (passToken) {
    return next(req.clone({ setHeaders: { Authorization: `Bearer ${passToken}` } })).pipe(
      catchError((error: HttpErrorResponse) => {
        // The pass was taken back or ran out. An organizer session on the same
        // phone is a different credential and stays exactly as it was.
        if (error.status === 401) {
          doorPass.end('This door pass has stopped working. It was taken back, or the night is over.');
        }

        return throwError(() => error);
      }),
    );
  }

  const token = session.token;

  const headers: Record<string, string> = {};

  // Only to the API. The trailing slash matters: without it a host named
  // api.myfiesta.ca.example.com would pass the check and be handed the token.
  const toApi = req.url.startsWith(`${base.replace(/\/+$/, '')}/`);

  if (token && toApi && !req.headers.has('Authorization')) headers['Authorization'] = `Bearer ${token}`;

  /*
   * Which organization this is about, for somebody in more than one.
   *
   * The server has read this header all along and falls back to the first
   * membership without it — and the console never sent it, so switching
   * organization changed the sidebar and left the dashboard, orders and
   * payouts showing the first one.
   */
  const organization = session.current()?.id;

  if (token && toApi && organization) headers['X-Organization'] = organization;

  const request = Object.keys(headers).length > 0 ? req.clone({ setHeaders: headers }) : req;

  return next(request).pipe(
    catchError((error: HttpErrorResponse) => {
      // Only when it was this session's token that was refused. A request
      // that brought its own (throwing a door pass away) says nothing about it.
      if (error.status === 401 && session.signedIn() && !req.headers.has('Authorization')) {
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

  // --- getting an account -------------------------------------------------

  /**
   * Sign up as an organizer.
   *
   * Returns a session, so signing up and being able to create an event are one
   * step — or a 202 with `pending`, which is what comes back when the address
   * already has an account. The server deliberately will not say which, so
   * neither does this.
   */
  register(body: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    organization?: string;
    /** Joining somebody's organization instead of creating one. */
    invitation?: string;
  }): Observable<Session & { pending?: boolean }> {
    return this.http.post<Session & { pending?: boolean }>(
      `${this.base}/api/auth/register`,
      { ...body, device: 'organizer-console' },
    );
  }

  forgotPassword(email: string): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/api/auth/forgot-password`, { email });
  }

  resetPassword(body: {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
  }): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/api/auth/reset-password`, body);
  }

  updateProfile(body: {
    name?: string;
    phone?: string | null;
    timezone?: string | null;
  }): Observable<{ name: string; email: string; phone: string | null; timezone: string | null }> {
    return this.http.patch<{
      name: string;
      email: string;
      phone: string | null;
      timezone: string | null;
    }>(`${this.base}/api/auth/profile`, body);
  }

  changePassword(body: {
    current_password: string;
    password: string;
    password_confirmation: string;
  }): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/api/auth/password`, body);
  }

  // --- the team -------------------------------------------------------------

  team(): Observable<TeamPage> {
    return this.http.get<TeamPage>(`${this.base}/api/organizer/team`);
  }

  inviteMember(email: string, role: string): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/api/organizer/team/invitations`, { email, role });
  }

  updateMemberRole(userId: string, role: string): Observable<{ message: string }> {
    return this.http.patch<{ message: string }>(`${this.base}/api/organizer/team/members/${userId}`, { role });
  }

  removeMember(userId: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(`${this.base}/api/organizer/team/members/${userId}`);
  }

  revokeInvitation(invitationId: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(`${this.base}/api/organizer/team/invitations/${invitationId}`);
  }

  invitation(token: string): Observable<InvitationDetails> {
    return this.http.get<InvitationDetails>(`${this.base}/api/invitations/${encodeURIComponent(token)}`);
  }

  /** Accept as the signed-in user; the answer is a fresh session including the new membership. */
  acceptInvitation(token: string): Observable<Session & { joined: string }> {
    return this.http.post<Session & { joined: string }>(`${this.base}/api/invitations/${encodeURIComponent(token)}/accept`, {});
  }

  /** What a door link opens, before opening it. */
  doorPassPreview(secret: string): Observable<DoorPassPreview> {
    return this.http.get<DoorPassPreview>(`${this.base}/api/door-passes/${encodeURIComponent(secret)}`);
  }

  /** Open a door link on this phone. Works once. */
  claimDoorPass(secret: string): Observable<DoorPassSession> {
    return this.http.post<DoorPassSession>(`${this.base}/api/door-passes/${encodeURIComponent(secret)}/claim`, {});
  }

  /** Throw a door pass away: the token is deleted, so the link cannot be revived on this phone. */
  endDoorPass(token: string): Observable<unknown> {
    return this.http.post(`${this.base}/api/auth/logout`, {}, { headers: { Authorization: `Bearer ${token}` } });
  }

  doorPasses(eventId: string): Observable<{ data: DoorPass[]; expires_at: string }> {
    return this.http.get<{ data: DoorPass[]; expires_at: string }>(`${this.base}/api/organizer/events/${eventId}/door-passes`);
  }

  issueDoorPass(eventId: string, label: string): Observable<{ data: DoorPass; link: string; qr: string; message: string }> {
    return this.http.post<{ data: DoorPass; link: string; qr: string; message: string }>(`${this.base}/api/organizer/events/${eventId}/door-passes`, { label });
  }

  revokeDoorPass(eventId: string, passId: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(`${this.base}/api/organizer/events/${eventId}/door-passes/${passId}`);
  }

  /** Everything the dashboard shows, in one request. */
  overview(): Observable<Overview> {
    return this.http.get<Overview>(`${this.base}/api/organizer/overview`);
  }

  /**
   * Every order the organization has taken, searchable across its events.
   *
   * Params are dropped when empty rather than sent blank: an empty q would
   * make the server run a LIKE '%%' over every row to no purpose.
   */
  organizationOrders(query: {
    q?: string;
    event_id?: string;
    status?: string;
    page?: number;
  }): Observable<OrganizationOrderPage> {
    let params = new HttpParams();

    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '') {
        params = params.set(key, String(value));
      }
    }

    return this.http.get<OrganizationOrderPage>(`${this.base}/api/organizer/orders`, { params });
  }

  /** The statement: what is owed, by which night, and what has been sent. */
  payouts(): Observable<PayoutStatement> {
    return this.http.get<PayoutStatement>(`${this.base}/api/organizer/payouts`);
  }

  /** Ask to be paid. Amount in minor units, up to what is owed. */
  requestPayout(amount: number, note: string | null): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/api/organizer/payouts/requests`, { amount, note });
  }

  withdrawPayoutRequest(id: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(`${this.base}/api/organizer/payouts/requests/${id}`);
  }

  /**
   * Set where the money goes.
   *
   * Returns the masked view, not what was sent. A save that echoed a full
   * account number back would put one in a response for no reason.
   */
  setPayoutDetails(body: Record<string, unknown>): Observable<PayoutDestination> {
    return this.http.put<PayoutDestination>(`${this.base}/api/organizer/payout-details`, body);
  }

  /** A page of upcoming (soonest first) or past (most recent first) events. */
  events(when: 'upcoming' | 'past', page = 1): Observable<Page<OrganizerEvent>> {
    const params = new HttpParams().set('when', when).set('page', page);

    return this.http.get<Page<OrganizerEvent>>(`${this.base}/api/organizer/events`, { params });
  }

  /** Every event's id and title, for a filter. */
  eventOptions(): Observable<{ data: EventOption[] }> {
    return this.http.get<{ data: EventOption[] }>(`${this.base}/api/organizer/events/options`);
  }

  /** The fixed category list, from the server that enforces it. */
  eventCategories(): Observable<{ data: string[] }> {
    return this.http.get<{ data: string[] }>(`${this.base}/api/event-categories`);
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

  /**
   * Copy an event into a new draft.
   *
   * Sends only a date. What gets copied is the server's decision, not the
   * client's — a caller that chose which parts to carry over would be a second
   * place for that rule to live, and the second copy is the one that goes
   * stale.
   */
  duplicateEvent(id: string, startsAt: string, title?: string): Observable<{ slug: string }> {
    return this.http.post<{ slug: string }>(
      `${this.base}/api/organizer/events/${id}/duplicate`,
      { starts_at: startsAt, ...(title ? { title } : {}) },
    );
  }

  // --- repeating ----------------------------------------------------------

  series(eventId: string): Observable<{ series: Series | null }> {
    return this.http.get<{ series: Series | null }>(
      `${this.base}/api/organizer/events/${eventId}/series`,
    );
  }

  /**
   * Make an event repeat.
   *
   * Sends a plain frequency, not a recurrence rule. The API speaks RFC 5545
   * because that is the interchange format calendars read, but asking an
   * organizer to write "FREQ=WEEKLY;BYDAY=FR" would be exposing a file format
   * as a user interface.
   */
  repeatEvent(
    eventId: string,
    frequency: 'weekly' | 'fortnightly' | 'monthly',
    count?: number,
  ): Observable<{ series: Series; created: number }> {
    return this.http.post<{ series: Series; created: number }>(
      `${this.base}/api/organizer/events/${eventId}/series`,
      { frequency, ...(count ? { count } : {}) },
    );
  }

  skipOccurrence(
    eventId: string,
    occurrenceId: string,
    reason?: string,
  ): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(
      `${this.base}/api/organizer/events/${eventId}/series/skip`,
      { occurrence_id: occurrenceId, ...(reason ? { reason } : {}) },
    );
  }

  stopRepeating(eventId: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(
      `${this.base}/api/organizer/events/${eventId}/series`,
    );
  }

  // --- calling an event off ------------------------------------------------

  /** What cancelling would involve — asked for before the confirmation shows. */
  cancellationPreview(id: string): Observable<CancellationPreview> {
    return this.http.get<CancellationPreview>(
      `${this.base}/api/organizer/events/${id}/cancellation`,
    );
  }

  /**
   * Call it off.
   *
   * The reason is required and reaches ticket holders verbatim. Refunding
   * defaults to true on the server; this passes the organizer's choice
   * explicitly so the request says what was intended rather than relying on a
   * default staying what it is.
   */
  cancelEvent(id: string, reason: string, refund: boolean): Observable<CancellationResult> {
    return this.http.post<CancellationResult>(
      `${this.base}/api/organizer/events/${id}/cancel`,
      { reason, refund },
    );
  }

  publish(id: string, status: 'draft' | 'published'): Observable<{ status: string; followers_told?: number }> {
    return this.http.post<{ status: string; followers_told?: number }>(`${this.base}/api/organizer/events/${id}/publish`, {
      status,
    });
  }

  sales(id: string): Observable<SalesReport> {
    return this.http.get<SalesReport>(`${this.base}/api/organizer/events/${id}/sales`);
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

  /**
   * The order tiers are offered in, as one list.
   *
   * One request rather than a sort_order per tier: swapping a pair with two
   * writes leaves both claiming the same place if the second never lands.
   */
  reorderTicketTypes(eventId: string, ids: string[]): Observable<{ data: TicketType[] }> {
    return this.http.post<{ data: TicketType[] }>(
      `${this.base}/api/organizer/events/${eventId}/ticket-types/order`,
      { ids },
    );
  }

  deleteTicketType(eventId: string, id: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(
      `${this.base}/api/organizer/events/${eventId}/ticket-types/${id}`,
    );
  }

  // --- what the checkout asks ---------------------------------------------

  eventQuestions(eventId: string): Observable<{ data: EventQuestion[] }> {
    return this.http.get<{ data: EventQuestion[] }>(
      `${this.base}/api/organizer/events/${eventId}/questions`,
    );
  }

  createEventQuestion(eventId: string, body: Record<string, unknown>): Observable<{ data: EventQuestion }> {
    return this.http.post<{ data: EventQuestion }>(
      `${this.base}/api/organizer/events/${eventId}/questions`,
      body,
    );
  }

  updateEventQuestion(
    eventId: string,
    id: string,
    body: Record<string, unknown>,
  ): Observable<{ data: EventQuestion }> {
    return this.http.patch<{ data: EventQuestion }>(
      `${this.base}/api/organizer/events/${eventId}/questions/${id}`,
      body,
    );
  }

  deleteEventQuestion(eventId: string, id: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(
      `${this.base}/api/organizer/events/${eventId}/questions/${id}`,
    );
  }

  /** The order they are asked in, as one list — two writes can disagree. */
  reorderEventQuestions(eventId: string, ids: string[]): Observable<{ data: EventQuestion[] }> {
    return this.http.post<{ data: EventQuestion[] }>(
      `${this.base}/api/organizer/events/${eventId}/questions/order`,
      { ids },
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

  /** The guest list as a CSV. Ticket codes are never in it. */
  exportGuests(eventId: string): Observable<Blob> {
    return this.http.get(`${this.base}/api/organizer/events/${eventId}/guests/export`, {
      responseType: 'blob',
    });
  }

  /** Every order the filter matches — not only the page on screen — as a CSV. */
  exportOrders(query: { q?: string; event_id?: string; status?: string }): Observable<Blob> {
    let params = new HttpParams();

    for (const [key, value] of Object.entries(query)) {
      if (value) params = params.set(key, value);
    }

    return this.http.get(`${this.base}/api/organizer/orders/export`, { params, responseType: 'blob' });
  }

  guests(eventId: string, search?: string, page = 1): Observable<GuestPage> {
    let params = new HttpParams().set('page', page);
    if (search) params = params.set('q', search);

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
  /**
   * Reports its progress, because a flyer is not a small file.
   *
   * Club posters come off a phone at three to eight megabytes, and on the
   * upload half of a domestic connection that is ten to thirty seconds of a
   * screen that previously said nothing at all. People conclude it did not
   * work and press the button again, which is how the same flyer ends up in a
   * gallery four times.
   *
   * `observe: 'events'` with `reportProgress` turns one request into a stream:
   * a series of UploadProgress values and then the finished image. The caller
   * decides what to draw.
   */
  // --- how the organization appears ------------------------------------------

  brand(): Observable<Brand> {
    return this.http.get<Brand>(`${this.base}/api/organizer/brand`);
  }

  saveBrand(changes: { name?: string; description?: string | null }): Observable<Brand> {
    return this.http.patch<Brand>(`${this.base}/api/organizer/brand`, changes);
  }

  uploadLogo(file: File): Observable<Brand> {
    const body = new FormData();

    body.append('file', file);

    return this.http.post<Brand>(`${this.base}/api/organizer/brand/logo`, body);
  }

  removeLogo(): Observable<Brand> {
    return this.http.delete<Brand>(`${this.base}/api/organizer/brand/logo`);
  }

  uploadImage(
    eventId: string,
    file: File,
    kind: 'banner' | 'gallery',
    caption?: string,
  ): Observable<UploadProgress | EventImage> {
    const body = new FormData();

    body.append('file', file);
    body.append('kind', kind);

    if (caption) body.append('caption', caption);

    return this.http
      .post<EventImage>(`${this.base}/api/organizer/events/${eventId}/images`, body, {
        reportProgress: true,
        observe: 'events',
      })
      .pipe(
        map((event) => {
          if (event.type === HttpEventType.UploadProgress) {
            return {
              uploading: true as const,
              // `total` is absent on some proxies. Reporting a percentage we
              // cannot compute would be a bar that jumps; the caller shows an
              // indeterminate one instead.
              percent: event.total ? Math.round((event.loaded / event.total) * 100) : null,
            };
          }

          if (event.type === HttpEventType.Response) {
            return event.body as EventImage;
          }

          return { uploading: true as const, percent: null };
        }),
      );
  }

  captionImage(eventId: string, id: string, caption: string | null): Observable<EventImage> {
    return this.http.patch<EventImage>(
      `${this.base}/api/organizer/events/${eventId}/images/${id}`,
      { caption },
    );
  }

  /**
   * Make a gallery picture the banner.
   *
   * One request, not two. The server demotes the sitting banner inside the
   * same transaction — a client doing it in two steps would leave an event
   * with no banner at all if the second never arrived.
   */
  setBanner(eventId: string, id: string): Observable<EventImage> {
    return this.http.patch<EventImage>(
      `${this.base}/api/organizer/events/${eventId}/images/${id}`,
      { kind: 'banner' },
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

  // --- messaging ----------------------------------------------------------

  messages(eventId: string, page = 1): Observable<Page<AttendeeMessage> & { audience: MessageAudience }> {
    return this.http.get<Page<AttendeeMessage> & { audience: MessageAudience }>(
      `${this.base}/api/organizer/events/${eventId}/messages`,
      { params: new HttpParams().set('page', page) },
    );
  }

  sendMessage(
    eventId: string,
    body: { subject: string; body: string; important: boolean },
  ): Observable<{ message: string; data: AttendeeMessage }> {
    return this.http.post<{ message: string; data: AttendeeMessage }>(
      `${this.base}/api/organizer/events/${eventId}/messages`,
      body,
    );
  }

  // --- the door -----------------------------------------------------------

  /**
   * One scan.
   *
   * The party size is how many of a table are going in now; omitting it admits
   * everyone still outstanding, which is right for an ordinary ticket.
   */
  scan(eventId: string, code: string, party?: number, clientId?: string): Observable<ScanResult> {
    return this.http.post<ScanResult>(`${this.base}/api/events/${eventId}/scan`, {
      code,
      ...(party ? { party } : {}),
      // The scan's own id, so a retry after a lost response is recognised
      // rather than refused as a second person on the ticket.
      ...(clientId ? { client_id: clientId } : {}),
    });
  }

  /** The event's tickets as hashes, for deciding at the door with no signal. */
  doorList(eventId: string): Observable<DoorList> {
    return this.http.get<DoorList>(`${this.base}/api/events/${eventId}/door-list`);
  }

  /** Scans made offline, sent once the phone has a connection again. */
  syncScans(
    eventId: string,
    scans: Omit<OfflineScan, 'event_id'>[],
  ): Observable<SyncResult> {
    return this.http.post<SyncResult>(`${this.base}/api/events/${eventId}/scans/sync`, { scans });
  }

  // --- reminders ----------------------------------------------------------

  reminders(eventId: string): Observable<{ data: Reminder[] }> {
    return this.http.get<{ data: Reminder[] }>(
      `${this.base}/api/organizer/events/${eventId}/reminders`,
    );
  }

  addReminder(eventId: string, offsetMinutes: number): Observable<Reminder> {
    return this.http.post<Reminder>(`${this.base}/api/organizer/events/${eventId}/reminders`, {
      offset_minutes: offsetMinutes,
    });
  }

  cancelReminder(eventId: string, id: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(
      `${this.base}/api/organizer/events/${eventId}/reminders/${id}`,
    );
  }

  // --- orders and refunds -------------------------------------------------

  orders(eventId: string, page = 1, search?: string): Observable<Page<SoldOrder>> {
    let params = new HttpParams().set('page', page);
    if (search) params = params.set('q', search);

    return this.http.get<Page<SoldOrder>>(`${this.base}/api/organizer/events/${eventId}/orders`, { params });
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

  /** Every code in the organization. event_id is an event, or 'all-events' for the ones made for every event. */
  organizationCodes(filters: { page?: number; eventId?: string | null; q?: string }): Observable<Page<OrganizationCode>> {
    let params = new HttpParams().set('page', filters.page ?? 1);
    if (filters.eventId) params = params.set('event_id', filters.eventId);
    if (filters.q?.trim()) params = params.set('q', filters.q.trim());

    return this.http.get<Page<OrganizationCode>>(`${this.base}/api/organizer/codes`, { params });
  }

  codes(eventId: string, page = 1): Observable<Page<PromoCode>> {
    return this.http.get<Page<PromoCode>>(`${this.base}/api/organizer/events/${eventId}/codes`, {
      params: new HttpParams().set('page', page),
    });
  }

  waitlist(eventId: string): Observable<WaitlistPage> {
    return this.http.get<WaitlistPage>(`${this.base}/api/organizer/events/${eventId}/waitlist`);
  }

  notifyWaitlist(eventId: string, limit: number, note: string | null): Observable<{ message: string; told: number }> {
    return this.http.post<{ message: string; told: number }>(`${this.base}/api/organizer/events/${eventId}/waitlist/notify`, { limit, note });
  }

  codeBatches(eventId: string): Observable<{ data: CodeBatch[] }> {
    return this.http.get<{ data: CodeBatch[] }>(`${this.base}/api/organizer/events/${eventId}/code-batches`);
  }

  createCodeBatch(eventId: string, body: Record<string, unknown>): Observable<CodeBatch> {
    return this.http.post<CodeBatch>(`${this.base}/api/organizer/events/${eventId}/code-batches`, body);
  }

  /** Every code in a batch, as the spreadsheet to hand them out from. */
  exportCodeBatch(eventId: string, batchId: string): Observable<Blob> {
    return this.http.get(`${this.base}/api/organizer/events/${eventId}/code-batches/${batchId}/export`, { responseType: 'blob' });
  }

  deactivateCodeBatch(eventId: string, batchId: string): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/api/organizer/events/${eventId}/code-batches/${batchId}/deactivate`, {});
  }

  createCode(eventId: string, body: Record<string, unknown>): Observable<PromoCode> {
    return this.http.post<PromoCode>(`${this.base}/api/organizer/events/${eventId}/codes`, body);
  }

  /**
   * Change a code that is already out there.
   *
   * Partial by design — only what changed is sent, so an edit to the end date
   * cannot accidentally clear a promoter's name it never carried.
   */
  updateCode(
    eventId: string,
    id: string,
    body: Record<string, unknown>,
  ): Observable<PromoCode> {
    return this.http.patch<PromoCode>(
      `${this.base}/api/organizer/events/${eventId}/codes/${id}`,
      body,
    );
  }

  deactivateCode(eventId: string, id: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(
      `${this.base}/api/organizer/events/${eventId}/codes/${id}`,
    );
  }
}
