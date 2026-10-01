import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ConfirmDialog, UiButton, UiEmpty, UiErrorState, UiPageHeader, UiSkeleton } from '@myfiesta/ui';
import type { SurveyedNight, SurveyTemplate } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { eventDate } from '../../core/event-time';
import { loadList } from '../../core/list-loader';
import { SessionStore } from '../../core/session';
import { questionKind } from './question-kinds';
import { SurveyEditor, type EditorStart } from './survey-editor';
import { SurveysApi } from './surveys-api';

/**
 * Surveys after each event: the organization's switch, how its recent
 * events answered, and the surveys it can send.
 *
 * On by default. Every event is asked about the morning after with
 * myFiesta's own six questions unless it chose one of the organization's
 * own, written here; any one event is switched off or changed on its
 * Feedback tab.
 */
@Component({
  selector: 'app-surveys',
  imports: [RouterLink, UiButton, UiEmpty, UiErrorState, UiPageHeader, UiSkeleton, SurveyEditor],
  templateUrl: './surveys.html',
})
export class Surveys {
  private readonly api = inject(SurveysApi);
  private readonly confirmDialog = inject(ConfirmDialog);
  private readonly session = inject(SessionStore);

  readonly questionKind = questionKind;

  /** Asked again when the organization being worked in changes. */
  private readonly organization = computed(() => this.session.current()?.id ?? null);
  private readonly overviewLoader = loadList(this.organization, () => this.api.overview());
  private readonly templatesLoader = loadList(this.organization, () => this.api.templates());

  readonly overview = this.overviewLoader.result;
  readonly loading = this.overviewLoader.loading;
  readonly failed = this.overviewLoader.failed;
  readonly refused = this.overviewLoader.refused;

  /** Saved or removed here, ahead of the next fetch. */
  private readonly changed = signal<SurveyTemplate[] | null>(null);
  readonly templates = computed(() => this.changed() ?? this.templatesLoader.result()?.data ?? []);
  readonly maxQuestions = computed(() => this.templatesLoader.result()?.max_questions ?? 12);
  readonly own = computed(() => this.templates().filter((t) => !t.platform));
  readonly platform = computed(() => this.templates().find((t) => t.platform) ?? null);

  /** The organization's switch as last saved; null until the overview arrives. */
  private readonly switched = signal<boolean | null>(null);
  readonly enabled = computed(() => this.switched() ?? this.overview()?.surveys_enabled ?? true);

  /** Finished events that switching surveys on would send within the hour. */
  private readonly dueNow = signal<number | null>(null);

  /** The surveys themselves, loaded beside the overview and failing on their own. */
  readonly templatesLoading = this.templatesLoader.loading;
  readonly templatesFailed = this.templatesLoader.failed;
  readonly templatesLoaded = computed(() => this.changed() !== null || this.templatesLoader.result() !== null);

  /** What the editor was opened with, while it is open. */
  readonly editing = signal<EditorStart | null>(null);
  readonly switching = signal(false);
  readonly notice = signal<string | null>(null);
  readonly error = signal<string | null>(null);

  retry(): void {
    this.overviewLoader.retry();
    this.templatesLoader.retry();
  }

  retryTemplates(): void {
    this.templatesLoader.retry();
  }

  /**
   * On or off for every event. Switching on while finished events are due
   * sends them on the next hourly run, which cannot be called back, so that
   * asks first; a "no" puts the box back as it was.
   */
  async setEnabled(enabled: boolean, box?: HTMLInputElement): Promise<void> {
    if (this.switching()) return;

    const due = this.dueNow() ?? this.overview()?.nights_due_now ?? 0;

    if (enabled && due > 0) {
      const sure = await this.confirmDialog.confirm({
        title: 'Switch surveys on?',
        body: `${due === 1 ? '1 event that has finished is' : `${due} events that have finished are`} asked about within the hour: everybody let in who takes emails like this.`,
        consequences: [
          'Each person is asked once. It cannot be sent again, or called back.',
          'To leave an event out, switch its survey off on its Feedback tab first.',
        ],
        confirmLabel: 'Switch on and send',
        tone: 'default',
      });

      if (!sure) {
        if (box) box.checked = false;
        return;
      }
    }

    this.switching.set(true);
    this.notice.set(null);
    this.error.set(null);

    this.api.setEnabled(enabled).subscribe({
      next: ({ surveys_enabled, nights_due_now, message }) => {
        this.switching.set(false);
        this.switched.set(surveys_enabled);
        this.dueNow.set(nights_due_now);
        this.notice.set(message);
      },
      error: (response) => {
        this.switching.set(false);
        if (box) box.checked = !enabled;
        this.error.set(messageFor(response, 'That could not be saved.'));
      },
    });
  }

  write(): void {
    this.notice.set(null);
    this.editing.set({ id: null, name: '', questions: [] });
  }

  /**
   * A new survey starting from another's questions: myFiesta's, most often.
   * The ids come too: they only need to be unique within a survey, and the
   * advice for myFiesta's own questions (sound, venue, door, value) is found
   * by them, so a copy keeps it.
   */
  copy(template: SurveyTemplate): void {
    this.notice.set(null);
    this.editing.set({
      id: null,
      name: `${template.name} (copy)`.slice(0, 80),
      questions: template.questions.map((q) => ({ ...q })),
    });
  }

  edit(template: SurveyTemplate): void {
    this.notice.set(null);
    this.editing.set({ id: template.id, name: template.name, questions: template.questions });
  }

  saved(template: SurveyTemplate): void {
    const all = this.templates();
    const known = all.some((t) => t.id === template.id);

    this.changed.set(known ? all.map((t) => (t.id === template.id ? template : t)) : [...all, template]);
    this.editing.set(null);
    this.notice.set(known ? `Saved “${template.name}”.` : `Saved “${template.name}”. Choose it for an event on that event’s Feedback tab.`);
  }

  async remove(template: SurveyTemplate): Promise<void> {
    const waiting = template.nights_waiting;
    const sure = await this.confirmDialog.confirm({
      title: `Remove “${template.name}”?`,
      body: 'It is no longer offered for your events. Events already sent it keep their answers.',
      consequences:
        waiting > 0
          ? [`${waiting === 1 ? 'The 1 event' : `The ${waiting} events`} still to be sent it will send myFiesta’s survey instead.`]
          : [],
      confirmLabel: 'Remove survey',
      tone: 'danger',
      run: () => this.api.removeTemplate(template.id),
      failure: (error) => messageFor(error, 'The survey could not be removed.'),
    });

    if (!sure) return;

    this.changed.set(this.templates().filter((t) => t.id !== template.id));
    this.notice.set(`Removed “${template.name}”.`);
  }

  night(row: SurveyedNight): string {
    return eventDate(row.starts_at, row.timezone);
  }

  signed(score: number | null): string {
    if (score === null) return '—';
    return score > 0 ? `+${score}` : String(score);
  }
}
