import type { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { ConfirmDialog } from '@myfiesta/ui';
import { Seo } from '../../core/seo';
import { PublicSurvey, SurveyAnswer, SurveyQuestion } from '../../core/api.types';
import { FeedbackApi } from './feedback-api';

/**
 * The survey somebody is sent after a night: tickets/feedback/:token.
 *
 * Under tickets/ like the other pages a link in an email opens, and the
 * token in it is the whole credential, so it is drawn in the browser rather
 * than on the server (app.routes.server.ts). No sign-in: most people who
 * came bought as guests.
 *
 * Answered once. The server keeps the first answer and refuses a second, so
 * the page asks before sending, and says plainly when a link has already
 * been used rather than showing a form that cannot be sent.
 */
@Component({
  selector: 'mf-feedback',
  imports: [FormsModule],
  templateUrl: './feedback.html',
})
export class Feedback {
  private readonly api = inject(FeedbackApi);
  private readonly route = inject(ActivatedRoute);
  private readonly seo = inject(Seo);
  private readonly confirmDialog = inject(ConfirmDialog);

  /** The link's whole credential. */
  private readonly token = this.route.snapshot.paramMap.get('token') ?? '';

  readonly survey = signal<PublicSurvey | null>(null);
  readonly loading = signal(true);
  readonly notFound = signal(false);
  /** The survey could not be fetched just now, though the link may be fine. */
  readonly unavailable = signal(false);

  /** Keyed by question id. A question nobody touched has no entry. */
  readonly answers = signal<Record<string, SurveyAnswer>>({});
  /** What is wrong with an answer, by question id, from this page or the server. */
  readonly errors = signal<Record<string, string>>({});
  readonly sending = signal(false);
  readonly failed = signal<string | null>(null);
  /** The server's thank-you once the answers are kept. */
  readonly thanks = signal<string | null>(null);

  /** The 0 to 10 scale, and the 1 to 5. */
  readonly npsScale = Array.from({ length: 11 }, (_, i) => i);
  readonly ratingScale = [1, 2, 3, 4, 5];

  /** Something to send: at least one answer. */
  readonly ready = computed(() => Object.values(this.answers()).some((value) => !this.blank(value)));

  constructor() {
    this.seo.forPrivatePage('How was it?');
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.unavailable.set(false);

    this.api.survey(this.token).subscribe({
      next: (survey) => {
        this.survey.set(survey);
        this.seo.forPrivatePage(`How was ${survey.event.title}?`);
        this.loading.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.loading.set(false);

        // Only the server saying there is no such link means the link is
        // wrong. Anything else — offline, too many tries, a fault on our
        // side — and the link is fine, so it says so and offers to try again.
        if (error.status === 404) {
          this.seo.notFound('Survey not found');
          this.notFound.set(true);
        } else {
          this.seo.unavailable('Survey unavailable');
          this.unavailable.set(true);
        }
      },
    });
  }

  answer(id: string, value: SurveyAnswer): void {
    this.answers.update((all) => ({ ...all, [id]: value }));
    this.errors.update((all) => {
      const next = { ...all };
      delete next[id];
      return next;
    });
  }

  /** One box of a "choose any" question, ticked or not. */
  toggle(question: SurveyQuestion, option: string, on: boolean): void {
    const chosen = this.chosen(question.id);
    const next = on ? [...chosen, option] : chosen.filter((o) => o !== option);

    // In the order the question offers them, as the server keeps them.
    this.answer(question.id, question.options.filter((o) => next.includes(o)));
  }

  chosen(id: string): string[] {
    const value = this.answers()[id];
    return Array.isArray(value) ? value : [];
  }

  /** "Saturday, October 3, 2026", in the night's own zone. */
  night(survey: PublicSurvey): string {
    return new Intl.DateTimeFormat('en-CA', { dateStyle: 'full', timeZone: survey.event.timezone }).format(
      new Date(survey.event.starts_at),
    );
  }

  async send(): Promise<void> {
    const survey = this.survey();
    if (!survey || this.sending()) return;

    // A required question left empty is said beside it before anything is
    // sent; the server checks again, and its word is the one that counts.
    const missing: Record<string, string> = {};
    for (const question of survey.questions) {
      if (question.required && this.blank(this.answers()[question.id])) {
        missing[question.id] = 'Please answer this one.';
      }
    }

    if (Object.keys(missing).length > 0) {
      this.errors.set(missing);
      this.failed.set('A question still needs an answer.');
      return;
    }

    if (!this.ready()) {
      this.failed.set('Answer at least one question.');
      return;
    }

    const sure = await this.confirmDialog.confirm({
      title: 'Send your answers?',
      body: `${survey.event.organizer} reads them without your name or email address.`,
      consequences: ['Each link takes one answer, so you cannot change them afterwards.'],
      confirmLabel: 'Send my answers',
      tone: 'default',
    });

    if (!sure) return;

    this.sending.set(true);
    this.failed.set(null);

    this.api.answer(this.token, this.answers()).subscribe({
      next: ({ message, survey: kept }) => {
        this.sending.set(false);
        this.survey.set(kept);
        this.thanks.set(message);
      },
      error: (response) => {
        this.sending.set(false);
        const body = response?.error;

        if (response?.status === 409) {
          // Answered already, from another tab or another device.
          this.survey.set({ ...survey, answered: true });
          return;
        }

        if (response?.status === 422 && body?.errors) {
          const byQuestion: Record<string, string> = {};
          for (const [key, messages] of Object.entries(body.errors as Record<string, string[]>)) {
            const id = key.startsWith('answers.') ? key.slice('answers.'.length) : null;
            if (id) byQuestion[id] = messages[0];
          }
          this.errors.set(byQuestion);

          if (Object.keys(byQuestion).length > 0) {
            this.failed.set('Some answers need another look.');
            return;
          }
        }

        this.failed.set(body?.message ?? 'Your answers could not be sent just now. Try again in a moment.');
      },
    });
  }

  private blank(value: SurveyAnswer | undefined): boolean {
    return value === undefined || value === null || (Array.isArray(value) ? value.length === 0 : String(value).trim() === '');
  }
}
