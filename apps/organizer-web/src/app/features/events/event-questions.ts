import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import {
  ToastStore,
  UiBadge,
  UiButton,
  UiConfirm,
  UiEmpty,
  UiErrorState,
  UiField,
  UiIcon,
  UiModal,
  UiSelect,
  UiSkeleton,
  type SelectOption,
} from '@myfiesta/ui';
import { ChevronDown, ChevronUp, Pencil, Plus, Trash2, X } from 'lucide-angular';
import { Api } from '../../core/api';
import { EventQuestion } from '../../core/api.types';
import { eventIdFrom } from '../../core/event-id';
import { messageFor } from '../../core/errors';

/** A question as the form holds it, before it becomes an API body. */
interface QuestionDraft {
  label: string;
  type: EventQuestion['type'];
  required: boolean;
  /** Asked once for the order, or once about each person on it. */
  perAttendee: boolean;
  /** One per line, as they are typed. Empty lines are dropped on save. */
  options: string[];
}

/**
 * What the checkout asks.
 *
 * The organizer's side of the order form. Everything here is answered by
 * strangers and read back on a guest list, an export and a door screen, so the
 * two decisions that matter are on the row rather than buried in the form:
 * whether an answer is required, and whether it is asked once or asked of each
 * person.
 *
 * A question that has been answered can be reworded and not reshaped — the
 * server refuses the second, and the controls say so rather than letting
 * somebody find out on save.
 */
@Component({
  selector: 'app-event-questions',
  imports: [
    FormsModule,
    UiButton,
    UiBadge,
    UiField,
    UiSelect,
    UiModal,
    UiConfirm,
    UiEmpty,
    UiErrorState,
    UiSkeleton,
    UiIcon,
  ],
  templateUrl: './event-questions.html',
})
export class EventQuestions {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  private readonly route = inject(ActivatedRoute);

  protected readonly addIcon = Plus;
  protected readonly editIcon = Pencil;
  protected readonly deleteIcon = Trash2;
  protected readonly upIcon = ChevronUp;
  protected readonly downIcon = ChevronDown;
  protected readonly removeOptionIcon = X;

  readonly eventId = eventIdFrom(this.route);

  readonly questions = signal<EventQuestion[]>([]);
  readonly loading = signal(true);
  readonly failed = signal(false);

  readonly editing = signal<EventQuestion | null>(null);
  readonly formOpen = signal(false);
  readonly saving = signal(false);
  readonly formError = signal<string | null>(null);
  readonly savingOrder = signal(false);

  readonly removing = signal<EventQuestion | null>(null);

  readonly draft = signal<QuestionDraft>(this.blank());

  /** How an answer is given. The server holds the same list as a constraint. */
  readonly typeOptions: SelectOption[] = [
    { value: 'text', label: 'They type an answer' },
    { value: 'choice', label: 'They choose one' },
    { value: 'multi_choice', label: 'They choose any number' },
    { value: 'boolean', label: 'Yes or no' },
  ];

  readonly askedOptions: SelectOption[] = [
    { value: 'order', label: 'Once, for the whole order' },
    { value: 'attendee', label: 'About each person' },
  ];

  readonly choosing = computed(
    () => this.draft().type === 'choice' || this.draft().type === 'multi_choice',
  );

  /** What a choice question will actually offer, once blank lines are dropped. */
  readonly options = computed(() => this.draft().options.map((o) => o.trim()).filter(Boolean));

