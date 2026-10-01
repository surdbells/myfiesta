import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../core/api-base';
import { HelpVideo } from '../../core/api.types';

/**
 * The how-to videos, from GET /api/help/videos.
 *
 * Its own injectable beside the page rather than another method on core/api,
 * so this feature never edits a file the others are changing too.
 */
@Injectable({ providedIn: 'root' })
export class HelpApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  /** Published ones only, buyers' and organizers' together, in staff's order. */
  videos(): Observable<{ data: HelpVideo[] }> {
    return this.http.get<{ data: HelpVideo[] }>(`${this.base}/api/help/videos`);
  }
}
