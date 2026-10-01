/** The survey somebody is sent after a night, and what they answer. */

/**
 * The five kinds of question. `nps` is 0 to 10 ("how likely are you to
 * recommend"), `rating5` 1 to 5, `single` one of the options, `multi` any of
 * them, `text` up to 2,000 characters.
 */
export type SurveyQuestionType = 'nps' | 'rating5' | 'single' | 'multi' | 'text';

export interface SurveyQuestion {
  /** Fixed for the question's life; answers are keyed by it. */
  id: string;
  type: SurveyQuestionType;
  label: string;
  /** Empty except for `single` and `multi`. */
  options: string[];
  required: boolean;
}

/** The survey behind an emailed link (GET /api/surveys/{token}). Never whose link it is. */
export interface PublicSurvey {
  event: { title: string; organizer: string; starts_at: string; timezone: string };
  questions: SurveyQuestion[];
  /** True once this link has been answered. It cannot be answered again. */
  answered: boolean;
}

/** One answer: a score, a choice, choices, or words. Null is no answer. */
export type SurveyAnswer = number | string | string[] | null;

export interface SurveyAnswerResult {
  message: string;
  survey: PublicSurvey;
}
