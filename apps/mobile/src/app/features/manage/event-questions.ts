import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { ArrowDown, ArrowUp, ListChecks, MoreHorizontal, Pencil, Plus, SquareCheck, Trash2, Type, X } from 'lucide-angular';
import type { EventQuestion, OrganizerEventDetail } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { fieldErrors, messageOf } from '../../core/errors';
import {
  Dialogs,
  MfBadge,
  MfButton,
  MfCard,
  MfCheck,
  MfChoices,
  MfEmpty,
  MfField,
  MfIcon,
  MfIconButton,
  MfScreen,
  MfSheet,
  MfSkeleton,
  ToastStore,
  type MfChoice,
} from '../../ui';
import { EventContext } from './event-context';
import { MfReviewLock, lockedForReview } from './event-review';

type Kind = EventQuestion['type'];

interface Draft {
  label: string;
  type: Kind;
  options: string[];
  required: boolean;
  perAttendee: boolean;
}

const BLANK: Draft = { label: '', type: 'text', options: ['', ''], required: false, perAttendee: false };

const KIND_LABELS: Record<Kind, string> = {
  text: 'Written answer',
  choice: 'One of a list',
  multi_choice: 'Several of a list',
  boolean: 'Yes or no',
};

/**
 * What a buyer is asked at checkout.
 *
 * The name to check against an ID, the table they are with, what they cannot
 * eat. Asked once per order or of every guest, and read back at the door —
 * so the one thing this screen protects is that a question already answered
 * is not quietly turned into a different question under the answers.
 */
@Component({
  selector: 'mf-event-questions',
  imports: [MfScreen, MfIconButton, MfIcon, MfCard, MfBadge, MfButton, MfEmpty, MfSkeleton, MfSheet, MfField, MfChoices, MfCheck, MfReviewLock],
  template: `
    <mf-screen title="Questions at checkout" [subtitle]="event()?.title ?? null" back [backTo]="'/manage/events/' + id()" refreshable [busy]="loading()" (refresh)="load()">
      <button mfIconButton screenActions tone="tonal" [icon]="plusIcon" label="Add a question" [disabled]="locked()" (click)="startNew()"></button>

      @if (locked()) {
        <mf-review-lock [eventId]="id()" />
      }

      @if (questions(); as all) {
        @if (all.length === 0) {
          <mf-empty title="Nothing is asked" hint="Buyers give a name and an email. Ask for more here — a name for each guest, a table, what they cannot eat.">
            <button mfButton [disabled]="locked()" (click)="startNew()">Add a question</button>
          </mf-empty>
        } @else {
          <ul class="items">
            @for (q of all; track q.id; let first = $first; let last = $last) {
              <li>
                <mf-card [tappable]="!locked()" (click)="edit(q)">
                  <div class="top">
                    <p class="label">{{ q.label }}</p>
                    @if (!locked()) {
                      <button mfIconButton size="sm" [icon]="moreIcon" [label]="'More for ' + q.label" (click)="$event.stopPropagation(); menu(q, first, last)"></button>
                    }
                  </div>
                  <div class="meta">
                    <span>{{ kinds[q.type] }}</span>
                    @if (q.required) {
                      <mf-badge tone="warning">Required</mf-badge>
                    }
                    <mf-badge>{{ q.per_attendee ? 'Each guest' : 'Once per order' }}</mf-badge>
                    @if (q.answered) {
                      <mf-badge tone="success">Answered</mf-badge>
                    }
                  </div>
                  @if (q.options.length) {
                    <p class="opts">{{ q.options.join(' · ') }}</p>
                  }
                </mf-card>
              </li>
            }
          </ul>
        }
      } @else if (error(); as message) {
        <mf-empty title="Could not load the questions" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else {
        <mf-card><mf-skeleton height="3.5rem" /></mf-card>
      }
    </mf-screen>

    <mf-sheet
      [open]="formOpen()"
      [heading]="editing() ? 'Edit question' : 'New question'"
      [subheading]="editing()?.answered ? 'Already answered, so its kind is fixed — changing it would change what the answers mean.' : null"
      closable
      (closed)="formOpen.set(false)"
    >
      <div class="form">
        @if (formError(); as message) {
          <p class="form-error" role="alert">{{ message }}</p>
        }
        <mf-field label="The question" [error]="err('label')">
          <input [value]="draft().label" (input)="set('label', $any($event.target).value)" placeholder="Name on your ID" maxlength="200" />
        </mf-field>

        @if (!editing()?.answered) {
          <mf-choices legend="How it is answered" [options]="kindChoices" [value]="draft().type" (valueChange)="set('type', $any($event))" />
        }

        @if (choosing()) {
          <div class="options">
            <p class="group-label">The choices</p>
            @for (option of draft().options; track $index; let i = $index) {
              <div class="option">
                <mf-field [label]="'Choice ' + (i + 1)">
                  <input [value]="option" (input)="setOption(i, $any($event.target).value)" [placeholder]="i === 0 ? 'Vegetarian' : 'Something else'" />
                </mf-field>
                @if (draft().options.length > 2) {
                  <button mfIconButton size="sm" [icon]="removeIcon" [label]="'Remove choice ' + (i + 1)" (click)="removeOption(i)"></button>
                }
              </div>
            }
            @if (err('options'); as message) {
              <p class="form-error">{{ message }}</p>
            }
            <button mfButton variant="ghost" size="sm" (click)="addOption()"><mf-icon [icon]="plusIcon" size="sm" /> Add a choice</button>
          </div>
        }

        <mf-check label="Required" hint="Checkout does not finish without an answer." [value]="draft().required" (valueChange)="set('required', $event)" />
        <mf-check label="Ask each guest" hint="A group of four answers four times — once each, by name." [value]="draft().perAttendee" (valueChange)="set('perAttendee', $event)" />
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="formOpen.set(false)">Cancel</button>
        <button mfButton [loading]="saving()" [disabled]="!canSave()" (click)="save()">{{ editing() ? 'Save' : 'Add' }}</button>
      </ng-container>
    </mf-sheet>
  `,
  styles: `
    .items {
      display: grid;
      gap: var(--space-3);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .top {
      display: flex;
      align-items: flex-start;
      gap: var(--space-2);
    }

    .label {
      flex: 1;
      font-weight: var(--font-weight-semibold);
      line-height: 1.35;
    }

    .meta {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: var(--space-2);
      margin-top: var(--space-2);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .opts {
      margin-top: var(--space-2);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .form,
    .options {
      display: grid;
      gap: var(--space-4);
    }

    .options {
      gap: var(--space-3);
    }

    .option {
      display: flex;
      align-items: flex-end;
      gap: var(--space-2);
    }

    .option mf-field {
      flex: 1;
    }

    .group-label {
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-semibold);
    }

    .form-error {
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-lg);
      background: color-mix(in srgb, var(--danger) 10%, transparent);
      color: var(--danger-text);
      font-size: var(--font-size-sm);
    }
  `,
})
export class EventQuestions implements OnInit {
  readonly id = input.required<string>();

