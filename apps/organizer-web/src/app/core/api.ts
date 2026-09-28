import {
  HttpClient,
  HttpContext,
  HttpContextToken,
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
  Campaign,
  CampaignAudience,
  CampaignDraft,
  CampaignFilters,
  CampaignPage,
  ApiKeySummary,
  Integrations,
  WebhookDelivery,
  WebhookEndpoint,
  WebhookEventName,
  CancellationPreview,
  CancellationResult,
  DoorList,
  EventSummary,
  EventReviewResult,
  SalesReport,
  OfflineScan,
  SyncResult,
  MessageAudience,
  GuestPage,
  IssueResult,
  EventImage,
  EventImages,
  EventOption,
  AddOn,
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
  OrganizationStanding,
  Account,
  AccountErasurePreview,
  AccountErasureResult,
  TermsStanding,
  SavedView,
  SavedViewList,
  SavedViewValue,
} from './api.types';
import { EmailVerification } from './email-verification';
import { isEmailUnverified } from './errors';
import { SessionStore, type StaffSession } from './session';
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
 * A request that is its own credential and must carry nobody's session: the
 * staff handoff, which arrives in a tab where somebody's own sign-in may be
 * sitting in storage and must not ride along.
 */
export const ANONYMOUS = new HttpContextToken<boolean>(() => false);

/**
 * Attaches the token, and reacts when the server stops accepting it.
 *
 * A 401 means the token is gone or expired — the session is cleared rather
 * than kept, because a console that looks signed in and fails every action is
 * worse than one that asks you to sign in again.
 *
 * A 403 is left alone. That is the server saying this account may not do this
 * particular thing, which is information, not a broken session. The one it
 * also reports is `email_unverified`, which the shell answers with a prompt
 * (EmailVerification) instead of each screen saying it in its own words.
 *
 * A request marked ANONYMOUS is passed through untouched.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  if (req.context.get(ANONYMOUS)) return next(req);

  const session = inject(SessionStore);
  const router = inject(Router);
  const base = inject(API_BASE_URL);
  const doorPass = inject(DoorPassStore);
  const verification = inject(EmailVerification);

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
        // A staff session that stopped working has run out or been ended:
        // say so, rather than offer a sign-in form in a tab that was never
        // anybody's own.
        const wasStaff = session.impersonation() !== null;

        session.clear();
        void router.navigate(wasStaff ? ['/impersonate/ended'] : ['/sign-in']);
      }

      // The one 403 with something to do about it: the address has to be
      // proved first. Every screen that can meet it — publishing, asking to
      // be paid, changing where payouts go — gets the same prompt, with the
      // button that sends the link again, rather than its own red sentence.
      if (isEmailUnverified(error)) {
        verification.refused(typeof error.error?.message === 'string' ? error.error.message : null);
      }

      return throwError(() => error);
    }),
  );
};

/**
 * The signed-in person, as GET /api/auth/me has them: what the account page
 * edits, their memberships, and whether their address is proved. Written down
 * once, in @myfiesta/api-types, for the phone to read the same way.
 */
