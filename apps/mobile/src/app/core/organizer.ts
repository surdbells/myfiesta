import { Injectable, inject } from '@angular/core';
import type {
  AddOn,
  ApiKeySummary,
  AttendeeMessage,
  Brand,
  Campaign,
  CampaignAudience,
  CampaignDraft,
  CampaignFilters,
  CampaignPage,
  CancellationPreview,
  CancellationResult,
  CodeBatch,
  DoorPass as IssuedDoorPass,
  EventImage,
  EventImages,
  EventOption,
  EventQuestion,
  EventSummary,
  GuestPage,
  Integrations,
  IssueResult,
  MessageAudience,
  OrganizationCode,
  OrganizationOrderPage,
  OrganizationStanding,
  OrganizerEvent,
  OrganizerEventDetail,
  Overview,
  Page,
  PayoutDestination,
  PayoutStatement,
  PromoCode,
  RefundResult,
  Reminder,
  SalesReport,
  Series,
  SoldOrder,
  TeamPage,
  TicketType,
  WaitlistPage,
  WebhookDelivery,
  WebhookEndpoint,
  WebhookEventName,
} from '@myfiesta/api-types';
import { Api } from './api';

type Body = Record<string, unknown>;

/** Only the parts of a query that say something. */
function query(values: Record<string, string | number | null | undefined>): Record<string, string> {
  const out: Record<string, string> = {};

  for (const [key, value] of Object.entries(values)) {
    if (value !== undefined && value !== null && value !== '') out[key] = String(value);
  }

  return out;
}

/**
 * Everything an organizer can do, from a phone.
 *
 * The same endpoints the console calls, with the same shapes — both apps type
 * them from @myfiesta/api-types — so a thing done on the phone is the thing
 * done at a desk, authorised by the same policies. Promises rather than
 * observables, like the rest of this app: a phone screen asks, waits, and
 * shows the answer.
 */
@Injectable({ providedIn: 'root' })
export class Organizer {
  private readonly api = inject(Api);

  private get<T>(path: string, q?: Record<string, string>): Promise<T> {
    return this.api.request<T>('GET', `/api/organizer${path}`, undefined, q);
  }

  private post<T>(path: string, body: unknown = {}): Promise<T> {
    return this.api.request<T>('POST', `/api/organizer${path}`, body);
  }

  private patch<T>(path: string, body: unknown): Promise<T> {
    return this.api.request<T>('PATCH', `/api/organizer${path}`, body);
  }

  private put<T>(path: string, body: unknown): Promise<T> {
    return this.api.request<T>('PUT', `/api/organizer${path}`, body);
  }

  private del<T>(path: string): Promise<T> {
    return this.api.request<T>('DELETE', `/api/organizer${path}`);
  }

  // --- the organization -------------------------------------------------------

  overview(): Promise<Overview> {
    return this.get('/overview');
  }

  /** Whether myFiesta is selling for the organization: the suspension strip's question. */
  standing(): Promise<OrganizationStanding> {
    return this.get('/standing');
  }

  /** from and to are days in timezone; without one the server reads Greenwich days. */
  orders(filters: { q?: string; event_id?: string; status?: string; from?: string; to?: string; timezone?: string; page?: number }): Promise<OrganizationOrderPage> {
    return this.get('/orders', query(filters));
  }

  exportOrders(filters: { q?: string; event_id?: string; status?: string; from?: string; to?: string; timezone?: string }): Promise<string> {
    return this.api.download('/api/organizer/orders/export', query(filters));
  }

  payouts(): Promise<PayoutStatement> {
    return this.get('/payouts');
  }

  requestPayout(amount: number, note: string | null): Promise<{ message: string }> {
    return this.post('/payouts/requests', { amount, note });
  }

  withdrawPayoutRequest(id: string): Promise<{ message: string }> {
    return this.del(`/payouts/requests/${id}`);
  }

  setPayoutDetails(body: Body): Promise<PayoutDestination> {
    return this.put('/payout-details', body);
  }

  team(): Promise<TeamPage> {
    return this.get('/team');
  }

  invite(email: string, role: string): Promise<{ message: string }> {
    return this.post('/team/invitations', { email, role });
  }

  changeRole(userId: string, role: string): Promise<{ message: string }> {
    return this.patch(`/team/members/${userId}`, { role });
  }

  removeMember(userId: string): Promise<{ message: string }> {
    return this.del(`/team/members/${userId}`);
  }

