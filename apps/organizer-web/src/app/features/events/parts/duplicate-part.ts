import { Component, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ConfirmDialog, UiButton, UiField } from '@myfiesta/ui';
import { firstValueFrom, tap } from 'rxjs';
import { Api } from '../../../core/api';
import type { EventCopy, OrganizerEventDetail } from '../../../core/api.types';
import { messageFor } from '../../../core/errors';
import { longEventTime } from '../../../core/event-time';
import { SessionStore } from '../../../core/session';
import { CopyDialog, type CopyChoice, type CopyTier } from '../../templates/copy-dialog';
import { TemplatesApi } from '../../templates/templates-api';

/**
 * Copying an event with adjustments — its date, title, which tiers and at
 * what price — and keeping it as a template, on its Overview.
 *
 * The CLONE track's own file. The Overview places it once, beside the quick
 * "Same event, new date" copy, and never edits it again, so the feature fills
 * this in without touching the page. A copy is a new event, so the part goes
 * to it itself; nothing here changes.
 *
 * Whoever may create events may do both, as the API has it: a copy and a
 * template are ways of making events, and neither changes this one.
 *
 * `contents`, so a part with nothing to offer adds no box and no gap.
 */
@Component({
  selector: 'app-duplicate-part',
  host: { class: 'contents' },
  imports: [FormsModule, RouterLink, UiButton, UiField, CopyDialog],
  templateUrl: './duplicate-part.html',
})
export class DuplicatePart {
  readonly event = input.required<OrganizerEventDetail>();

  private readonly api = inject(Api);
  private readonly templates = inject(TemplatesApi);
  private readonly confirmDialog = inject(ConfirmDialog);
  private readonly session = inject(SessionStore);

  readonly allowed = computed(() => this.session.can('events.create'));

  readonly copyOpen = signal(false);
  readonly tiers = signal<CopyTier[]>([]);
  readonly loadingTiers = signal(false);
  readonly copyError = signal<string | null>(null);
  readonly copying = signal(false);
  /** The draft just made, to open from here. */
  readonly made = signal<EventCopy | null>(null);

  readonly naming = signal(false);
  readonly templateName = signal('');
  readonly keeping = signal(false);
  readonly keepError = signal<string | null>(null);
  readonly kept = signal<string | null>(null);

  /** How long the night ran, so the dialog can say what an empty end means. */
  readonly lengthMinutes = computed(() => {
    const event = this.event();

    if (!event.ends_at) return null;

    return Math.round((Date.parse(event.ends_at) - Date.parse(event.starts_at)) / 60000);
  });

  /**
   * The dialog, with this event's tiers as they are now.
   *
   * Read on opening rather than kept from the page: somebody who renamed a
   * tier in the next tab should see that name here.
   */
  async openCopy(): Promise<void> {
    if (this.loadingTiers()) return;

    this.made.set(null);
    this.copyError.set(null);
    this.loadingTiers.set(true);

    try {
      const { data } = await firstValueFrom(this.api.ticketTypes(this.event().id));

      this.tiers.set(
        data.map((type) => ({
          id: type.id,
          name: type.name,
          price: type.price,
          quantity_available: type.quantity_available,
        })),
      );
      this.copyOpen.set(true);
    } catch (error) {
      this.copyError.set(messageFor(error, 'The tickets on this event could not be read, so it cannot be copied yet.'));
    } finally {
      this.loadingTiers.set(false);
    }
  }

  /** Said back, then made. The dialog stays open underneath if the answer is no. */
  async copy(choice: CopyChoice): Promise<void> {
    const event = this.event();

    if (this.copying()) return;

    this.copying.set(true);

    let made: EventCopy | null = null;

    const done = await this.confirmDialog.confirm({
      title: `Make ${choice.title} on ${longEventTime(choice.changes.starts_at, event.timezone)}?`,
      body: 'A new draft is made from this event, with the changes below.',
      consequences: [
        ...(choice.changed.length > 0 ? choice.changed : ['Nothing else changes: it is this event on a new date.']),
        'Sales, codes and the gallery stay with this one.',
        'The copy goes on sale only after you submit it for review.',
      ],
      confirmLabel: 'Make the copy',
      busyLabel: 'Copying…',
      tone: 'default',
      run: () => this.templates.duplicate(event.id, choice.changes).pipe(tap((copy) => (made = copy))),
      failure: (response) => messageFor(response, 'That event could not be copied.'),
    });

    this.copying.set(false);

    if (!done) return;

    this.copyOpen.set(false);
    this.made.set(made);
  }

  startNaming(): void {
    this.kept.set(null);
    this.keepError.set(null);
    this.templateName.set(this.event().title);
    this.naming.set(true);
  }

  /**
   * Kept straight away, without a question: keeping a template changes
   * nothing anybody can buy, and it can be deleted from Templates.
   */
  async keep(): Promise<void> {
    const name = this.templateName().trim();

    if (this.keeping()) return;

    if (name.length < 2) {
      this.keepError.set('Give the template a name of two letters or more.');

      return;
    }

    this.keepError.set(null);
    this.keeping.set(true);

    try {
      const { data } = await firstValueFrom(this.templates.keep(this.event().id, name));

      this.naming.set(false);
      this.kept.set(data.name);
    } catch (error) {
      this.keepError.set(messageFor(error, 'That template could not be kept.'));
    } finally {
      this.keeping.set(false);
    }
  }
}