export type { Account } from './api.types';

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

  // --- myFiesta staff acting as an organization ---------------------------

  /**
   * The one-minute code from the admin panel's link, for the staff session.
   *
   * The code is the whole credential. Marked ANONYMOUS, so whatever sign-in
   * this browser holds for somebody — the staff member's own, or an
   * organizer's on a shared machine — is not sent with it.
   */
  exchangeImpersonation(code: string): Observable<StaffSession> {
    return this.http.post<StaffSession>(
      `${this.base}/api/impersonation/exchange`,
      { code },
      { context: new HttpContext().set(ANONYMOUS, true) },
    );
  }

  /** End the staff session: the token stops working at once. */
  endImpersonation(): Observable<unknown> {
    return this.http.delete(`${this.base}/api/impersonation`);
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
    /** The terms, privacy and refund policies, agreed to. Refused without it. */
    accept_terms: boolean;
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

  /**
   * Who is signed in, as the server has them now.
   *
   * The session holds what signing in said, which goes stale when the address
   * is changed from a link opened on another device.
   */
  me(): Observable<Account> {
    return this.http.get<Account>(`${this.base}/api/auth/me`);
  }

  /**
   * A fresh link to prove the address the account already has. 202 when one
   * went, 200 with `verified` when there was nothing to prove, and 429 with
   * the server's own sentence about how long to wait.
   */
  resendVerification(): Observable<{ message: string; verified: boolean }> {
    return this.http.post<{ message: string; verified: boolean }>(`${this.base}/api/auth/email/verification`, {});
  }

  /** Whether this account has agreed to the terms in force now. */
  terms(): Observable<TermsStanding> {
    return this.http.get<TermsStanding>(`${this.base}/api/auth/terms`);
  }

  /** The box, ticked: kept on the account with the version and the moment. */
  acceptTerms(): Observable<TermsStanding> {
    return this.http.post<TermsStanding>(`${this.base}/api/auth/terms`, { accept_terms: true });
  }

  /** What deleting this account would do, and what would stop it. Changes nothing. */
  erasurePreview(): Observable<AccountErasurePreview> {
    return this.http.get<AccountErasurePreview>(`${this.base}/api/auth/erasure`);
  }

  /**
   * Delete this account: the privacy page's erasure, on the password. A 409
   * is the only owner of an organization being told nothing happened.
   */
  eraseAccount(currentPassword: string): Observable<AccountErasureResult> {
    return this.http.post<AccountErasureResult>(`${this.base}/api/auth/erasure`, { current_password: currentPassword });
  }

  /**
   * Ask to move the account to a new address. Nothing changes until the link
   * sent there is opened, and the answer is the same whether or not that
   * address already has an account — so this does not say either.
   */
  requestEmailChange(body: { email: string; current_password: string }): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/api/auth/email`, body);
  }

  /** From the emailed link. Works once; every other device is signed out. */
  confirmEmailChange(token: string): Observable<{ message: string; email: string }> {
    return this.http.post<{ message: string; email: string }>(`${this.base}/api/auth/email/confirm`, { token });
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

  /** Whether myFiesta is selling for the organization being worked in: the suspension banner. */
  standing(): Observable<OrganizationStanding> {
    return this.http.get<OrganizationStanding>(`${this.base}/api/organizer/standing`);
  }

  /**
   * Every order the organization has taken, searchable across its events.
   *
   * Params are dropped when empty rather than sent blank: an empty q would
   * make the server run a LIKE '%%' over every row to no purpose.
   */
  organizationOrders(query: ListQuery): Observable<OrganizationOrderPage> {
    return this.http.get<OrganizationOrderPage>(`${this.base}/api/organizer/orders`, { params: listParams(query) });
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

  /** Take off sale (`draft`), or — kept for older callers — send for review (`published`). */
  publish(id: string, status: 'draft' | 'published'): Observable<EventReviewResult> {
    return this.http.post<EventReviewResult>(`${this.base}/api/organizer/events/${id}/publish`, {
      status,
    });
  }

  /**
   * Send a draft to myFiesta to be looked at, or straight back on sale when
   * nothing has changed since it was approved. The answer says which.
   */
  submitForReview(id: string): Observable<EventReviewResult> {
    return this.http.post<EventReviewResult>(`${this.base}/api/organizer/events/${id}/submit`, {});
  }

  /** Take an event back from review, to change something. */
  withdrawFromReview(id: string): Observable<EventReviewResult> {
    return this.http.post<EventReviewResult>(`${this.base}/api/organizer/events/${id}/withdraw`, {});
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

  // --- what is sold beside a ticket ---------------------------------------

  addOns(eventId: string): Observable<{ data: AddOn[] }> {
    return this.http.get<{ data: AddOn[] }>(`${this.base}/api/organizer/events/${eventId}/add-ons`);
  }

  createAddOn(eventId: string, body: Record<string, unknown>): Observable<{ data: AddOn }> {
    return this.http.post<{ data: AddOn }>(`${this.base}/api/organizer/events/${eventId}/add-ons`, body);
  }

  updateAddOn(eventId: string, id: string, body: Record<string, unknown>): Observable<{ data: AddOn }> {
    return this.http.patch<{ data: AddOn }>(`${this.base}/api/organizer/events/${eventId}/add-ons/${id}`, body);
  }

  deleteAddOn(eventId: string, id: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(`${this.base}/api/organizer/events/${eventId}/add-ons/${id}`);
  }

  /** The order they are offered in, as one list — two writes can disagree. */
  reorderAddOns(eventId: string, ids: string[]): Observable<{ data: AddOn[] }> {
    return this.http.post<{ data: AddOn[] }>(`${this.base}/api/organizer/events/${eventId}/add-ons/order`, { ids });
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
  /** The guest list as a CSV: the list's filters and order, or only `ids`. */
  exportGuests(eventId: string, query: ListQuery = {}): Observable<Blob> {
    return this.http.get(`${this.base}/api/organizer/events/${eventId}/guests/export`, {
      params: listParams(query),
      responseType: 'blob',
    });
  }

  /** The views this person keeps of one list, for the current organization. */
  savedViews(list: SavedViewList): Observable<{ data: SavedView[] }> {
    return this.http.get<{ data: SavedView[] }>(`${this.base}/api/organizer/saved-views`, {
      params: new HttpParams().set('list', list),
    });
  }

  /** Keep a view; saving a name that exists replaces it. */
  saveView(list: SavedViewList, name: string, state: Record<string, SavedViewValue>): Observable<{ data: SavedView }> {
    return this.http.post<{ data: SavedView }>(`${this.base}/api/organizer/saved-views`, { list, name, state });
  }

  deleteSavedView(id: string): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/organizer/saved-views/${encodeURIComponent(id)}`);
  }

  /**
   * Every order the filter matches — not only the page on screen — as a CSV,
   * in the list's order. With `ids`, only those orders.
   */
  exportOrders(query: ListQuery): Observable<Blob> {
    return this.http.get(`${this.base}/api/organizer/orders/export`, { params: listParams(query), responseType: 'blob' });
  }

  /** Who is coming: search, arrived or not, tiers and sort as ListState asks. */
  guests(eventId: string, query: ListQuery = {}): Observable<GuestPage> {
    return this.http.get<GuestPage>(`${this.base}/api/organizer/events/${eventId}/guests`, { params: listParams(query) });
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

  // --- campaigns ---------------------------------------------------------

  campaigns(page = 1, filters: CampaignFilters = {}): Observable<CampaignPage> {
    let params = new HttpParams().set('page', page);

    if (filters.status) params = params.set('status', filters.status);
    if (filters.audience) params = params.set('audience', filters.audience);
    if (filters.event_id) params = params.set('event_id', filters.event_id);
    if (filters.q) params = params.set('q', filters.q);

    return this.http.get<CampaignPage>(`${this.base}/api/organizer/campaigns`, { params });
  }

  /** How many are on a list, and how many would be written to now. */
  campaignAudience(audience: CampaignAudience, eventId: string | null): Observable<{ all: number; reachable: number }> {
    return this.http.post<{ all: number; reachable: number }>(`${this.base}/api/organizer/campaigns/audience`, {
      audience,
      event_id: eventId,
    });
  }

  saveCampaign(draft: CampaignDraft, id: string | null): Observable<{ message?: string; data: Campaign }> {
    return id
      ? this.http.put<{ message?: string; data: Campaign }>(`${this.base}/api/organizer/campaigns/${id}`, draft)
      : this.http.post<{ message?: string; data: Campaign }>(`${this.base}/api/organizer/campaigns`, draft);
  }

  cancelCampaign(id: string): Observable<{ data: Campaign }> {
    return this.http.post<{ data: Campaign }>(`${this.base}/api/organizer/campaigns/${id}/cancel`, {});
  }

  // --- other systems -------------------------------------------------------

  integrations(): Observable<Integrations> {
    return this.http.get<Integrations>(`${this.base}/api/organizer/integrations`);
  }

  /** The secret comes back here and nowhere else. */
  addWebhook(body: { url: string; events: WebhookEventName[]; description: string | null }): Observable<{ data: WebhookEndpoint; secret: string }> {
    return this.http.post<{ data: WebhookEndpoint; secret: string }>(`${this.base}/api/organizer/integrations/webhooks`, body);
  }

  updateWebhook(id: string, changes: { events?: WebhookEventName[]; description?: string | null; enabled?: boolean }): Observable<WebhookEndpoint> {
    return this.http
      .patch<{ data: WebhookEndpoint }>(`${this.base}/api/organizer/integrations/webhooks/${id}`, changes)
      .pipe(map((response) => response.data));
  }

  removeWebhook(id: string): Observable<unknown> {
    return this.http.delete(`${this.base}/api/organizer/integrations/webhooks/${id}`);
  }

  testWebhook(id: string): Observable<WebhookDelivery> {
    return this.http
      .post<{ data: WebhookDelivery }>(`${this.base}/api/organizer/integrations/webhooks/${id}/test`, {})
      .pipe(map((response) => response.data));
  }

  webhookDeliveries(id: string): Observable<WebhookDelivery[]> {
    return this.http
      .get<{ data: WebhookDelivery[] }>(`${this.base}/api/organizer/integrations/webhooks/${id}/deliveries`)
      .pipe(map((response) => response.data));
  }

  /** The key comes back here and nowhere else. */
  createApiKey(name: string): Observable<{ data: ApiKeySummary; key: string }> {
    return this.http.post<{ data: ApiKeySummary; key: string }>(`${this.base}/api/organizer/integrations/keys`, { name });
  }

  revokeApiKey(id: string): Observable<unknown> {
    return this.http.delete(`${this.base}/api/organizer/integrations/keys/${id}`);
  }

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
   * The party size is how many of a table are going in now. Omitting it admits
   * the one place left, which is right for an ordinary ticket; with more than
   * one left it admits nobody and the answer is `choose_party`.
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

  /** An event's orders, with what is left to refund on each: search, status and sort as ListState asks. */
  orders(eventId: string, query: ListQuery = {}): Observable<Page<SoldOrder>> {
    return this.http.get<Page<SoldOrder>>(`${this.base}/api/organizer/events/${eventId}/orders`, { params: listParams(query) });
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
  organizationCodes(query: ListQuery): Observable<Page<OrganizationCode>> {
    return this.http.get<Page<OrganizationCode>>(`${this.base}/api/organizer/codes`, { params: listParams(query) });
  }

  /**
   * Turn several codes off, or back on. Codes on an event waiting for review
   * are left as they are and named in `skipped`.
   */
  setCodesActive(ids: readonly string[], active: boolean): Observable<{ changed: number; skipped: { id: string; code: string; reason: string }[] }> {
    return this.http.post<{ changed: number; skipped: { id: string; code: string; reason: string }[] }>(`${this.base}/api/organizer/codes/active`, {
      ids,
      active,
    });
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

/**
 * What a list asks for: each filter that is on, the sort and the page, as
 * ListState.query() produces it. Arrays are several values of one filter.
 */
export type ListQuery = Readonly<Record<string, string | number | readonly string[] | null | undefined>>;

/**
 * A list's query as the API reads it.
 *
 * Several values of one filter go as `status[]=paid&status[]=refunded`, which
 * is how Laravel reads an array (App\Support\Listing). Empty values are left
 * out rather than sent blank: an empty q would make the server run a
 * LIKE '%%' over every row to no purpose.
 */
export function listParams(query: ListQuery): HttpParams {
  let params = new HttpParams();

  for (const [key, value] of Object.entries(query)) {
    if (value === null || value === undefined || value === '') continue;

    if (Array.isArray(value)) {
      for (const item of value) params = params.append(`${key}[]`, String(item));
    } else {
      params = params.set(key, String(value));
    }
  }

  return params;
}
