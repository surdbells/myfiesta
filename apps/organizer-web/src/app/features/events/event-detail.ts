import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { EventSummary, OrganizerEvent, TicketType } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { longEventTime } from '../../core/event-time';
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
  readonly draft = signal({ name: '', price: '', quantity: '' as string });
  readonly savingTicket = signal(false);

  readonly money = formatMoney;
  readonly when = longEventTime;

  readonly canPublish = computed(() => this.ticketTypes().some((t) => t.status === 'on_sale'));

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

    // Only asked for when this member may see it. Requesting anyway would
    // produce a 403 in the console for someone doing nothing wrong.
    if (this.session.canSeeMoney()) {
      this.api.summary(this.eventId).subscribe({
        next: (summary) => this.summary.set(summary),
        error: () => undefined,
      });
    }
  }

  addTicketType(): void {
    const draft = this.draft();

    if (!draft.name.trim() || draft.price === '') return;

    this.savingTicket.set(true);
    this.error.set(null);

    this.api
      .createTicketType(this.eventId, {
        name: draft.name.trim(),
        // Converted at the edge. Everything inside is minor units.
        price_amount: toMinorUnits(draft.price),
        quantity_available: draft.quantity === '' ? null : Number(draft.quantity),
      })
      .subscribe({
        next: () => {
          this.savingTicket.set(false);
          this.draft.set({ name: '', price: '', quantity: '' });
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
}
