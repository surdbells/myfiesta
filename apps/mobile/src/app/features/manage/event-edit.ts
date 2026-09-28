import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import type { OrganizerEventDetail } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { Navigation } from '../../core/navigation';
import { fieldErrors, messageOf } from '../../core/errors';
import { longEventTime } from '../../core/event-time';
import { Dialogs, MfButton, MfCard, MfEmpty, MfScreen, MfSkeleton, ToastStore } from '../../ui';
import { EventContext } from './event-context';
import { MfEventForm, bodyOf, draftOf, type EventDraft } from './event-form';
import { MfReviewLock, lockedForReview } from './event-review';

/**
 * Changing an event's details: name, time, place, words.
 *
 * Only what changed is sent. A description written with formatting on the web
 * is kept exactly as it was unless its words are edited here, and leaving
 * with unsaved changes asks first.
 */
@Component({
  selector: 'mf-event-edit',
  imports: [MfScreen, MfEventForm, MfButton, MfCard, MfEmpty, MfSkeleton, MfReviewLock],
  template: `
    <mf-screen title="Details" [subtitle]="event()?.title ?? null" back task (backed)="leave()">
      @if (error(); as message) {
        <mf-empty title="Could not load this event" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (draft(); as d) {
        @if (locked()) {
          <mf-review-lock [eventId]="id()" />
        }
        <p class="fixed">Sold in {{ event()!.currency }} at <code>/{{ event()!.slug }}</code>. Neither can change — orders are in that currency, and the link is already out there.</p>
        @if (formError(); as message) {
          <p class="form-error" role="alert">{{ message }}</p>
        }
        <!-- Shown as it stands while myFiesta reviews it; every field inside is switched off. -->
        <fieldset class="frozen" [disabled]="locked()">
          <mf-event-form [draft]="d" (draftChange)="draft.set($event)" [errors]="errors()" [categories]="categories()" [original]="event()!.description" />
        </fieldset>
      } @else {
        <mf-card><mf-skeleton height="12rem" /></mf-card>
      }

      <div screenFooter class="footer">
        <button mfButton block [loading]="saving()" [disabled]="locked() || !changed() || !valid()" (click)="save()">Save changes</button>
      </div>
    </mf-screen>
  `,
  styles: `
    .frozen {
      min-width: 0;
      margin: 0;
      padding: 0;
      border: 0;
    }

    .fixed {
      margin-bottom: var(--space-4);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    code {
      font-family: var(--font-family-mono);
    }

    .form-error {
      margin-bottom: var(--space-4);
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-lg);
      background: color-mix(in srgb, var(--danger) 10%, transparent);
      color: var(--danger-text);
      font-size: var(--font-size-sm);
    }

    .footer {
      display: grid;
    }
  `,
})
export class EventEdit implements OnInit {
  readonly id = input.required<string>();

  private readonly organizer = inject(Organizer);
  private readonly context = inject(EventContext);
  private readonly nav = inject(Navigation);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  protected readonly event = signal<OrganizerEventDetail | null>(null);
  protected readonly draft = signal<EventDraft | null>(null);
  protected readonly categories = signal<string[]>([]);
  protected readonly error = signal<string | null>(null);
  protected readonly saving = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly errors = signal<Record<string, string>>({});

  private readonly initial = signal<EventDraft | null>(null);

  /** Waiting for myFiesta's review: what staff are looking at is what goes on sale, so it stays as it is. */
  protected readonly locked = computed(() => lockedForReview(this.event()));

  protected readonly changed = computed(() => {
    const now = this.draft();
    const was = this.initial();
    return !!now && !!was && JSON.stringify(now) !== JSON.stringify(was);
  });

  protected readonly valid = computed(() => {
    const d = this.draft();
    return !!d && d.title.trim() !== '' && d.city.trim() !== '' && d.startsAt !== '' && !this.saving();
  });

  ngOnInit(): void {
    void this.organizer.categories().then((c) => this.categories.set(c)).catch(() => undefined);
    void this.load();
  }

  async load(): Promise<void> {
    this.error.set(null);

    try {
      const event = await this.context.get(this.id(), true);
      this.event.set(event);
      this.draft.set(draftOf(event));
      this.initial.set(draftOf(event));
    } catch (error) {
      this.error.set(messageOf(error));
    }
  }

  protected async save(): Promise<void> {
    const d = this.draft();
    const was = this.initial();
    if (!d || !was || !this.valid() || this.locked()) return;

    const body: Record<string, unknown> = { ...bodyOf(d), resale_enabled: d.resaleEnabled };

    // Untouched words are not sent, so formatting written on the web survives
    // a change of start time made on the phone.
    if (d.description !== was.description) body['description'] = d.description.trim() || null;

    // On sale, an edit is what buyers read the moment it is saved, with no
    // review in between, and a moved date is not emailed to anybody who
    // already holds a ticket.
    const event = this.event();
    const onSale = event?.status === 'published';
    const moved = d.startsAt !== was.startsAt || d.timezone !== was.timezone;
    const starts = body['starts_at'] as string | null;
    const consequences: string[] = [];

    if (moved && starts) consequences.push(`It now starts ${longEventTime(starts, d.timezone)}.`);
    if (moved && (event?.tickets_issued ?? 0) > 0) {
      consequences.push('People who already hold tickets are not emailed about the new time. Tell them from Messages.');
    }
    if (onSale) consequences.push('Taking it off sale and putting it back later sends it through review, since it is no longer what was approved.');

    const sure = await this.dialogs.confirm({
      title: `Save the changes to ${d.title.trim()}?`,
      body: onSale
        ? 'It is on sale: the event page shows the changes straight away, to everybody who opens it.'
        : 'The changes are saved to the draft. Nobody sees them until it is approved and on sale.',
      consequences,
      confirmLabel: 'Save changes',
      tone: 'default',
    });

    if (!sure || this.saving()) return;

    this.saving.set(true);
    this.formError.set(null);
    this.errors.set({});

    try {
      await this.organizer.updateEvent(this.id(), body);
      const fresh = await this.context.get(this.id(), true);
      this.event.set(fresh);
      this.draft.set(draftOf(fresh));
      this.initial.set(draftOf(fresh));
      this.toasts.show('Saved.', 'success');
    } catch (error) {
      this.errors.set(fieldErrors(error));
      this.formError.set(Object.keys(this.errors()).length ? 'Some of that needs another look.' : messageOf(error, 'Those changes could not be saved.'));
    } finally {
      this.saving.set(false);
    }
  }

  protected async leave(): Promise<void> {
    if (this.changed()) {
      const discard = await this.dialogs.confirm({
        title: 'Leave without saving?',
        body: 'What you changed here will be lost.',
        confirmLabel: 'Leave without saving',
        cancelLabel: 'Keep editing',
        tone: 'danger',
      });

      if (!discard) return;
    }

    this.nav.back(`/manage/events/${this.id()}`);
  }
}
