import type { SurveyQuestionType } from '../../core/api.types';

/**
 * The five kinds of question, named as somebody writing a survey thinks of
 * them rather than as the API stores them.
 */
export const QUESTION_KINDS: readonly { type: SurveyQuestionType; label: string; hint: string }[] = [
  { type: 'nps', label: 'Would recommend, 0 to 10', hint: 'Gives the night its recommend score.' },
  { type: 'rating5', label: 'Rating, 1 to 5', hint: 'Averaged, and compared with your last event.' },
  { type: 'single', label: 'One choice', hint: 'People pick one of the answers you give.' },
  { type: 'multi', label: 'Any of several', hint: 'People tick as many as apply.' },
  { type: 'text', label: 'Written answer', hint: 'Read here without names.' },
];

export function questionKind(type: SurveyQuestionType): string {
  return QUESTION_KINDS.find((kind) => kind.type === type)?.label ?? type;
}
