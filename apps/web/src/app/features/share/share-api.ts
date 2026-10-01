import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { API_BASE_URL } from '../../core/api-base';
import { ShareOffer } from '../../core/api.types';

/**
 * Friend discounts, on the server.
 *
 * Its own injectable rather than another method on core/api, so this feature
 * never edits a file the others are changing too.
 */
@Injectable({ providedIn: 'root' })
export class ShareApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  /**
   * What a friend's link takes off this night. Fails (404) for a ref that is
   * not a working friend's link to it — a promoter's slug that only looks
   * like one, or a link whose holder is no longer coming.
   */
  friendDiscount(slug: string, ref: string): Observable<ShareOffer> {
    return this.http
      .get<{ data: ShareOffer }>(`${this.base}/api/events/${encodeURIComponent(slug)}/friend-discount`, {
        params: { ref },
      })
      .pipe(map(({ data }) => data));
  }
}
