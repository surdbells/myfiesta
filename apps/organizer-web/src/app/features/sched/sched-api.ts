import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import type { Observable } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import type { SeriesSettings, SeriesUpdated } from '../../core/api.types';

/**
 * The calls behind going on sale at a set time and a repeating night's
 * settings.
 *
 * Here rather than in core/api.ts, which the features added since leave
 * alone (the Api class says why). The event as a whole is read again through
 * Api.event afterwards, so the Overview and its header agree.
 */
@Injectable({ providedIn: 'root' })
export class SchedApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  /**
   * Set when the night goes on sale by itself, as an ISO instant, or null to
   * stop waiting for a time. The time alone can move while the night waits
   * for review; nothing else can.
   */
  setPublishAt(eventId: string, publishAt: string | null): Observable<unknown> {
    return this.http.patch(`${this.base}/api/organizer/events/${eventId}`, { publish_at: publishAt });
  }

  /** Change how a series ends, and whether its dates put themselves on sale. */
  updateSeries(eventId: string, settings: SeriesSettings): Observable<SeriesUpdated> {
    return this.http.patch<SeriesUpdated>(`${this.base}/api/organizer/events/${eventId}/series`, settings);
  }
}
