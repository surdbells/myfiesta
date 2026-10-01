import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import type { Observable } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import type { PayLaterSetting } from '../../core/api.types';

/**
 * The calls behind letting buyers pay for a night later, with Klarna or
 * Affirm on Stripe's page.
 *
 * Here rather than in core/api.ts, which the features added since leave
 * alone (the Api class says why). The event as a whole is read again through
 * Api.event after a change, so the page and its header agree.
 */
@Injectable({ providedIn: 'root' })
export class PayLaterApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  /** The opt-in as it stands, with what each lender charges beside a card. */
  setting(eventId: string): Observable<PayLaterSetting> {
    return this.http.get<PayLaterSetting>(`${this.base}/api/organizer/events/${eventId}/pay-later`);
  }

  /** Turn it on or off. Turning it on is refused where myFiesta does not offer it. */
  set(eventId: string, enabled: boolean): Observable<PayLaterSetting> {
    return this.http.put<PayLaterSetting>(`${this.base}/api/organizer/events/${eventId}/pay-later`, { enabled });
  }
}
