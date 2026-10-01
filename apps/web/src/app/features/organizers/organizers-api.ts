import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../core/api-base';
import { OrganizerEvents } from '../../core/api.types';

/**
 * An organizer's nights beyond the twelve their page carries.
 *
 * Its own injectable beside the page rather than another method on core/api,
 * so this feature never edits a file the others are changing too.
 */
@Injectable({ providedIn: 'root' })
export class OrganizersApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  /** Twelve at a time, newest first for `past`. Page 1 is what the page already shows. */
  events(slug: string, when: 'past' | 'upcoming', page: number): Observable<OrganizerEvents> {
    const params = new HttpParams().set('when', when).set('page', String(page));

    return this.http.get<OrganizerEvents>(
      `${this.base}/api/organizers/${encodeURIComponent(slug)}/events`,
      { params },
    );
  }
}
