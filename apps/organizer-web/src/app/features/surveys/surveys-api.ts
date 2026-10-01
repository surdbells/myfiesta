import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import type { Observable } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import type {
  EventSurvey,
  EventSurveyUpdate,
  SurveyOverview,
  SurveyResults,
  SurveySettingsResult,
  SurveyTemplate,
  SurveyTemplateInput,
  SurveyTemplateList,
} from '../../core/api.types';

/**
 * The calls behind the survey after each night: the organization's switch,
 * its own surveys, and one night's survey and what people said.
 *
 * Here rather than in core/api.ts, which the features added since leave
 * alone (the Api class says why).
 */
@Injectable({ providedIn: 'root' })
export class SurveysApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  overview(): Observable<SurveyOverview> {
    return this.http.get<SurveyOverview>(`${this.base}/api/organizer/surveys`);
  }

  /** On or off for every night of the organization. */
  setEnabled(enabled: boolean): Observable<SurveySettingsResult> {
    return this.http.put<SurveySettingsResult>(`${this.base}/api/organizer/surveys/settings`, { surveys_enabled: enabled });
  }

  templates(): Observable<SurveyTemplateList> {
    return this.http.get<SurveyTemplateList>(`${this.base}/api/organizer/survey-templates`);
  }

  createTemplate(input: SurveyTemplateInput): Observable<{ data: SurveyTemplate }> {
    return this.http.post<{ data: SurveyTemplate }>(`${this.base}/api/organizer/survey-templates`, input);
  }

  updateTemplate(id: string, input: SurveyTemplateInput): Observable<{ data: SurveyTemplate }> {
    return this.http.put<{ data: SurveyTemplate }>(`${this.base}/api/organizer/survey-templates/${id}`, input);
  }

  /** Archived, not deleted: the message says which nights send myFiesta's instead. */
  removeTemplate(id: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(`${this.base}/api/organizer/survey-templates/${id}`);
  }

  eventSurvey(eventId: string): Observable<EventSurvey> {
    return this.http.get<EventSurvey>(`${this.base}/api/organizer/events/${eventId}/survey`);
  }

  updateEventSurvey(eventId: string, changes: EventSurveyUpdate): Observable<EventSurvey> {
    return this.http.put<EventSurvey>(`${this.base}/api/organizer/events/${eventId}/survey`, changes);
  }

  results(eventId: string): Observable<SurveyResults> {
    return this.http.get<SurveyResults>(`${this.base}/api/organizer/events/${eventId}/survey/results`);
  }

  /** Now, rather than when the hourly run gets to it. Once only. */
  sendNow(eventId: string): Observable<{ message: string; invited: number; survey: EventSurvey }> {
    return this.http.post<{ message: string; invited: number; survey: EventSurvey }>(
      `${this.base}/api/organizer/events/${eventId}/survey/send`,
      {},
    );
  }
}
