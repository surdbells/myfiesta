import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ConfirmDialog, UiButton, UiEmpty, UiErrorState, UiPageHeader, UiSkeleton } from '@myfiesta/ui';
import { tap } from 'rxjs';
import type { EventCopy, EventTemplate } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { eventDate, longEventTime } from '../../core/event-time';
import { loadList } from '../../core/list-loader';
import { formatMoney } from '../../core/money';
import { SessionStore } from '../../core/session';
import { CopyDialog, type CopyChoice } from './copy-dialog';
import { TemplatesApi } from './templates-api';

/**
 * Event templates: nights kept as a starting point, and new events made from
 * them (EventTemplates on the API says what one keeps).
 *
 * Kept from an event's Overview ("Keep as a template"), so this screen only
 * lists, starts from and deletes them. Starting from one asks the same
 * questions as copying an event with changes (CopyDialog), with a date
 * required: a template has no night of its own to default to.
 */
@Component({
  selector: 'app-templates',
  imports: [RouterLink, UiButton, UiEmpty, UiErrorState, UiPageHeader, UiSkeleton, CopyDialog],
  templateUrl: './templates.html',
})
export class Templates {
  private readonly templatesApi = inject(TemplatesApi);
  private readonly confirmDialog = inject(ConfirmDialog);
  private readonly session = inject(SessionStore);

  /** Asked again when the organization being worked in changes. */
  private readonly organization = computed(() => this.session.current()?.id ?? null);
  private readonly loader = loadList(this.organization, () => this.templatesApi.templates());

  readonly templates = computed<EventTemplate[] | null>(() => this.loader.result()?.data ?? null);
  readonly loading = this.loader.loading;
  readonly failed = this.loader.failed;
  readonly refused = this.loader.refused;

  /** The template an event is being made from, while the dialog is open. */
  readonly using = signal<EventTemplate | null>(null);
  readonly making = signal(false);
  /** The draft just made, to open from here. */
  readonly made = signal<EventCopy | null>(null);
  readonly notice = signal<string | null>(null);

  readonly formatMoney = formatMoney;

  load(): void {
    this.loader.retry();
  }

  /** "Early bird $15.00 · General $25.00", short enough for a row. */
  tierLine(template: EventTemplate): string {
    if (template.ticket_types.length === 0) return 'No tickets';

    return template.ticket_types.map((type) => `${type.name} ${formatMoney(type.price)}`).join(' · ');
  }

  /** "2 extras, 1 question, 1 reminder", leaving out what it does not keep. */
  keepsLine(template: EventTemplate): string | null {
    const parts = [
      this.count(template.add_ons, 'extra', 'extras'),
      this.count(template.questions, 'question', 'questions'),
      this.count(template.reminders, 'reminder', 'reminders'),
    ].filter((part): part is string => part !== null);

    return parts.length > 0 ? parts.join(', ') : null;
  }

  keptLine(template: EventTemplate): string {
    const when = eventDate(template.created_at, template.timezone);

    return template.created_by ? `Kept by ${template.created_by} on ${when}` : `Kept on ${when}`;
  }

  startFrom(template: EventTemplate): void {
    this.made.set(null);
    this.notice.set(null);
    this.using.set(template);
  }

  /** Said back, then made. The dialog stays open underneath if the answer is no. */
  async make(template: EventTemplate, choice: CopyChoice): Promise<void> {
    if (this.making()) return;

    this.making.set(true);

    let made: EventCopy | null = null;

    const done = await this.confirmDialog.confirm({
      title: `Make ${choice.title} on ${longEventTime(choice.changes.starts_at, template.timezone)}?`,
      body: `A new draft is made from the template “${template.name}”${choice.changed.length > 0 ? ', with the changes below' : ''}.`,
      consequences: [...choice.changed, 'It goes on sale only after you submit it for review.'],
      confirmLabel: 'Make the event',
      busyLabel: 'Making it…',
      tone: 'default',
      run: () => this.templatesApi.createEvent(template.id, choice.changes).pipe(tap((event) => (made = event))),
      failure: (response) => messageFor(response, 'An event could not be made from that template.'),
    });

    this.making.set(false);

    if (!done) return;

    this.using.set(null);
    this.made.set(made);
  }

  /** Asked first: a deleted template cannot be brought back. */
  async remove(template: EventTemplate): Promise<void> {
    this.made.set(null);
    this.notice.set(null);

    const done = await this.confirmDialog.confirm({
      title: `Delete the template “${template.name}”?`,
      body: 'It goes for everybody in your team, with its copy of the poster. It cannot be brought back.',
      consequences: ['Events already made from it keep everything they have.'],
      confirmLabel: 'Delete template',
      busyLabel: 'Deleting…',
      tone: 'danger',
      run: () => this.templatesApi.delete(template.id),
      failure: (response) => messageFor(response, 'That template could not be deleted.'),
    });

    if (!done) return;

    this.notice.set(`Deleted “${template.name}”.`);
    this.loader.retry();
  }

  private count(n: number, one: string, many: string): string | null {
    if (n === 0) return null;

    return `${n} ${n === 1 ? one : many}`;
  }
}
