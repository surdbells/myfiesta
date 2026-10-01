import { Component, computed, inject, signal } from '@angular/core';
import { ConfirmDialog, UiButton, UiSelect, type SelectOption } from '@myfiesta/ui';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { tap } from 'rxjs';
import { eventIdFrom } from '../../core/event-id';
import { Api } from '../../core/api';
import {
  EventSummary,
  EventReviewResult,
  OrganizerEventDetail,
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
import { EventEmbed } from './event-embed';
import { EventSales } from './event-sales';
import { eventStatusLabel, nightIsOver, reviewStepLabel } from './event-status';
import { EventWorkspace } from './event-workspace';
import { DuplicatePart } from './parts/duplicate-part';
import { SchedulePart } from './parts/schedule-part';
import { SeriesPart } from './parts/series-part';
import { ShareOfferPart } from './parts/share-offer-part';

@Component({
  selector: 'app-event-detail',
  imports: [
    FormsModule,
    RouterLink,
    UiButton,
    UiSelect,
    EventSales,
    EventEmbed,
    SchedulePart,
    SeriesPart,
    DuplicatePart,
    ShareOfferPart,
  ],
  templateUrl: './event-detail.html',
})
export class EventDetail {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly confirmDialog = inject(ConfirmDialog);
  /** The frame around this tab, whose header and locks follow the event's status. */
  private readonly workspace = inject(EventWorkspace, { optional: true });
  readonly session = inject(SessionStore);

  readonly eventId = eventIdFrom(this.route);

  readonly event = signal<OrganizerEventDetail | null>(null);
  readonly summary = signal<EventSummary | null>(null);

  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);
  readonly publishing = signal(false);

  readonly money = formatMoney;
  readonly when = longEventTime;

  /*
   * Where the event stands with myFiesta's review.
   *
   * Nothing goes on sale without somebody at myFiesta looking at it first. The
   * server says what sending it now would do — into the queue, or straight
   * back on sale because nothing has changed since it was approved — and what
   * stops it being sent, so the button can say which before it is pressed.
   */
  readonly review = computed(() => this.event()?.review ?? null);
  readonly notReady = computed(() => this.review()?.not_ready ?? []);
  readonly history = computed(() => this.review()?.history ?? []);

  /**
   * The time it is set to go on sale by itself (the Schedule part), while
   * that is still to come. Approved before then, it waits for it as a draft,
   * usually one never on sale, so the button says "on sale now" rather than
   * "back on sale", and the dialog says pressing it does not wait.
   */
  readonly onSaleAt = computed(() => {
    const at = this.event()?.publish_at;

    return at && new Date(at).getTime() > Date.now() ? at : null;
  });

  readonly statusLabel = eventStatusLabel;
  readonly stepLabel = reviewStepLabel;

  /** Published, and its night over: nothing on sale to take off. */
  readonly over = computed(() => nightIsOver(this.event()));

  /** "1 order · 2 tickets · 0 arrived": one of a thing is not "1 orders". */
  counted(n: number, one: string, many: string): string {
    return `${n.toLocaleString()} ${n === 1 ? one : many}`;
  }

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

    // Ticket types are managed on the Tickets tab, and whether there is one on
    // sale — what used to gate the button here — comes with the event now, in
    // `review.not_ready`, alongside everything else that stops it being sent.
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

  async makeRepeating(): Promise<void> {
    const event = this.event();
    if (!event || this.repeating()) return;

    this.error.set(null);
    this.notice.set(null);

    const count = Number(this.repeatCount());

    // "For 8 dates" is every date in the series, this one included — the
    // count the server takes (2 to 104) — so 8 is seven new events. Refused
    // here rather than after the question, so nobody confirms a number the
    // server then turns down.
    if (count && (!Number.isInteger(count) || count < 2 || count > 104)) {
      this.error.set('Repeat it for 2 to 104 dates, counting this one.');

      return;
    }

    const more = count - 1;
    const last = new Date(event.starts_at).getTime() + more * { weekly: 7, fortnightly: 14, monthly: 31 }[this.frequency()] * 86_400_000;
    const how = this.frequencyOptions.find((option) => option.value === this.frequency())?.label.toLowerCase() ?? this.frequency();
    let created = 0;

    this.repeating.set(true);

    const done = await this.confirmDialog.confirm({
      title: `Repeat ${event.title} ${how}?`,
      body: count
        ? `${count} dates in all, counting this one: ${more} more ${more === 1 ? 'is' : 'are'} added, ${how}, each its own event with its own tickets and door.`
        : `New dates are added ${how}, six months ahead at a time, until you stop repeating it. Each is its own event with its own tickets and door.`,
      consequences: [
        // SeriesGenerator makes six months ahead at a time; the rest follow on schedule.
        ...(count && last - Date.now() > 180 * 86_400_000 ? ['Dates more than six months away are added as they come closer.'] : []),
        'Each new date is a draft until you submit it. A date that is this approved night, unchanged, goes straight on sale.',
      ],
      confirmLabel: count ? `Add ${more} more ${more === 1 ? 'date' : 'dates'}` : 'Repeat it',
      busyLabel: 'Setting up…',
      tone: 'default',
      run: () => this.api.repeatEvent(this.eventId, this.frequency(), count || undefined).pipe(tap((result) => (created = result.created))),
      failure: (response) => messageFor(response, 'That event could not be set to repeat.'),
    });

    this.repeating.set(false);

    if (!done) return;

    this.copying.set(false);
    this.notice.set(
      `${created} more ${created === 1 ? 'date' : 'dates'} added. Each is a draft until you submit it — a date that is this approved night, unchanged, goes straight on sale.`,
    );
    this.loadSeries();
  }

  async skip(occurrence: SeriesOccurrence): Promise<void> {
    this.error.set(null);

    let said = '';

    const done = await this.confirmDialog.confirm({
      title: `Skip ${this.occurrenceDate(occurrence.starts_at)}?`,
      body: 'That date is taken out of the series and its page is deleted. It will not come back.',
      consequences: ['A date somebody already holds a ticket for cannot be skipped: refund them first.'],
      confirmLabel: 'Skip this date',
      busyLabel: 'Skipping…',
      tone: 'danger',
      run: () => this.api.skipOccurrence(this.eventId, occurrence.id).pipe(tap((result) => (said = result.message))),
      failure: (response) => messageFor(response, 'That date could not be taken out.'),
    });

    if (!done) return;

    this.notice.set(said);
    this.loadSeries();
  }

  async stopRepeating(): Promise<void> {
    this.error.set(null);

    const title = this.event()?.title ?? 'this event';
    let said = '';

    const done = await this.confirmDialog.confirm({
      title: `Stop repeating ${title}?`,
      body: 'No more dates are added, and future dates nobody has bought a ticket for are deleted.',
      consequences: ['Dates people have already bought tickets for are kept, exactly as they are.'],
      confirmLabel: 'Stop repeating',
      busyLabel: 'Stopping…',
      tone: 'danger',
      run: () => this.api.stopRepeating(this.eventId).pipe(tap((result) => (said = result.message))),
      failure: (response) => messageFor(response, 'That series could not be stopped.'),
    });

    if (!done) return;

    this.notice.set(said);
    this.loadSeries();
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

  async addReminder(): Promise<void> {
    if (this.savingReminder()) return;

    this.error.set(null);
    this.notice.set(null);

    const when = this.reminderChoices.find((choice) => choice.minutes === this.newReminder())?.label ?? 'Before the event';

    this.savingReminder.set(true);

    const done = await this.confirmDialog.confirm({
      title: `Add a reminder ${when.toLowerCase()}?`,
      body: `Everyone holding a ticket for ${this.event()?.title ?? 'this event'} gets an email ${when.toLowerCase()} it starts.`,
      consequences: ['Anyone who has unsubscribed does not.'],
      confirmLabel: 'Add the reminder',
      busyLabel: 'Adding…',
      tone: 'default',
      run: () => this.api.addReminder(this.eventId, Number(this.newReminder())),
      failure: (response) => messageFor(response, 'That reminder could not be added.'),
    });

    this.savingReminder.set(false);

    if (done) this.loadReminders();
  }

  async cancelReminder(reminder: Reminder): Promise<void> {
    this.error.set(null);

    const done = await this.confirmDialog.confirm({
      title: `Turn off the ${reminder.label.toLowerCase()} reminder?`,
      body: `The email due ${this.sendTime(reminder.send_at)} is not sent to anyone.`,
      confirmLabel: 'Turn off the reminder',
      busyLabel: 'Turning off…',
      tone: 'danger',
      run: () => this.api.cancelReminder(this.eventId, reminder.id),
      failure: (response) => messageFor(response, 'That reminder could not be turned off.'),
    });

    if (done) this.loadReminders();
  }

  /**
   * When the email goes out, or when the event was sent for review, in the
   * event's zone and saying which.
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

  /**
   * Send it to myFiesta, or straight back on sale.
   *
   * Which one is known before the dialog opens (`review.on_submit`), and the
   * dialog says it: an organizer who expects a review and finds the event on
   * sale, or the other way round, has been told something untrue.
   */
  async submit(): Promise<void> {
    const event = this.event();
    if (!event || this.publishing()) return;

    const straightBack = event.review.on_submit === 'publish';
    // Set to go on sale by itself later: pressing now does not wait for that.
    const setFor = this.onSaleAt();
    let result: EventReviewResult | null = null;

    this.error.set(null);
    this.notice.set(null);
    this.publishing.set(true);

    const done = await this.confirmDialog.confirm(
      straightBack && setFor
        ? {
            title: `Put ${event.title} on sale now?`,
            body: `It is approved and set to go on sale by itself at ${this.sendTime(setFor)}. Putting it on sale now does not wait for that time.`,
            consequences: ['Its page goes live and tickets can be bought straight away.', 'The time you set is cleared.'],
            confirmLabel: 'Put on sale now',
            busyLabel: 'Putting it on sale…',
            tone: 'default',
            run: () => this.api.submitForReview(this.eventId).pipe(tap((answer) => (result = answer))),
            failure: (response) => messageFor(response, 'It could not be put on sale.'),
          }
        : straightBack
        ? {
            title: `Put ${event.title} back on sale?`,
            body: 'Nothing a buyer sees has changed since myFiesta approved it, so it goes back on sale straight away, without another review.',
            consequences: ['Its page is visible again and tickets can be bought.'],
            confirmLabel: 'Put back on sale',
            busyLabel: 'Putting it back…',
            tone: 'default',
            run: () => this.api.submitForReview(this.eventId).pipe(tap((answer) => (result = answer))),
            failure: (response) => messageFor(response, 'It could not be put back on sale.'),
          }
        : {
            title: `Send ${event.title} for review?`,
            body: 'Somebody at myFiesta looks at every event before it goes on sale, usually within a working day. We email you when it is approved or if something needs changing.',
            consequences: [
              'While it is being reviewed you cannot change it: its details, tickets, extras, questions, pictures and codes are locked.',
              setFor
                ? `It goes on sale at the time you set, ${this.sendTime(setFor)}, if it is approved by then, or as soon as it is approved after that. The people who follow you are told when it goes on sale.`
                : 'Once it is approved it goes on sale straight away, and the people who follow you are told.',
              'You can withdraw it from review at any time to make a change.',
            ],
            confirmLabel: 'Submit for review',
            busyLabel: 'Sending…',
            tone: 'default',
            run: () => this.api.submitForReview(this.eventId).pipe(tap((answer) => (result = answer))),
            failure: (response) => messageFor(response, 'It could not be sent for review.'),
          },
    );

    this.publishing.set(false);

    if (done && result) this.afterReviewStep(result);
  }

  /** Take it back from review, to change something. */
  async withdraw(): Promise<void> {
    const event = this.event();
    if (!event || this.publishing()) return;

    let result: EventReviewResult | null = null;

    this.error.set(null);
    this.notice.set(null);
    this.publishing.set(true);

    const done = await this.confirmDialog.confirm({
      title: `Withdraw ${event.title} from review?`,
      body: 'It goes back to a draft so you can change it. myFiesta stops looking at it until you send it again.',
      consequences: ['When you send it again, it waits for review from the start.'],
      confirmLabel: 'Withdraw from review',
      busyLabel: 'Withdrawing…',
      tone: 'default',
      run: () => this.api.withdrawFromReview(this.eventId).pipe(tap((answer) => (result = answer))),
      failure: (response) => messageFor(response, 'It could not be taken back from review.'),
    });

    this.publishing.set(false);

    if (done && result) this.afterReviewStep(result);
  }

  /**
   * Take it off sale, saying first what putting it back would take.
   *
   * Edits made while it is on sale need no review, but they do mean it is no
   * longer what was approved — so the dialog says whether it could go
   * straight back, rather than leaving that to be found out later.
   */
  async takeOffSale(): Promise<void> {
    const event = this.event();
    if (!event || this.publishing()) return;

    let result: EventReviewResult | null = null;

    this.error.set(null);
    this.notice.set(null);
    this.publishing.set(true);

    const done = await this.confirmDialog.confirm({
      title: `Take ${event.title} off sale?`,
      body: 'Its page is hidden and nobody can buy a ticket until it is back on sale. Tickets already sold still work.',
      consequences: [
        event.review.unchanged_since_approval
          ? 'Nothing a buyer sees has changed since myFiesta approved it, so you can put it straight back on sale — as long as that stays true.'
          : 'It has changed since myFiesta approved it, so putting it back on sale will need another review.',
      ],
      confirmLabel: 'Take off sale',
      busyLabel: 'Taking it off sale…',
      tone: 'danger',
      run: () => this.api.publish(this.eventId, 'draft').pipe(tap((answer) => (result = answer))),
      failure: (response) => messageFor(response, 'It could not be taken off sale.'),
    });

    this.publishing.set(false);

    if (done && result) this.afterReviewStep(result);
  }

  /**
   * The event as one of the parts on this page has just changed it.
   *
   * Kept here and in the header alike, and the series read again with it:
   * a go-live time or a shorter run can move or remove dates.
   */
  partChanged(fresh: OrganizerEventDetail): void {
    this.event.set(fresh);
    this.workspace?.setEvent(fresh);
    this.loadSeries();
  }

  /** Say what happened, and read the event again so the page and the header agree. */
  private afterReviewStep(result: EventReviewResult): void {
    this.notice.set(result.message);

    this.api.event(this.eventId).subscribe({
      next: (fresh) => {
        this.event.set(fresh);
        this.workspace?.setEvent(fresh);
      },
      error: () => this.workspace?.refresh(),
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

  /**
   * The last word before calling it off: the panel gathered the reason and
   * the choice to refund, and this says back what they add up to — who is
   * told, how much goes back — above the button that does it.
   */
  async confirmCancel(): Promise<void> {
    const event = this.event();
    if (!event || !this.cancelReady() || this.cancelBusy()) return;

    this.error.set(null);
    this.notice.set(null);

    const preview = this.cancelPreview();
    const refund = this.cancelRefund();
    const reason = this.cancelReason().trim();
    let said = '';

    const told = preview
      ? `${preview.ticket_holders} ${preview.ticket_holders === 1 ? 'person holding a ticket is' : 'people holding tickets are'} told it is off, with your reason word for word.`
      : 'Everybody holding a ticket is told it is off, with your reason word for word.';

    const returned = !refund
      ? 'Nobody is refunded now: you refund each order yourself from Orders and refunds.'
      : preview && preview.orders_to_refund > 0
        ? `${this.money(preview.refund_total)} goes back across ${preview.orders_to_refund} ${preview.orders_to_refund === 1 ? 'order' : 'orders'}, to the cards they paid with.`
        : 'Every paid order is refunded to the card it was paid with.';

    // Paid with Klarna or Affirm longer ago than the lender takes money back for (pay later).
    const late = refund ? (preview?.orders_to_refund_elsewhere ?? 0) : 0;
    const elsewhere = late > 0
      ? [`${late} of those ${late === 1 ? 'was' : 'were'} paid with Klarna or Affirm too long ago to go back that way. Write to myFiesta support afterwards, who will return ${late === 1 ? 'it' : 'them'} another way.`]
      : [];

    this.cancelBusy.set(true);

    const done = await this.confirmDialog.confirm({
      title: `Cancel ${event.title}?`,
      body: told,
      consequences: [returned, ...elsewhere, 'Sales stop and the reminders still to come are not sent.', 'It cannot be undone.'],
      confirmLabel: 'Cancel the event',
      cancelLabel: 'Keep it running',
      busyLabel: 'Cancelling…',
      tone: 'danger',
      run: () => this.api.cancelEvent(this.eventId, reason, refund).pipe(tap((result) => (said = result.message))),
      failure: (response) => messageFor(response, 'That event could not be cancelled.'),
    });

    this.cancelBusy.set(false);

    if (!done) return;

    this.cancelling.set(false);
    this.notice.set(said);

    const current = this.event();
    if (current) this.event.set({ ...current, status: 'cancelled' });
  }

  async duplicate(): Promise<void> {
    const event = this.event();

    if (!event || this.duplicating()) return;

    const startsAt = zonedWallClockToIso(this.copyDate(), event.timezone);

    if (!startsAt) {
      this.error.set('Choose when the copy happens.');

      return;
    }

    this.error.set(null);
    this.notice.set(null);
    this.duplicating.set(true);

    const done = await this.confirmDialog.confirm({
      title: `Copy ${event.title} to ${this.occurrenceDate(startsAt)}?`,
      body: 'A new draft is made with the same tickets, prices and price steps, capacity, extras, checkout questions, reminder times and banner.',
      consequences: ['Sales, codes and the gallery stay with this one.', 'The copy goes on sale only after you submit it for review.'],
      confirmLabel: 'Make a copy',
      busyLabel: 'Copying…',
      tone: 'default',
      run: () => this.api.duplicateEvent(this.eventId, startsAt),
      failure: (response) => messageFor(response, 'That event could not be copied.'),
    });

    this.duplicating.set(false);

    if (!done) return;

    this.copying.set(false);
    // Left on this page rather than jumped to the copy: the organizer is
    // mid-thought about this event, and being moved somewhere else is
    // disorienting when the new thing is a draft they may not want yet.
    this.notice.set('Copied. It is in your events list as a draft.');
  }
}