  revokeInvitation(id: string): Promise<{ message: string }> {
    return this.del(`/team/invitations/${id}`);
  }

  brand(): Promise<Brand> {
    return this.get('/brand');
  }

  saveBrand(changes: { name?: string; description?: string | null }): Promise<Brand> {
    return this.patch('/brand', changes);
  }

  uploadLogo(file: File, progress?: (percent: number | null) => void): Promise<Brand> {
    return this.api.upload('/api/organizer/brand/logo', { file }, progress);
  }

  removeLogo(): Promise<Brand> {
    return this.del('/brand/logo');
  }

  codes(filters: { page?: number; event_id?: string | null; q?: string }): Promise<Page<OrganizationCode>> {
    return this.get('/codes', query({ page: filters.page ?? 1, event_id: filters.event_id, q: filters.q?.trim() }));
  }

  campaigns(page = 1, filters: CampaignFilters = {}): Promise<CampaignPage> {
    return this.get('/campaigns', query({ page, ...filters }));
  }

  campaignAudience(audience: CampaignAudience, eventId: string | null): Promise<{ all: number; reachable: number }> {
    return this.post('/campaigns/audience', { audience, event_id: eventId });
  }

  saveCampaign(draft: CampaignDraft, id: string | null): Promise<{ message?: string; data: Campaign }> {
    return id ? this.put(`/campaigns/${id}`, draft) : this.post('/campaigns', draft);
  }

  cancelCampaign(id: string): Promise<{ data: Campaign }> {
    return this.post(`/campaigns/${id}/cancel`);
  }

  integrations(): Promise<Integrations> {
    return this.get('/integrations');
  }

  addWebhook(body: { url: string; events: WebhookEventName[]; description: string | null }): Promise<{ data: WebhookEndpoint; secret: string }> {
    return this.post('/integrations/webhooks', body);
  }

  async updateWebhook(id: string, changes: { events?: WebhookEventName[]; description?: string | null; enabled?: boolean }): Promise<WebhookEndpoint> {
    return (await this.patch<{ data: WebhookEndpoint }>(`/integrations/webhooks/${id}`, changes)).data;
  }

  removeWebhook(id: string): Promise<unknown> {
    return this.del(`/integrations/webhooks/${id}`);
  }

  async testWebhook(id: string): Promise<WebhookDelivery> {
    return (await this.post<{ data: WebhookDelivery }>(`/integrations/webhooks/${id}/test`)).data;
  }

  async webhookDeliveries(id: string): Promise<WebhookDelivery[]> {
    return (await this.get<{ data: WebhookDelivery[] }>(`/integrations/webhooks/${id}/deliveries`)).data;
  }

  createApiKey(name: string): Promise<{ data: ApiKeySummary; key: string }> {
    return this.post('/integrations/keys', { name });
  }

  revokeApiKey(id: string): Promise<unknown> {
    return this.del(`/integrations/keys/${id}`);
  }

  // --- events -----------------------------------------------------------------

  events(when: 'upcoming' | 'past', page = 1): Promise<Page<OrganizerEvent>> {
    return this.get('/events', query({ when, page }));
  }

  async eventOptions(): Promise<EventOption[]> {
    return (await this.get<{ data: EventOption[] }>('/events/options')).data;
  }

  async categories(): Promise<string[]> {
    return (await this.api.request<{ data: string[] }>('GET', '/api/event-categories')).data;
  }

  event(id: string): Promise<OrganizerEventDetail> {
    return this.get(`/events/${id}`);
  }

  createEvent(body: Body): Promise<{ id?: string; data?: { id: string } } & Body> {
    return this.post('/events', body);
  }

  updateEvent(id: string, body: Body): Promise<unknown> {
    return this.patch(`/events/${id}`, body);
  }

  duplicate(id: string, startsAt: string, title?: string): Promise<{ slug: string; id?: string }> {
    return this.post(`/events/${id}/duplicate`, { starts_at: startsAt, ...(title ? { title } : {}) });
  }

  publish(id: string, status: 'draft' | 'published'): Promise<{ status: string; followers_told?: number }> {
    return this.post(`/events/${id}/publish`, { status });
  }

  cancellationPreview(id: string): Promise<CancellationPreview> {
    return this.get(`/events/${id}/cancellation`);
  }

  cancel(id: string, reason: string, refund: boolean): Promise<CancellationResult> {
    return this.post(`/events/${id}/cancel`, { reason, refund });
  }

  summary(id: string): Promise<EventSummary> {
    return this.get(`/events/${id}/summary`);
  }

