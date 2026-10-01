import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../core/api-base';
import { PublicSurvey, SurveyAnswer, SurveyAnswerResult } from '../../core/api.types';

/**
 * The survey from an emailed link, and answering it.
 *
 * Its own injectable beside the page rather than another method on core/api,
 * so this feature never edits a file the others are changing too. The token
 * sits right after `surveys`, which the error reporter already blanks out of
 * any address it sends.
 */
@Injectable({ providedIn: 'root' })
export class FeedbackApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  survey(token: string): Observable<PublicSurvey> {
    return this.http.get<PublicSurvey>(`${this.base}/api/surveys/${encodeURIComponent(token)}`);
  }

  /** Once per link. A second try answers 409, and the page says it was already kept. */
  answer(token: string, answers: Record<string, SurveyAnswer>): Observable<SurveyAnswerResult> {
    return this.http.post<SurveyAnswerResult>(`${this.base}/api/surveys/${encodeURIComponent(token)}`, { answers });
  }
}
