import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { BellPlus } from 'lucide-angular';
import type { OrganizerEventDetail, Reminder } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { messageOf } from '../../core/errors';
import { longEventTime } from '../../core/event-time';
import { Dialogs, MfBadge, MfButton, MfCard, MfEmpty, MfIconButton, MfList, MfRow, MfScreen, MfSkeleton, ToastStore } from '../../ui';
import { EventContext } from './event-context';

/** The moments a reminder can go out, as the console offers them. */
const CHOICES = [
  { minutes: 20160, label: '2 weeks before' },
  { minutes: 10080, label: '1 week before' },
  { minutes: 2880, label: '2 days before' },
  { minutes: 1440, label: 'The day before' },
  { minutes: 360, label: '6 hours before' },
  { minutes: 180, label: '3 hours before' },
  { minutes: 60, label: '1 hour before' },
];

/**
 * Emails that go to every ticket holder ahead of the night, on a schedule.
 *
 * When each goes out is shown in the event's zone with its abbreviation: an
 * organizer in Lagos running a Toronto night should not have to work out
 * which of two clocks is lying.
 */
@Component({
  selector: 'mf-event-reminders',
  imports: [MfScreen, MfIconButton, MfCard, MfBadge, MfButton, MfEmpty, MfSkeleton, MfList, MfRow],
  template: `
    <mf-screen title="Reminders" [subtitle]="event()?.title ?? null" back [backTo]="'/manage/events/' + id()" refreshable [busy]="loading()" (refresh)="load()">
      <button mfIconButton screenActions tone="tonal" [icon]="addIcon" label="Add a reminder" [disabled]="available().length === 0" (click)="add()"></button>

      @if (error(); as message) {
        <mf-empty title="Could not load the reminders" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (reminders(); as all) {
        @if (live().length === 0) {
          <mf-empty title="No reminders" hint="The day before is the one that matters: it is when people check how they are getting there.">
            <button mfButton (click)="add()">Add a reminder</button>
          </mf-empty>
        } @else {
          <mf-list heading="Scheduled" footer="Each goes to everybody holding a ticket at the time it sends.">
            @for (reminder of live(); track reminder.id) {
              <mf-row
                [label]="reminder.label"
                [sub]="when(reminder)"
                [action]="reminder.status === 'scheduled'"
                [chevron]="false"
                (pressed)="cancel(reminder)"
              >
                <mf-badge [tone]="reminder.status === 'sent' ? 'success' : 'neutral'">{{ statusOf(reminder) }}</mf-badge>
              </mf-row>
            }
          </mf-list>
        }
      } @else {
        <mf-card><mf-skeleton height="4rem" /></mf-card>
      }
    </mf-screen>
  `,
})
export class EventReminders implements OnInit {
  readonly id = input.required<string>();

  private readonly organizer = inject(Organizer);
  private readonly context = inject(EventContext);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  protected readonly event = signal<OrganizerEventDetail | null>(null);
  protected readonly reminders = signal<Reminder[] | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly addIcon = BellPlus;

  protected readonly live = computed(() => (this.reminders() ?? []).filter((r) => r.status !== 'cancelled'));

  /** Only moments still ahead, and not already taken. */
  protected readonly available = computed(() => {
    const starts = this.event()?.starts_at;
    const taken = new Set(this.live().map((r) => r.offset_minutes));
    const now = Date.now();

    return CHOICES.filter((c) => !taken.has(c.minutes) && (!starts || new Date(starts).getTime() - c.minutes * 60_000 > now));
  });

  ngOnInit(): void {
    this.event.set(this.context.peek(this.id()));
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      const [event, reminders] = await Promise.all([this.context.get(this.id()), this.organizer.reminders(this.id())]);
      this.event.set(event);
      this.reminders.set(reminders);
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected when(reminder: Reminder): string {
    const zone = this.event()?.timezone ?? 'UTC';
    const at = longEventTime(reminder.sent_at ?? reminder.send_at, zone);

    if (reminder.status === 'sent') return `Sent ${at}${reminder.recipients !== null ? ` to ${reminder.recipients}` : ''}`;

    return at;
  }

  protected statusOf(reminder: Reminder): string {
    return reminder.status === 'sent' ? 'Sent' : reminder.status === 'sending' ? 'Sending' : 'Scheduled';
  }

  protected async add(): Promise<void> {
    const choices = this.available();

    if (choices.length === 0) {
      this.toasts.show('Every reminder that is still ahead is already set.', 'neutral');
      return;
    }

    const chosen = await this.dialogs.menu({
      title: 'When should it go?',
      actions: choices.map((c) => ({ key: String(c.minutes), label: c.label })),
    });

    if (!chosen) return;

    const when = choices.find((c) => String(c.minutes) === chosen)?.label.toLowerCase() ?? 'before the event';
    const sure = await this.dialogs.confirm({
      title: `Add a reminder ${when}?`,
      body: `Everyone holding a ticket for ${this.event()?.title ?? 'this event'} gets an email ${when} it starts.`,
      consequences: ['Anyone who has unsubscribed does not.'],
      confirmLabel: 'Add the reminder',
      tone: 'default',
    });

    if (!sure) return;

    try {
      await this.organizer.addReminder(this.id(), Number(chosen));
      this.toasts.show('Reminder scheduled.', 'success');
      await this.load();
    } catch (error) {
      this.toasts.show(messageOf(error, 'That reminder could not be added.'), 'danger');
    }
  }

  protected async cancel(reminder: Reminder): Promise<void> {
    const sure = await this.dialogs.confirm({
      title: `Turn off “${reminder.label}”?`,
      body: 'Nobody gets it. You can add it again while it is still ahead.',
      confirmLabel: 'Turn off the reminder',
      tone: 'danger',
    });

    if (!sure) return;

    try {
      await this.organizer.cancelReminder(this.id(), reminder.id);
      this.toasts.show('Turned off.', 'success');
      await this.load();
    } catch (error) {
      this.toasts.show(messageOf(error, 'That reminder could not be turned off.'), 'danger');
    }
  }
}
