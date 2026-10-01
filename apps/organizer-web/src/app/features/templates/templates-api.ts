import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import type { Observable } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import type { CopyAdjustments, EventCopy, EventTemplate } from '../../core/api.types';

/**
 * The calls behind copying an event with changes and its templates.
 *
 * Here rather than in core/api.ts, which the features added since leave
 * alone (the Api class says why). Copying with changes is a call of its own
 * rather than a wider duplicateEvent, which the quick "Same event, new date"
 * copy on the Overview keeps using unchanged.
 */
@Injectable({ providedIn: 'root' })
export class TemplatesApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  /** A new draft from this event, with the changes asked for. */
  duplicate(eventId: string, changes: CopyAdjustments): Observable<EventCopy> {
    return this.http.post<EventCopy>(`${this.base}/api/organizer/events/${eventId}/duplicate`, changes);
  }

  templates(): Observable<{ data: EventTemplate[] }> {
    return this.http.get<{ data: EventTemplate[] }>(`${this.base}/api/organizer/templates`);
  }

  /** Keep this event, as it is now, as a template. */
  keep(eventId: string, name: string): Observable<{ data: EventTemplate }> {
    return this.http.post<{ data: EventTemplate }>(`${this.base}/api/organizer/templates`, {
      from_event_id: eventId,
      name,
    });
  }

  /** A new draft event from a template. A start is required. */
  createEvent(templateId: string, changes: CopyAdjustments & { starts_at: string }): Observable<EventCopy> {
    return this.http.post<EventCopy>(`${this.base}/api/organizer/templates/${templateId}/events`, changes);
  }

  delete(templateId: string): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/organizer/templates/${templateId}`);
  }
}