  private readonly organizer = inject(Organizer);
  private readonly context = inject(EventContext);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  protected readonly event = signal<OrganizerEventDetail | null>(null);
  protected readonly questions = signal<EventQuestion[] | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  /** Waiting for myFiesta's review: the questions are shown, and none of them can change. */
  protected readonly locked = computed(() => lockedForReview(this.event()));

  protected readonly formOpen = signal(false);
  protected readonly editing = signal<EventQuestion | null>(null);
  protected readonly draft = signal<Draft>({ ...BLANK, options: ['', ''] });
  protected readonly saving = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly errors = signal<Record<string, string>>({});

  protected readonly plusIcon = Plus;
  protected readonly moreIcon = MoreHorizontal;
  protected readonly removeIcon = X;
  protected readonly kinds = KIND_LABELS;

  protected readonly kindChoices: MfChoice[] = [
    { value: 'text', label: KIND_LABELS.text, icon: Type },
    { value: 'choice', label: KIND_LABELS.choice, icon: ListChecks },
    { value: 'multi_choice', label: KIND_LABELS.multi_choice, icon: ListChecks },
    { value: 'boolean', label: KIND_LABELS.boolean, icon: SquareCheck },
  ];

  protected readonly choosing = computed(() => this.draft().type === 'choice' || this.draft().type === 'multi_choice');

  protected readonly canSave = computed(() => {
    const d = this.draft();
    const options = d.options.map((o) => o.trim()).filter(Boolean);

    return d.label.trim() !== '' && (!this.choosing() || options.length >= 2) && !this.saving();
  });

