import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { ConfirmDialog, UiButton, UiErrorState, UiSkeleton } from '@myfiesta/ui';
import type { EventSurvey, EventSurveyUpdate, SurveyResults } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { eventIdFrom } from '../../core/event-id';
import { longEventTime } from '../../core/event-time';
import { SurveyResultsView } from './survey-results';
import { SurveysApi } from './surveys-api';
import { questionKind } from './question-kinds';

/**
 * When it goes after the night ends: the choices offered, in hours. None is
 * sooner than the API allows (16 hours, an hour past the door's final count),
 * since a survey that goes before the last scans are in asks the wrong people.
 */
export const DELAYS = [18, 24, 48, 72] as const;

/**
 * One event's survey and what people said: the Feedback tab.
 *
 * The morning after, everybody let in through the door is emailed a few
 * questions (SurveySender on the API), unless the organizer switched it off
 * here or for the whole organization on the Surveys screen. Until it goes,
 * this tab chooses the questions and when; once it has, it reads the answers.
 *
 * Two things here reach people, so both ask first: sending early, and
 * switching back on an event that is over, which the next hourly run sends.
 * Everything else is a setting that can be changed back until the send.
 */
@Component({
  selector: 'app-event-feedback',
  imports: [FormsModule, RouterLink, UiButton, UiErrorState, UiSkeleton, SurveyResultsView],
  templateUrl: './event-feedback.html',
})
export class EventFeedback {
  private readonly api = inject(SurveysApi);
  private readonly route = inject(ActivatedRoute);
  private readonly confirmDialog = inject(ConfirmDialog);

  readonly eventId = eventIdFrom(this.route);
  readonly delays = DELAYS;
  readonly questionKind = questionKind;

  readonly survey = signal<EventSurvey | null>(null);
  readonly results = signal<SurveyResults | null>(null);
  /** Why what people said could not be loaded; the tab offers to try again. */
  readonly resultsFailed = signal<string | null>(null);
  readonly loading = signal(true);
  readonly failed = signal<string | null>(null);

  readonly saving = signal(false);
  readonly sending = signal(false);
  readonly notice = signal<string | null>(null);
  readonly error = signal<string | null>(null);

  /** Settings change only until the survey goes. */
  readonly locked = computed(() => this.survey()?.sent_at != null || this.saving());

  /** The delays offered, with this night's own when it is not one of them. */
  readonly delayChoices = computed(() => {
    const current = this.survey()?.send_delay_hours;
    const all: number[] = [...DELAYS];
    if (current != null && !all.includes(current)) all.push(current);
    return all.sort((a, b) => a - b);
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.failed.set(null);

    this.api.eventSurvey(this.eventId).subscribe({
      next: (survey) => {
        this.survey.set(survey);
        this.loading.set(false);
        if (survey.sent_at) this.loadResults();
      },
      error: (response) => {
        this.loading.set(false);
        this.failed.set(messageFor(response, 'Could not load the survey for this event.'));
      },
    });
  }

  loadResults(): void {
    this.resultsFailed.set(null);

    this.api.results(this.eventId).subscribe({
      next: (results) => this.results.set(results),
      error: (response) => this.resultsFailed.set(messageFor(response, 'We could not reach the server. It is usually temporary.')),
    });
  }

  /**
   * On or off for this event. Switching a finished event back on sends it on
   * the next hourly run, which cannot be called back, so that asks first; a
   * "no" puts the box back as it was.
   */
  async setEnabled(enabled: boolean, box?: HTMLInputElement): Promise<void> {
    const survey = this.survey();
    if (!survey || this.locked()) return;

    if (enabled && survey.due_now && survey.organization_enabled) {
      const questions = survey.questions.length;
      const sure = await this.confirmDialog.confirm({
        title: 'Switch the survey on and send it?',
        body: `This event is over and its door has been counted, so everybody let in who takes emails like this is asked ${questions === 1 ? 'one question' : `${questions} questions`} within the hour.`,
        consequences: ['Each person is asked once. It cannot be sent again, or called back.'],
        confirmLabel: 'Switch on and send',
        tone: 'default',
      });

      if (!sure) {
        if (box) box.checked = false;
        return;
      }
    }

    this.save({ enabled }, (saved) => this.switchedNotice(saved), box);
  }

  setTemplate(templateId: string | null): void {
    this.save({ template_id: templateId }, () => 'Questions chosen.');
  }

  setDelay(hours: number): void {
    this.save({ send_delay_hours: Number(hours) }, () => `It goes ${this.hours(Number(hours))} after the event ends.`);
  }

  /** What switching it on or off did, from what the server now says. */
  private switchedNotice(saved: EventSurvey): string {
    if (!saved.enabled) return 'Nobody is asked about this event.';
    if (saved.stopped_because) return 'Switched on.';
    if (saved.due_now) return 'Switched on. The survey goes within the hour.';
    return `Switched on. The survey goes ${this.when(saved.sends_at, saved)}.`;
  }

  private save(changes: EventSurveyUpdate, done: (saved: EventSurvey) => string, box?: HTMLInputElement): void {
    if (this.locked()) return;

    this.saving.set(true);
    this.error.set(null);
    this.notice.set(null);

    this.api.updateEventSurvey(this.eventId, changes).subscribe({
      next: (survey) => {
        this.saving.set(false);
        this.survey.set(survey);
        this.notice.set(done(survey));
      },
      error: (response) => {
        this.saving.set(false);
        this.error.set(messageFor(response, 'That could not be saved.'));
        // Back to what the server has, so the controls do not show a change that did not happen.
        if (box && changes.enabled !== undefined) box.checked = !changes.enabled;
        this.load();
      },
    });
  }

  /**
   * Now, rather than when the hourly run gets to it. An email cannot be
   * called back, and each person is only ever asked once, so it asks first.
   */
  async sendNow(): Promise<void> {
    const survey = this.survey();
    if (!survey?.can_send_now || this.sending()) return;

    const questions = survey.questions.length;
    const sure = await this.confirmDialog.confirm({
      title: 'Send the survey now?',
      body: `Everybody let in to this event who takes emails like this is asked ${questions === 1 ? 'one question' : `${questions} questions`}, straight away.`,
      consequences: [
        'Each person is asked once. It cannot be sent again, or called back.',
        'The questions cannot be changed once it has gone.',
      ],
      confirmLabel: 'Send the survey',
      tone: 'default',
    });

    if (!sure || this.sending()) return;

    this.sending.set(true);
    this.error.set(null);
    this.notice.set(null);

    this.api.sendNow(this.eventId).subscribe({
      next: ({ message, survey: sent }) => {
        this.sending.set(false);
        this.notice.set(message);
        this.survey.set(sent);
        this.loadResults();
      },
      error: (response) => {
        this.sending.set(false);
        this.error.set(messageFor(response, 'The survey could not be sent.'));
        this.load();
      },
    });
  }

  when(iso: string | null, survey: EventSurvey): string {
    return iso ? longEventTime(iso, survey.timezone) : '';
  }

  /** Whether a time has come: a send time already reached goes on the next hourly run. */
  past(iso: string): boolean {
    return new Date(iso).getTime() <= Date.now();
  }

  hours(n: number): string {
    if (n % 24 === 0 && n >= 48) return `${n / 24} days`;
    return n === 1 ? '1 hour' : `${n} hours`;
  }
}
