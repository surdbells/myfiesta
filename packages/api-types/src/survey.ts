/** Surveys after a night: templates, a night's survey, and what people said. */

/**
 * The five kinds of question. `nps` is 0 to 10 ("how likely are you to
 * recommend"), `rating5` 1 to 5, `single` one of the options, `multi` any of
 * them, `text` up to 2,000 characters.
 */
export type SurveyQuestionType = 'nps' | 'rating5' | 'single' | 'multi' | 'text';

export interface SurveyQuestion {
  /** Fixed for the question's life, so its answers stay attached to it. */
  id: string;
  type: SurveyQuestionType;
  label: string;
  /** Empty except for `single` and `multi`. */
  options: string[];
  required: boolean;
}

/** A question as the console sends it: a new one has no id yet. */
export interface SurveyQuestionInput {
  id?: string | null;
  type: SurveyQuestionType;
  label: string;
  options?: string[];
  required?: boolean;
}

/** One answer, keyed by question id: a score, a choice, choices, or words. */
export type SurveyAnswer = number | string | string[] | null;

/** The survey behind an emailed link (GET /api/surveys/{token}). Never whose link it is. */
export interface PublicSurvey {
  event: { title: string; organizer: string; starts_at: string; timezone: string };
  questions: SurveyQuestion[];
  /** True once this link has been answered. It cannot be answered again. */
  answered: boolean;
}

/** A list of questions a night can send. `platform` is myFiesta's own, which nobody edits. */
export interface SurveyTemplate {
  id: string;
  name: string;
  platform: boolean;
  questions: SurveyQuestion[];
  /** Nights still to be sent with it, so removing it can say what it touches. */
  nights_waiting: number;
  updated_at: string | null;
}

export interface SurveyTemplateInput {
  name: string;
  questions: SurveyQuestionInput[];
}

export interface SurveyTemplateList {
  data: SurveyTemplate[];
  max_questions: number;
  types: SurveyQuestionType[];
}

export interface SurveyTemplateChoice {
  id: string;
  name: string;
  platform: boolean;
}

/** One night's survey as it stands, for the event's Feedback tab. */
export interface EventSurvey {
  enabled: boolean;
  organization_enabled: boolean;
  /** The survey chosen for this night. Null is myFiesta's own. */
  template_id: string | null;
  template: SurveyTemplateChoice | null;
  send_delay_hours: number;
  /** The night's own zone (IANA). */
  timezone: string;
  /** The night's end, or twelve hours after it starts when it lists none. */
  ends_at: string;
  /** When the door's last scans are in, the earliest the survey can be sent. */
  final_count_at: string;
  /** The hourly run that sends it, if nothing stops it. */
  sends_at: string;
  sent_at: string | null;
  /** Why it will not go, in the organizer's words; null when it will or has. */
  stopped_because: string | null;
  /** Switched on, the next hourly run sends it. */
  due_now: boolean;
  can_send_now: boolean;
  questions: SurveyQuestion[];
  /** What can be chosen instead, myFiesta's own first. */
  templates: SurveyTemplateChoice[];
}

export interface EventSurveyUpdate {
  enabled?: boolean;
  template_id?: string | null;
  send_delay_hours?: number;
}

/** A surveyed night on the Surveys screen. */
export interface SurveyedNight {
  event_id: string;
  title: string;
  starts_at: string;
  timezone: string;
  sent_at: string | null;
  invited: number;
  responded: number;
  /** Percent of those asked who answered. */
  response_rate: number | null;
  /** The recommend score, -100 to 100. Null under `minimum` answers. */
  nps: number | null;
}

export interface SurveyOverview {
  surveys_enabled: boolean;
  /** Finished nights the next hourly run asks about with surveys on. */
  nights_due_now: number;
  /** Answers a night needs before its figures are shown. */
  minimum: number;
  recent: SurveyedNight[];
}

/** The organization's switch, as saved. */
export interface SurveySettingsResult {
  surveys_enabled: boolean;
  /** Finished nights the next hourly run asks about with surveys on. */
  nights_due_now: number;
  message: string;
}

export interface SurveyQuestionResult {
  id: string;
  type: SurveyQuestionType;
  label: string;
  answered: number;
  /** False until enough people answered it; the figures stay null until then. */
  shown: boolean;
  average: number | null;
  /** Every possible answer with how many gave it, zeros included. */
  distribution: { label: string; count: number }[] | null;
  /** What was written, newest first, with nothing about who wrote it. */
  texts: string[] | null;
}

export interface SurveyNps {
  score: number;
  promoters: number;
  passives: number;
  detractors: number;
  answered: number;
}

/** Something the answers suggest doing, by a rule the figures beside it show. */
export interface SurveyInsight {
  kind: 'lowest' | 'change' | 'words';
  tone: 'good' | 'warning' | 'neutral';
  title: string;
  action: string;
  /** The event tab where the action is taken, when there is one. */
  tab: string | null;
  word: string | null;
}

export interface SurveyResults {
  sent_at: string | null;
  invited: number;
  responded: number;
  response_rate: number | null;
  minimum: number;
  /** Whether `minimum` people have answered. Below it, only the counts are shown. */
  enough: boolean;
  nps: SurveyNps | null;
  questions: SurveyQuestionResult[];
  insights: SurveyInsight[];
}
