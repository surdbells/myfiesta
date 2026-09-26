import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { COUNTRIES } from '@myfiesta/shared/places';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { Navigation } from '../../core/navigation';
import { fieldErrors, messageOf } from '../../core/errors';
import { Dialogs, MfButton, MfScreen, ToastStore } from '../../ui';
import { MfEventForm, blankEvent, bodyOf, type EventDraft } from './event-form';

/**
 * A new event, from the phone.
 *
 * It starts as a draft: nothing is public until it is put on sale from the
 * event's own screen, which is where this lands — with tickets the obvious
 * next thing to add.
 */
@Component({
  selector: 'mf-event-create',
  imports: [MfScreen, MfEventForm, MfButton],
  template: `
    <mf-screen title="New event" subtitle="Starts as a draft — nothing is public yet" back task (backed)="leave()">
      @if (formError(); as message) {
        <p class="form-error" role="alert">{{ message }}</p>
      }
      <mf-event-form creating [draft]="draft()" (draftChange)="draft.set($event)" [errors]="errors()" [categories]="categories()" />

      <div screenFooter class="footer">
        <button mfButton block [loading]="saving()" [disabled]="!valid()" (click)="create()">Make the draft</button>
      </div>
    </mf-screen>
  `,
  styles: `
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
export class EventCreate implements OnInit {
  private readonly organizer = inject(Organizer);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);
  private readonly nav = inject(Navigation);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  protected readonly draft = signal<EventDraft>(blankEvent());
  protected readonly categories = signal<string[]>([]);
  protected readonly saving = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly errors = signal<Record<string, string>>({});

  protected readonly valid = computed(() => {
    const d = this.draft();
    return d.title.trim() !== '' && d.city.trim() !== '' && d.startsAt !== '' && !this.saving();
  });

  private readonly touched = computed(() => JSON.stringify(this.draft()) !== JSON.stringify(blankEvent()));

  ngOnInit(): void {
    void this.organizer.categories().then((c) => this.categories.set(c)).catch(() => undefined);
  }

  protected async create(): Promise<void> {
    const organization = this.session.organization();
    const d = this.draft();

    if (!organization || !this.valid()) return;

    this.saving.set(true);
    this.formError.set(null);
    this.errors.set({});

    try {
      const made = await this.organizer.createEvent({
        ...bodyOf(d),
        organization_id: organization.id,
        kind: d.kind,
        description: d.description.trim() || null,
        // Derived, never chosen, and permanent once the event exists.
        currency: COUNTRIES.find((c) => c.code === d.country)?.currency ?? 'CAD',
      });

      const id = made.data?.id ?? made.id;
      this.toasts.show('Draft made. Add its tickets next.', 'success');
      await this.router.navigate(id ? ['/manage/events', id] : ['/manage/events'], { replaceUrl: true });
    } catch (error) {
      this.errors.set(fieldErrors(error));
      this.formError.set(Object.keys(this.errors()).length ? 'Some of that needs another look.' : messageOf(error, 'That event could not be made.'));
    } finally {
      this.saving.set(false);
    }
  }

  protected async leave(): Promise<void> {
    if (this.touched()) {
      const discard = await this.dialogs.confirm({ title: 'Throw this away?', message: 'Nothing has been saved yet.', confirm: 'Throw away', danger: true });
      if (!discard) return;
    }

    this.nav.back('/manage/events');
  }
}
