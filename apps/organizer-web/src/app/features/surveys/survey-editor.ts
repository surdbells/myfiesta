import { Component, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { UiButton } from '@myfiesta/ui';
import type { SurveyQuestion, SurveyQuestionType, SurveyTemplate, SurveyTemplateInput } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { QUESTION_KINDS } from './question-kinds';
import { SurveysApi } from './surveys-api';

/** A question while it is being written: choices as typed, one per line. */
interface Draft {
  /** Kept from the question being edited, so its answers stay attached. Null for a new one. */
  id: string | null;
  type: SurveyQuestionType;
  label: string;
  choices: string;
  required: boolean;
  /** For the list's track, since a new question has no id yet. */
  key: number;
}

/** What the editor starts from: a survey to change, or questions to begin a new one with. */
export interface EditorStart {
  /** Set when changing one of the organization's own surveys. */
  id: string | null;
  name: string;
  questions: SurveyQuestion[];
}

let nextKey = 0;

/**
 * Writing one of the organization's own surveys: a name and up to twelve
 * questions, each one of the five kinds.
 *
 * Saving changes the questions for every event still to be sent this
 * survey; events already sent keep what they asked, so the editor says so
 * rather than asking first.
 */
@Component({
  selector: 'app-survey-editor',
  imports: [FormsModule, UiButton],
  template: `
    <form class="editor grid gap-5 rounded-(--radius-card) border border-border-subtle bg-surface-raised p-6 shadow-(--shadow-card)" (ngSubmit)="save()" novalidate>
      <div class="field">
        <label for="survey-name">Name</label>
        <input
          id="survey-name"
          name="name"
          maxlength="80"
          required
          placeholder="After a concert"
          [ngModel]="name()"
          (ngModelChange)="name.set($event)"
        />
        <p class="field__hint">Only you see this, when choosing the survey for an event.</p>
        @if (errors()['name']; as error) {
          <p class="field__error" role="alert">{{ error }}</p>
        }
      </div>

      <ol class="m-0 grid list-none gap-4 p-0">
        @for (q of questions(); track q.key; let i = $index, first = $first, last = $last) {
          <li class="question grid gap-3 rounded-md border border-border-subtle bg-surface p-4">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-xs font-semibold uppercase tracking-[0.08em] text-text-subtle">Question {{ i + 1 }}</span>
              <span class="ml-auto flex gap-1">
                <button uiButton variant="ghost" size="sm" type="button" [disabled]="first" (click)="move(i, -1)" [attr.aria-label]="'Move question ' + (i + 1) + ' up'">Up</button>
                <button uiButton variant="ghost" size="sm" type="button" [disabled]="last" (click)="move(i, 1)" [attr.aria-label]="'Move question ' + (i + 1) + ' down'">Down</button>
                <button uiButton variant="ghost" size="sm" type="button" [disabled]="questions().length === 1" (click)="remove(i)" [attr.aria-label]="'Remove question ' + (i + 1)">Remove</button>
              </span>
            </div>

            <div class="grid gap-3 sm:grid-cols-[1fr_14rem]">
              <div class="field">
                <label [for]="'label-' + q.key">Question</label>
                <input
                  [id]="'label-' + q.key"
                  [name]="'label-' + q.key"
                  maxlength="200"
                  required
                  [ngModel]="q.label"
                  (ngModelChange)="change(i, { label: $event })"
                />
              </div>
              <div class="field">
                <label [for]="'type-' + q.key">Kind</label>
                <select [id]="'type-' + q.key" [name]="'type-' + q.key" [ngModel]="q.type" (ngModelChange)="change(i, { type: $event })">
                  @for (kind of kinds; track kind.type) {
                    <option [value]="kind.type">{{ kind.label }}</option>
                  }
                </select>
              </div>
            </div>

            @if (q.type === 'single' || q.type === 'multi') {
              <div class="field">
                <label [for]="'choices-' + q.key">Answers to choose from, one per line</label>
                <textarea
                  [id]="'choices-' + q.key"
                  [name]="'choices-' + q.key"
                  rows="4"
                  [ngModel]="q.choices"
                  (ngModelChange)="change(i, { choices: $event })"
                ></textarea>
              </div>
            } @else {
              <p class="m-0 text-xs text-text-muted">{{ hint(q.type) }}</p>
            }

            <label class="flex cursor-pointer items-center gap-2 text-sm font-normal">
              <input class="check" type="checkbox" [name]="'required-' + q.key" [ngModel]="q.required" (ngModelChange)="change(i, { required: $event })" />
              People must answer this one
            </label>

            @for (error of errorsFor(i); track error) {
              <p class="field__error" role="alert">{{ error }}</p>
            }
          </li>
        }
      </ol>

      <div class="flex flex-wrap items-center gap-3">
        <button uiButton variant="secondary" size="sm" type="button" [disabled]="full()" (click)="add()">Add a question</button>
        <span class="text-xs text-text-muted">
          {{ questions().length }} of {{ maxQuestions() }}. Fewer questions, more people finish.
        </span>
      </div>

      @if (failed(); as message) {
        <p class="error m-0 rounded-md bg-[color-mix(in_srgb,var(--danger)_9%,transparent)] p-3 text-sm text-danger" role="alert">{{ message }}</p>
      }

      <div class="flex flex-wrap items-center gap-3 border-t border-border-subtle pt-4">
        <button uiButton type="submit" [loading]="saving()" [disabled]="saving()">{{ start().id ? 'Save changes' : 'Save survey' }}</button>
        <button uiButton variant="ghost" type="button" [disabled]="saving()" (click)="cancelled.emit()">Cancel</button>
        @if (start().id) {
          <span class="text-xs text-text-muted">Events already sent this survey keep the questions they asked.</span>
        }
      </div>
    </form>
  `,
})
export class SurveyEditor implements OnInit {
  private readonly api = inject(SurveysApi);

  readonly start = input.required<EditorStart>();
  readonly maxQuestions = input(12);
  readonly saved = output<SurveyTemplate>();
  readonly cancelled = output<void>();

  readonly kinds = QUESTION_KINDS;

  readonly name = signal('');
  readonly questions = signal<Draft[]>([]);
  readonly saving = signal(false);
  readonly failed = signal<string | null>(null);
  /** The server's word on a field, keyed as it sends them: name, questions.2.options. */
  readonly errors = signal<Record<string, string>>({});

  readonly full = computed(() => this.questions().length >= this.maxQuestions());

  ngOnInit(): void {
    const start = this.start();
    this.name.set(start.name);
    this.questions.set(
      start.questions.length > 0
        ? start.questions.map((q) => this.draft(q))
        : [this.draft({ id: '', type: 'nps', label: 'How likely are you to recommend this event to a friend?', options: [], required: true })],
    );
  }

  private draft(q: SurveyQuestion): Draft {
    return { id: q.id || null, type: q.type, label: q.label, choices: q.options.join('\n'), required: q.required, key: nextKey++ };
  }

  hint(type: SurveyQuestionType): string {
    return QUESTION_KINDS.find((kind) => kind.type === type)?.hint ?? '';
  }

  change(index: number, changes: Partial<Draft>): void {
    this.questions.update((all) => all.map((q, i) => (i === index ? { ...q, ...changes } : q)));
  }

  add(): void {
    if (this.full()) return;
    this.questions.update((all) => [...all, this.draft({ id: '', type: 'rating5', label: '', options: [], required: false })]);
  }

  remove(index: number): void {
    this.questions.update((all) => all.filter((_, i) => i !== index));
  }

  move(index: number, by: -1 | 1): void {
    this.questions.update((all) => {
      const next = [...all];
      const target = index + by;
      if (target < 0 || target >= next.length) return all;
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });
  }

  errorsFor(index: number): string[] {
    const prefix = `questions.${index}`;
    return Object.entries(this.errors())
      .filter(([key]) => key === prefix || key.startsWith(prefix + '.'))
      .map(([, message]) => message);
  }

  /** What the API takes: choices split into lines, and only for the kinds that have them. */
  input(): SurveyTemplateInput {
    return {
      name: this.name().trim(),
      questions: this.questions().map((q) => ({
        id: q.id,
        type: q.type,
        label: q.label.trim(),
        options:
          q.type === 'single' || q.type === 'multi'
            ? q.choices.split('\n').map((line) => line.trim()).filter((line) => line !== '')
            : [],
        required: q.required,
      })),
    };
  }

  save(): void {
    if (this.saving()) return;

    const id = this.start().id;
    const body = this.input();

    this.saving.set(true);
    this.failed.set(null);
    this.errors.set({});

    (id ? this.api.updateTemplate(id, body) : this.api.createTemplate(body)).subscribe({
      next: ({ data }) => {
        this.saving.set(false);
        this.saved.emit(data);
      },
      error: (response: unknown) => {
        this.saving.set(false);

        const fields = response instanceof HttpErrorResponse ? (response.error?.errors as Record<string, string[]> | undefined) : undefined;
        if (fields) {
          this.errors.set(Object.fromEntries(Object.entries(fields).map(([key, messages]) => [key, messages[0]])));
        }

        this.failed.set(fields ? 'Some of the survey needs another look.' : messageFor(response, 'The survey could not be saved.'));
      },
    });
  }
}