  readonly canSave = computed(() => {
    if (this.draft().label.trim().length < 2) return false;

    // A choice with nothing to choose from is a question nobody can answer —
    // and on a required question, a checkout nobody can finish.
    return !this.choosing() || this.options().length >= 2;
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.failed.set(false);

    this.api.eventQuestions(this.eventId).subscribe({
      next: ({ data }) => {
        this.questions.set(data);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.failed.set(true);
      },
    });
  }

  // --- the form -------------------------------------------------------------

  openNew(): void {
    this.editing.set(null);
    this.draft.set(this.blank());
    this.formError.set(null);
    this.formOpen.set(true);
  }

  edit(question: EventQuestion): void {
    this.editing.set(question);
    this.draft.set({
      label: question.label,
      type: question.type,
      required: question.required,
      perAttendee: question.per_attendee,
      options: question.options.length > 0 ? [...question.options] : ['', ''],
    });
    this.formError.set(null);
    this.formOpen.set(true);
  }

  update<K extends keyof QuestionDraft>(key: K, value: QuestionDraft[K]): void {
    this.draft.set({ ...this.draft(), [key]: value });
    this.formError.set(null);
  }

  /** The asked-once / asked-of-each choice, which is a boolean underneath. */
  setAsked(value: string): void {
    this.update('perAttendee', value === 'attendee');
  }

  setOption(index: number, value: string): void {
    const options = [...this.draft().options];
    options[index] = value;
    this.update('options', options);
  }

  addOption(): void {
    this.update('options', [...this.draft().options, '']);
  }

  removeOption(index: number): void {
    this.update(
      'options',
      this.draft().options.filter((_, at) => at !== index),
    );
  }

  save(): void {
    if (!this.canSave() || this.saving()) return;

    const draft = this.draft();
    const editing = this.editing();

    const body = {
      label: draft.label.trim(),
      type: draft.type,
      required: draft.required,
      per_attendee: draft.perAttendee,
      options: this.choosing() ? this.options() : null,
    };

    this.saving.set(true);
    this.formError.set(null);

    const request = editing
      ? this.api.updateEventQuestion(this.eventId, editing.id, body)
      : this.api.createEventQuestion(this.eventId, body);

    request.subscribe({
      next: ({ data }) => {
        this.saving.set(false);
        this.formOpen.set(false);

        this.questions.set(
          editing
            ? this.questions().map((q) => (q.id === data.id ? data : q))
            : [...this.questions(), data],
        );

        this.toasts.show(editing ? 'Saved.' : 'Added. The checkout asks it now.', 'success');
      },
      error: (response) => {
        this.saving.set(false);
        this.formError.set(messageFor(response, 'That could not be saved.'));
      },
    });
  }

  // --- the order they are asked in -----------------------------------------

  move(question: EventQuestion, direction: -1 | 1): void {
    const order = this.questions().map((q) => q.id);
    const from = order.indexOf(question.id);
    const to = from + direction;

    if (from < 0 || to < 0 || to >= order.length) return;

    [order[from], order[to]] = [order[to], order[from]];

    // Shown before the server agrees: a row that does not move when the arrow
    // is pressed reads as a broken arrow.
    const before = this.questions();
    this.questions.set(order.map((id) => before.find((q) => q.id === id)!));
    this.savingOrder.set(true);

    this.api.reorderEventQuestions(this.eventId, order).subscribe({
      next: ({ data }) => {
        this.questions.set(data);
        this.savingOrder.set(false);
      },
      error: () => {
        this.questions.set(before);
        this.savingOrder.set(false);
        this.toasts.show('That order could not be saved.', 'danger');
      },
    });
  }

  // --- removing -------------------------------------------------------------

  /** What removing it does, said before it is done. */
  consequence(question: EventQuestion): string {
    return question.answered
      ? 'The checkout stops asking it. The answers people have already given are kept, and stay on your guest list and export.'
      : 'The checkout stops asking it.';
  }

  confirmRemove(): void {
    const question = this.removing();
    if (!question) return;

    this.api.deleteEventQuestion(this.eventId, question.id).subscribe({
      next: ({ message }) => {
        this.questions.set(this.questions().filter((q) => q.id !== question.id));
        this.removing.set(null);
        this.toasts.show(message, 'success');
      },
      error: (response) => {
        this.removing.set(null);
        this.toasts.show(messageFor(response, 'That could not be removed.'), 'danger');
      },
    });
  }

  // --- how a row reads ------------------------------------------------------

  kind(question: EventQuestion): string {
    return this.typeOptions.find((option) => option.value === question.type)?.label ?? question.type;
  }

  private blank(): QuestionDraft {
    return { label: '', type: 'text', required: false, perAttendee: false, options: ['', ''] };
  }
}
