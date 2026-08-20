import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { EventSummary, OrganizerEvent, Reminder, TicketType } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { longEventTime } from '../../core/event-time';
import { zonedWallClockToIso } from '../../core/zoned-time';
import { formatMoney, toMajorUnits, toMinorUnits } from '../../core/money';
import { SessionStore } from '../../core/session';

@Component({
  selector: 'app-event-detail',
  imports: [FormsModule, RouterLink],
  templateUrl: './event-detail.html',
  styleUrl: './event-detail.css',
})
export class EventDetail {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  readonly session = inject(SessionStore);

  readonly eventId = this.route.snapshot.paramMap.get('id')!;

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
   * `admits` is how many people one of these lets in — a Couple admits 2, a
   * Table of 5 admits 5 — and the door counts them in, so a table can arrive in
   * two groups. The description is shown to buyers on the event page, which is
   * where a table tier has to explain itself.
   */
  readonly draft = signal({
    name: '',
    description: '',
    price: '',
    quantity: '' as string,
    admits: '1',
  });
  readonly savingTicket = signal(false);

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

  readonly liveReminders = computed(() =>
    this.reminders().filter((r) => r.status !== 'cancelled'),
  );

  constructor() {
    this.load();
  }

  private load(): void {
    this.api.events().subscribe({
      next: ({ data }) => {
        this.event.set(data.find((e) => e.id === this.eventId) ?? null);
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

    // Only asked for when this member may see it. Requesting anyway would
    // produce a 403 in the console for someone doing nothing wrong.
    if (this.session.canSeeMoney()) {
      this.api.summary(this.eventId).subscribe({
        next: (summary) => this.summary.set(summary),
        error: () => undefined,
      });
    }
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

  addTicketType(): void {
    const draft = this.draft();

    if (!draft.name.trim() || draft.price === '') return;

    this.savingTicket.set(true);
    this.error.set(null);

    this.api
      .createTicketType(this.eventId, {
        name: draft.name.trim(),
        description: draft.description.trim() || null,
        // Converted at the edge. Everything inside is minor units.
        price_amount: toMinorUnits(draft.price),
        quantity_available: draft.quantity === '' ? null : Number(draft.quantity),
        admits: Number(draft.admits) || 1,
      })
      .subscribe({
        next: () => {
          this.savingTicket.set(false);
          this.draft.set({ name: '', description: '', price: '', quantity: '', admits: '1' });
          this.api.ticketTypes(this.eventId).subscribe(({ data }) => this.ticketTypes.set(data));
        },
        error: (response) => {
          this.savingTicket.set(false);
          this.error.set(messageFor(response, 'That ticket could not be saved.'));
        },
      });
  }

  removeTicketType(type: TicketType): void {
    this.api.deleteTicketType(this.eventId, type.id).subscribe({
      next: (result) => {
        // A type with tickets against it closes rather than disappears, and
        // saying so avoids it looking like the delete failed.
        this.notice.set(result.message);
        this.api.ticketTypes(this.eventId).subscribe(({ data }) => this.ticketTypes.set(data));
      },
      error: () => this.error.set('That ticket could not be removed.'),
    });
  }

  togglePublished(): void {
    const event = this.event();
    if (!event || this.publishing()) return;

    const next = event.status === 'published' ? 'draft' : 'published';

    this.publishing.set(true);
    this.error.set(null);

    this.api.publish(this.eventId, next).subscribe({
      next: ({ status }) => {
        this.publishing.set(false);
        this.event.set({ ...event, status: status as OrganizerEvent['status'] });
        this.notice.set(
          status === 'published'
            ? 'Live. The link is ready to share.'
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
