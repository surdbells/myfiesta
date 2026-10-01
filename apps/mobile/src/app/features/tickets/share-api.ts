import { Injectable, inject } from '@angular/core';
import type { ShareLink, ShareOffer } from '@myfiesta/api-types';
import { Api } from '../../core/api';

/**
 * Friend discounts, on the server: a ticket holder's own link, and whether a
 * link somebody arrived by takes money off.
 *
 * Its own file rather than more of core/api.ts, so the features built at the
 * same time never edit the same lines. A buyer is given their link when they
 * pay; link() is for somebody else holding a ticket to the night (passed on
 * to them), who asks for theirs. Asking twice gets the same link.
 */
@Injectable({ providedIn: 'root' })
export class ShareApi {
  private readonly api = inject(Api);

  async link(eventSlug: string): Promise<ShareLink> {
    const { data } = await this.api.request<{ data: ShareLink }>('POST', `/api/events/${encodeURIComponent(eventSlug)}/share-link`);

    return data;
  }

  /**
   * What a friend's link takes off a night, for the event screen to say so.
   * Public, without the token. Fails (404) for a ref that is not a working
   * friend's link to it: a promoter's slug that only looks like one, or a
   * link whose holder is no longer coming.
   */
  async friendDiscount(eventSlug: string, ref: string): Promise<ShareOffer> {
    const { data } = await this.api.public<{ data: ShareOffer }>(
      `/api/events/${encodeURIComponent(eventSlug)}/friend-discount`,
      { ref },
    );

    return data;
  }
}