  ngOnInit(): void {
    this.event.set(this.context.peek(this.id()));
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      const [event, questions] = await Promise.all([this.context.get(this.id()), this.organizer.questions(this.id())]);
      this.event.set(event);
      this.questions.set(questions);
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected err(field: string): string | null {
    return this.errors()[field] ?? this.errors()[`${field}.0`] ?? null;
  }

  protected set<K extends keyof Draft>(key: K, value: Draft[K]): void {
    this.draft.update((d) => ({ ...d, [key]: value }));
  }

  protected setOption(index: number, value: string): void {
    this.draft.update((d) => ({ ...d, options: d.options.map((o, i) => (i === index ? value : o)) }));
  }

  protected addOption(): void {
    this.draft.update((d) => ({ ...d, options: [...d.options, ''] }));
  }

  protected removeOption(index: number): void {
    this.draft.update((d) => ({ ...d, options: d.options.filter((_, i) => i !== index) }));
  }

  protected startNew(): void {
    if (this.locked()) return;

    this.editing.set(null);
    this.draft.set({ ...BLANK, options: ['', ''] });
    this.formError.set(null);
    this.errors.set({});
    this.formOpen.set(true);
  }

  protected edit(q: EventQuestion): void {
    if (this.locked()) return;

    this.editing.set(q);
    this.draft.set({
      label: q.label,
      type: q.type,
      options: q.options.length ? [...q.options] : ['', ''],
      required: q.required,
      perAttendee: q.per_attendee,
    });
    this.formError.set(null);
    this.errors.set({});
    this.formOpen.set(true);
  }

  protected async save(): Promise<void> {
    if (!this.canSave()) return;

    const d = this.draft();
    const body = {
      label: d.label.trim(),
      type: d.type,
      required: d.required,
      per_attendee: d.perAttendee,
      options: this.choosing() ? d.options.map((o) => o.trim()).filter(Boolean) : null,
    };

    // A required question is one more thing between a buyer and paying, so
    // who is asked, and whether they may skip it, is said on adding it.
    const editing = this.editing();
    const sure = await this.dialogs.confirm({
      title: editing ? 'Save the changes to this question?' : 'Add this question to the checkout?',
      body: `“${body.label}” is asked ${body.per_attendee ? 'about each person' : 'once for the whole order'}, ${body.required ? 'and must be answered before paying' : 'and can be skipped'}.`,
      consequences: editing ? ['Answers already given stay as they were given.'] : [],
      confirmLabel: editing ? 'Save changes' : 'Add the question',
      tone: 'default',
    });

    if (!sure || this.saving()) return;

    this.saving.set(true);
    this.formError.set(null);
    this.errors.set({});

    try {
      if (editing) await this.organizer.updateQuestion(this.id(), editing.id, body);
      else await this.organizer.createQuestion(this.id(), body);

      this.formOpen.set(false);
      this.toasts.show(editing ? 'Saved.' : 'Question added.', 'success');
      await this.load();
    } catch (error) {
      this.errors.set(fieldErrors(error));
      this.formError.set(Object.keys(this.errors()).length ? null : messageOf(error));
    } finally {
      this.saving.set(false);
    }
  }

  protected async menu(q: EventQuestion, first: boolean, last: boolean): Promise<void> {
    if (this.locked()) return;

    const chosen = await this.dialogs.menu({
      title: q.label,
      actions: [
        { key: 'edit', label: 'Edit', icon: Pencil },
        { key: 'up', label: 'Ask it earlier', icon: ArrowUp, disabled: first },
        { key: 'down', label: 'Ask it later', icon: ArrowDown, disabled: last },
        { key: 'delete', label: 'Delete', icon: Trash2, danger: true, hint: q.answered ? 'Answers already given are kept on their orders' : undefined },
      ],
    });

    try {
      switch (chosen) {
        case 'edit':
          this.edit(q);
          return;
        case 'up':
        case 'down': {
          const ids = (this.questions() ?? []).map((x) => x.id);
          const at = ids.indexOf(q.id);
          const to = at + (chosen === 'up' ? -1 : 1);
          [ids[at], ids[to]] = [ids[to], ids[at]];
          this.questions.set(await this.organizer.reorderQuestions(this.id(), ids));
          return;
        }
        case 'delete':
          if (
            !(await this.dialogs.confirm({
              title: 'Delete this question?',
              body: `The checkout stops asking “${q.label}”.`,
              consequences: q.answered ? ['Answers already given are kept on their orders.'] : [],
              confirmLabel: 'Delete the question',
              tone: 'danger',
            }))
          ) {
            return;
          }
          await this.organizer.deleteQuestion(this.id(), q.id);
          this.toasts.show('Deleted.', 'success');
          await this.load();
          return;
      }
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
    }
  }
}