  sales(id: string): Promise<SalesReport> {
    return this.get(`/events/${id}/sales`);
  }

  series(eventId: string): Promise<{ series: Series | null }> {
    return this.get(`/events/${eventId}/series`);
  }

  repeat(eventId: string, frequency: 'weekly' | 'fortnightly' | 'monthly', count?: number): Promise<{ series: Series; created: number }> {
    return this.post(`/events/${eventId}/series`, { frequency, ...(count ? { count } : {}) });
  }

  skipOccurrence(eventId: string, occurrenceId: string, reason?: string): Promise<{ message: string }> {
    return this.post(`/events/${eventId}/series/skip`, { occurrence_id: occurrenceId, ...(reason ? { reason } : {}) });
  }

  stopRepeating(eventId: string): Promise<{ message: string }> {
    return this.del(`/events/${eventId}/series`);
  }

  // --- what an event sells ------------------------------------------------------

  async ticketTypes(eventId: string): Promise<TicketType[]> {
    return (await this.get<{ data: TicketType[] }>(`/events/${eventId}/ticket-types`)).data;
  }

  createTicketType(eventId: string, body: Body): Promise<TicketType> {
    return this.post(`/events/${eventId}/ticket-types`, body);
  }

  updateTicketType(eventId: string, id: string, body: Body): Promise<TicketType> {
    return this.patch(`/events/${eventId}/ticket-types/${id}`, body);
  }

  async reorderTicketTypes(eventId: string, ids: string[]): Promise<TicketType[]> {
    return (await this.post<{ data: TicketType[] }>(`/events/${eventId}/ticket-types/order`, { ids })).data;
  }

  deleteTicketType(eventId: string, id: string): Promise<{ message: string }> {
    return this.del(`/events/${eventId}/ticket-types/${id}`);
  }

  async addOns(eventId: string): Promise<AddOn[]> {
    return (await this.get<{ data: AddOn[] }>(`/events/${eventId}/add-ons`)).data;
  }

  async createAddOn(eventId: string, body: Body): Promise<AddOn> {
    return (await this.post<{ data: AddOn }>(`/events/${eventId}/add-ons`, body)).data;
  }

  async updateAddOn(eventId: string, id: string, body: Body): Promise<AddOn> {
    return (await this.patch<{ data: AddOn }>(`/events/${eventId}/add-ons/${id}`, body)).data;
  }

  deleteAddOn(eventId: string, id: string): Promise<{ message: string }> {
    return this.del(`/events/${eventId}/add-ons/${id}`);
  }

  async reorderAddOns(eventId: string, ids: string[]): Promise<AddOn[]> {
    return (await this.post<{ data: AddOn[] }>(`/events/${eventId}/add-ons/order`, { ids })).data;
  }

  async questions(eventId: string): Promise<EventQuestion[]> {
    return (await this.get<{ data: EventQuestion[] }>(`/events/${eventId}/questions`)).data;
  }

  async createQuestion(eventId: string, body: Body): Promise<EventQuestion> {
    return (await this.post<{ data: EventQuestion }>(`/events/${eventId}/questions`, body)).data;
  }

  async updateQuestion(eventId: string, id: string, body: Body): Promise<EventQuestion> {
    return (await this.patch<{ data: EventQuestion }>(`/events/${eventId}/questions/${id}`, body)).data;
  }

  deleteQuestion(eventId: string, id: string): Promise<{ message: string }> {
    return this.del(`/events/${eventId}/questions/${id}`);
  }

  async reorderQuestions(eventId: string, ids: string[]): Promise<EventQuestion[]> {
    return (await this.post<{ data: EventQuestion[] }>(`/events/${eventId}/questions/order`, { ids })).data;
  }

  eventCodes(eventId: string, page = 1): Promise<Page<PromoCode>> {
    return this.get(`/events/${eventId}/codes`, query({ page }));
  }

  createCode(eventId: string, body: Body): Promise<PromoCode> {
    return this.post(`/events/${eventId}/codes`, body);
  }

  updateCode(eventId: string, id: string, body: Body): Promise<PromoCode> {
    return this.patch(`/events/${eventId}/codes/${id}`, body);
  }

  deactivateCode(eventId: string, id: string): Promise<{ message: string }> {
    return this.del(`/events/${eventId}/codes/${id}`);
  }

  async codeBatches(eventId: string): Promise<CodeBatch[]> {
    return (await this.get<{ data: CodeBatch[] }>(`/events/${eventId}/code-batches`)).data;
  }

