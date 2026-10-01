import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import type { Observable } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import type { OrganizerShareOffer } from '../../core/api.types';

/**
 * The call behind a night's friend discount ("friend buys, both save").
 *
 * Here rather than in core/api.ts, which the features added since leave
 * alone (the Api class says why). The event as a whole is read again through
 * Api.event afterwards, so the Overview and its header agree.
 */
@Injectable({ providedIn: 'root' })
export class ShareApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  /**
   * Start, change or end the offer. Basis points (1500 is 15%); null ends it.
   * Links and rewards already handed out keep what they were given.
   */
  setOffer(eventId: string, discountBps: number | null, maxRewards: number): Observable<{ share_offer: OrganizerShareOffer }> {
    return this.http.put<{ share_offer: OrganizerShareOffer }>(`${this.base}/api/organizer/events/${eventId}/share-offer`, {
      discount_bps: discountBps,
      max_rewards: maxRewards,
    });
  }
}
