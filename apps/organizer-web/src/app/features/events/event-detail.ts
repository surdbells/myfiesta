import { Component, computed, inject, signal } from '@angular/core';
import { UiButton, UiSelect, type SelectOption } from '@myfiesta/ui';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { eventIdFrom } from '../../core/event-id';
import { Api } from '../../core/api';
import {
  EventSummary,
  OrganizerEvent,
  CancellationPreview,
  Reminder,
  Series,
  SeriesOccurrence,
  TicketType,
} from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { longEventTime } from '../../core/event-time';
import { zonedWallClockToIso } from '../../core/zoned-time';
import { formatMoney, toMajorUnits, toMinorUnits } from '../../core/money';
import { SessionStore } from '../../core/session';
import { EventSales } from './event-sales';

@Component({
  selector: 'app-event-detail',
  imports: [FormsModule, RouterLink, UiButton, UiSelect, EventSales],
  templateUrl: './event-detail.html',
})
export class EventDetail {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  readonly session = inject(SessionStore);

  readonly eventId = eventIdFrom(this.route);

  readonly event = signal<OrganizerEvent | null>(null);
  readonly ticketTypes = signal<TicketType[]>([]);
  readonly summary = signal<EventSummary | null>(null);

  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);
  readonly publishing = signal(false);

  /** A new ticket type being written. Prices are entered in major units. */
  /**
   * A new ticket type being written. Prices are entered in major units.
   *
   * Ticket types are read here only to gate publishing — managing them moved
   * to the Tickets tab, which does inventory and sale windows properly. The
   * cruder editor this screen carried was a second place for the same
   * decision, and the lesser one always won by being seen first.
   */
  readonly money = formatMoney;
  readonly when = longEventTime;

  readonly canPublish = computed(() => this.ticketTypes().some((t) => t.status === 'on_sale'));

  /*
   * Reminders live on this page rather than behind another click.
   *
   * They are set once and never touched, and sensible ones exist from the
   * moment an event is published — so the job of this section is to let an
   * organizer see that reminders will go out without having to go looking for
   * a screen that confirms it.
   */
  readonly reminders = signal<Reminder[]>([]);
  readonly newReminder = signal('1440');
  readonly savingReminder = signal(false);

  readonly reminderChoices = [
    { minutes: '20160', label: '2 weeks before' },
    { minutes: '10080', label: '1 week before' },
    { minutes: '2880', label: '2 days before' },
    { minutes: '1440', label: 'The day before' },
    { minutes: '360', label: '6 hours before' },
    { minutes: '180', label: '3 hours before' },
    { minutes: '60', label: '1 hour before' },
  ];

  readonly copying = signal(false);
  readonly duplicating = signal(false);
  readonly copyDate = signal('');

  /*
   * Calling the event off.
   *
   * Two steps on purpose. The preview is fetched first so the organizer sees
   * how many people they are about to tell and how much money is about to move
   * before they are asked to confirm — a confirm dialog that says "are you
   * sure?" and nothing else is asking somebody to guess.
   */
  readonly cancelling = signal(false);
  readonly cancelPreview = signal<CancellationPreview | null>(null);
  readonly cancelReason = signal('');
  readonly cancelRefund = signal(true);
  readonly cancelBusy = signal(false);

  readonly cancelReady = computed(() => this.cancelReason().trim().length >= 10);

  readonly series = signal<Series | null>(null);
  readonly repeating = signal(false);
  readonly frequency = signal<'weekly' | 'fortnightly' | 'monthly'>('weekly');

  readonly frequencyOptions: SelectOption[] = [
    { value: 'weekly', label: 'Every week' },
    { value: 'fortnightly', label: 'Every other week' },
    { value: 'monthly', label: 'Every month' },
  ];

  readonly reminderOptions = computed<SelectOption[]>(() =>
    this.reminderChoices.map((choice) => ({ value: choice.minutes, label: choice.label })),
  );
  readonly repeatCount = signal('8');

  /** Future dates only. A residency's past nights are not a schedule. */
  readonly upcoming = computed(() =>
    (this.series()?.occurrences ?? []).filter((o) => new Date(o.starts_at) > new Date()),
  );

  readonly liveReminders = computed(() =>
    this.reminders().filter((r) => r.status !== 'cancelled'),
  );

  constructor() {
    this.load();
  }

  private load(): void {
    // The event itself. This used to fetch the events list and look for the
    // id in it, so the thirty-first event's overview said it did not exist.
    this.api.event(this.eventId).subscribe({
      next: (event) => {
        this.event.set(event);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Could not load this event.');
      },
    });

    this.api.ticketTypes(this.eventId).subscribe({
      next: ({ data }) => this.ticketTypes.set(data),
      error: () => this.error.set('Could not load tickets for this event.'),
    });

    this.loadReminders();
    this.loadSeries();

    // Only asked for when this member may see it. Requesting anyway would
    // produce a 403 in the console for someone doing nothing wrong.
    if (this.session.canSeeMoney()) {
      this.api.summary(this.eventId).subscribe({
        next: (summary) => this.summary.set(summary),
        error: () => undefined,
      });
    }
  }

  private loadSeries(): void {
    this.api.series(this.eventId).subscribe({
      next: ({ series }) => this.series.set(series),
      error: () => undefined,
    });
  }

  makeRepeating(): void {
    if (this.repeating()) return;

    this.repeating.set(true);
    this.error.set(null);
    this.notice.set(null);

    const count = Number(this.repeatCount());

    this.api.repeatEvent(this.eventId, this.frequency(), count || undefined).subscribe({
      next: ({ created }) => {
        this.repeating.set(false);
        this.copying.set(false);
        this.notice.set(
          `${created} more ${created === 1 ? 'date' : 'dates'} added. They are drafts until you publish them.`,
        );
        this.loadSeries();
      },
      error: (response) => {
        this.repeating.set(false);
        this.error.set(messageFor(response, 'That event could not be set to repeat.'));
      },
    });
  }

  skip(occurrence: SeriesOccurrence): void {
    this.error.set(null);

    this.api.skipOccurrence(this.eventId, occurrence.id).subscribe({
      next: (result) => {
        this.notice.set(result.message);
        this.loadSeries();
      },
      error: (response) =>
        this.error.set(messageFor(response, 'That date could not be taken out.')),
    });
  }

  stopRepeating(): void {
    this.error.set(null);

    this.api.stopRepeating(this.eventId).subscribe({
      next: (result) => {
        this.notice.set(result.message);
        this.loadSeries();
      },
      error: (response) =>
        this.error.set(messageFor(response, 'That series could not be stopped.')),
    });
  }

  /** A date in the event's own zone, which is the venue's. */
  occurrenceDate(iso: string): string {
    const timeZone = this.event()?.timezone;

    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
      ...(timeZone ? { timeZone } : {}),
    }).format(new Date(iso));
  }

  private loadReminders(): void {
    this.api.reminders(this.eventId).subscribe({
      next: ({ data }) => this.reminders.set(data),
      error: () => undefined,
    });
  }

  addReminder(): void {
    if (this.savingReminder()) return;

    this.savingReminder.set(true);
    this.error.set(null);
    this.notice.set(null);

    this.api.addReminder(this.eventId, Number(this.newReminder())).subscribe({
      next: () => {
        this.savingReminder.set(false);
        this.loadReminders();
      },
      error: (response) => {
        this.savingReminder.set(false);
        this.error.set(messageFor(response, 'That reminder could not be added.'));
      },
    });
  }

  cancelReminder(reminder: Reminder): void {
    this.error.set(null);

    this.api.cancelReminder(this.eventId, reminder.id).subscribe({
      next: () => this.loadReminders(),
      error: (response) =>
        this.error.set(messageFor(response, 'That reminder could not be turned off.')),
    });
  }

  /**
   * When the email goes out, in the event's zone.
   *
   * Not the browser's. Everything else on this page is shown at the venue, and
   * an organizer in Lagos running a Toronto night reading "10:00 p.m." next to
   * a 5pm event has to work out which of the two is lying. The abbreviation is
   * what settles it.
   */
  sendTime(iso: string): string {
    const timeZone = this.event()?.timezone;

    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
      timeZoneName: 'short',
      ...(timeZone ? { timeZone } : {}),
    }).format(new Date(iso));
  }

  togglePublished(): void {
    const event = this.event();
    if (!event || this.publishing()) return;

    const next = event.status === 'published' ? 'draft' : 'published';

    this.publishing.set(true);
    this.error.set(null);

    this.api.publish(this.eventId, next).subscribe({
      next: ({ status, followers_told: told }) => {
        this.publishing.set(false);
        this.event.set({ ...event, status: status as OrganizerEvent['status'] });
        this.notice.set(
          status === 'published'
            ? told
              ? `Live, and ${told} ${told === 1 ? 'follower has' : 'followers have'} been told. The link is ready to share.`
              : 'Live. The link is ready to share.'
            : 'Taken down. Existing tickets still work.',
        );
      },
      error: (response) => {
        this.publishing.set(false);
        this.error.set(messageFor(response, 'That could not be changed.'));
      },
    });
  }

  priceOf(type: TicketType): string {
    return String(toMajorUnits(type.price.amount));
  }

  /**
   * Copy this event to a new date.
   *
   * The date is asked for rather than defaulted, because a copy with no date
   * is a copy on the same night as the original — which is never what somebody
   * duplicating a weekly night means, and produces two events competing for
   * the same room.
   */
  openCancel(): void {
    this.cancelling.set(true);
    this.error.set(null);

    this.api.cancellationPreview(this.eventId).subscribe({
      next: (preview) => this.cancelPreview.set(preview),
      error: (response) =>
        this.error.set(messageFor(response, 'Could not work out what cancelling would involve.')),
    });
  }

  confirmCancel(): void {
    if (!this.cancelReady() || this.cancelBusy()) return;

    this.cancelBusy.set(true);
    this.error.set(null);
    this.notice.set(null);

    this.api.cancelEvent(this.eventId, this.cancelReason().trim(), this.cancelRefund()).subscribe({
      next: (result) => {
        this.cancelBusy.set(false);
        this.cancelling.set(false);
        this.notice.set(result.message);

        const event = this.event();
        if (event) this.event.set({ ...event, status: 'cancelled' });
      },
      error: (response) => {
        this.cancelBusy.set(false);
        this.error.set(messageFor(response, 'That event could not be cancelled.'));
      },
    });
  }

  duplicate(): void {
    const event = this.event();

    if (!event || this.duplicating()) return;

    const startsAt = zonedWallClockToIso(this.copyDate(), event.timezone);

    if (!startsAt) {
      this.error.set('Choose when the copy happens.');

      return;
    }

    this.duplicating.set(true);
    this.error.set(null);
    this.notice.set(null);

    this.api.duplicateEvent(this.eventId, startsAt).subscribe({
      next: () => {
        this.duplicating.set(false);
        this.copying.set(false);
        // Left on this page rather than jumped to the copy: the organizer is
        // mid-thought about this event, and being moved somewhere else is
        // disorienting when the new thing is a draft they may not want yet.
        this.notice.set('Copied. It is in your events list as a draft.');
      },
      error: (response) => {
        this.duplicating.set(false);
        this.error.set(messageFor(response, 'That event could not be copied.'));
      },
    });
  }
}