  createCodeBatch(eventId: string, body: Body): Promise<CodeBatch> {
    return this.post(`/events/${eventId}/code-batches`, body);
  }

  exportCodeBatch(eventId: string, batchId: string): Promise<string> {
    return this.api.download(`/api/organizer/events/${eventId}/code-batches/${batchId}/export`);
  }

  deactivateCodeBatch(eventId: string, batchId: string): Promise<{ message: string }> {
    return this.post(`/events/${eventId}/code-batches/${batchId}/deactivate`);
  }

  // --- the people ---------------------------------------------------------------

  guests(eventId: string, search = '', page = 1, filters: { status?: string; ticket_type_id?: string } = {}): Promise<GuestPage> {
    return this.get(`/events/${eventId}/guests`, query({ page, q: search.trim(), ...filters }));
  }

  exportGuests(eventId: string): Promise<string> {
    return this.api.download(`/api/organizer/events/${eventId}/guests/export`);
  }

  issueTicket(eventId: string, body: Body): Promise<IssueResult> {
    return this.post(`/events/${eventId}/tickets`, body);
  }

  eventOrders(eventId: string, page = 1, search = ''): Promise<Page<SoldOrder>> {
    return this.get(`/events/${eventId}/orders`, query({ page, q: search.trim() }));
  }

  refund(eventId: string, orderId: string, body: { ticket_ids?: string[]; reason?: string | null }): Promise<RefundResult> {
    return this.post(`/events/${eventId}/orders/${orderId}/refunds`, body);
  }

  messages(eventId: string, page = 1): Promise<Page<AttendeeMessage> & { audience: MessageAudience }> {
    return this.get(`/events/${eventId}/messages`, query({ page }));
  }

  sendMessage(eventId: string, body: { subject: string; body: string; important: boolean }): Promise<{ message: string; data: AttendeeMessage }> {
    return this.post(`/events/${eventId}/messages`, body);
  }

  waitlist(eventId: string): Promise<WaitlistPage> {
    return this.get(`/events/${eventId}/waitlist`);
  }

  notifyWaitlist(eventId: string, limit: number, note: string | null): Promise<{ message: string; told: number }> {
    return this.post(`/events/${eventId}/waitlist/notify`, { limit, note });
  }

  async reminders(eventId: string): Promise<Reminder[]> {
    return (await this.get<{ data: Reminder[] }>(`/events/${eventId}/reminders`)).data;
  }

  addReminder(eventId: string, offsetMinutes: number): Promise<Reminder> {
    return this.post(`/events/${eventId}/reminders`, { offset_minutes: offsetMinutes });
  }

  cancelReminder(eventId: string, id: string): Promise<{ message: string }> {
    return this.del(`/events/${eventId}/reminders/${id}`);
  }

  // --- the door -----------------------------------------------------------------

  doorPasses(eventId: string): Promise<{ data: IssuedDoorPass[]; expires_at: string }> {
    return this.get(`/events/${eventId}/door-passes`);
  }

  issueDoorPass(eventId: string, label: string): Promise<{ data: IssuedDoorPass; link: string; qr: string; message: string }> {
    return this.post(`/events/${eventId}/door-passes`, { label });
  }

  revokeDoorPass(eventId: string, passId: string): Promise<{ message: string }> {
    return this.del(`/events/${eventId}/door-passes/${passId}`);
  }

  // --- pictures -----------------------------------------------------------------

  images(eventId: string): Promise<EventImages> {
    return this.get(`/events/${eventId}/images`);
  }

  uploadImage(eventId: string, file: File, kind: 'banner' | 'gallery', progress?: (percent: number | null) => void): Promise<EventImage> {
    return this.api.upload(`/api/organizer/events/${eventId}/images`, { file, kind }, progress);
  }

  captionImage(eventId: string, id: string, caption: string | null): Promise<EventImage> {
    return this.patch(`/events/${eventId}/images/${id}`, { caption });
  }

  setBanner(eventId: string, id: string): Promise<EventImage> {
    return this.patch(`/events/${eventId}/images/${id}`, { kind: 'banner' });
  }

  deleteImage(eventId: string, id: string): Promise<{ message: string }> {
    return this.del(`/events/${eventId}/images/${id}`);
  }

  reorderImages(eventId: string, ids: string[]): Promise<{ gallery: EventImage[] }> {
    return this.post(`/events/${eventId}/images/order`, { ids });
  }
}
