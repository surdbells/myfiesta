import { Component, inject, signal } from '@angular/core';
import { ToastStore, UiButton, UiIcon } from '@myfiesta/ui';
import { Download } from 'lucide-angular';
import { saveFile, today } from '../../core/download';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { eventIdFrom } from '../../core/event-id';
import { Api } from '../../core/api';
import { Guest, TicketType } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';

/**
 * Who is coming, who has arrived, and putting somebody on the list.
 *
 * The screen an organizer keeps open all night, so arrivals are the number that
 * leads and the search is the thing that works fastest — a name gets typed here
 * under time pressure with somebody waiting at the door.
 */
@Component({
  selector: 'app-event-guests',
  imports: [FormsModule, UiButton, UiIcon],
  templateUrl: './event-guests.html',
})
export class EventGuests {
  protected readonly downloadIcon = Download;
  private readonly toasts = inject(ToastStore);
  readonly exporting = signal(false);

  /**
   * The whole guest list as a spreadsheet, alphabetical — to print for a door
   * or send to a venue. No ticket codes: a forwarded list must not be a set of
   * working tickets.
   */
  exportList(): void {
    if (this.exporting()) return;

    this.exporting.set(true);

    this.api.exportGuests(this.eventId).subscribe({
      next: (file) => {
        this.exporting.set(false);
        saveFile(file, `guest-list-${today()}.csv`);
      },
      error: () => {
        this.exporting.set(false);
        this.toasts.show('The guest list could not be downloaded. Try again.', 'danger');
      },
    });
  }

  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  readonly session = inject(SessionStore);

  readonly eventId = eventIdFrom(this.route);

  readonly guests = signal<Guest[]>([]);
  readonly total = signal(0);
  readonly arrived = signal(0);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);

  readonly search = signal('');

  readonly ticketTypes = signal<TicketType[]>([]);
  readonly issuing = signal(false);
  readonly issue = signal({
    ticket_type_id: '',
    name: '',
    email: '',
    quantity: '1',
    note: '',
    send_email: true,
  });

  constructor() {
    this.load();

    this.api.ticketTypes(this.eventId).subscribe({
      next: ({ data }) => {
        this.ticketTypes.set(data);

        // Preselected so the common case — one type, one guest — is two fields
        // and a button rather than a form to work through.
        if (data.length > 0) {
          this.issue.set({ ...this.issue(), ticket_type_id: data[0].id });
        }
      },
      error: () => undefined,
    });
  }

  load(): void {
    this.loading.set(true);

    this.api.guests(this.eventId, this.search().trim() || undefined).subscribe({
      next: (page) => {
        this.guests.set(page.data);
        this.total.set(page.meta.total);
        this.arrived.set(page.meta.checked_in);
        this.loading.set(false);
      },
      error: (response) => {
        this.loading.set(false);
        this.error.set(messageFor(response, 'Could not load the guest list.'));
      },
    });
  }

  submitIssue(): void {
    const form = this.issue();

    if (!form.name.trim() || !form.email.trim() || !form.ticket_type_id) return;

    this.issuing.set(true);
    this.error.set(null);
    this.notice.set(null);

    this.api
      .issueTicket(this.eventId, {
        ticket_type_id: form.ticket_type_id,
        name: form.name.trim(),
        email: form.email.trim(),
        quantity: Number(form.quantity) || 1,
        note: form.note.trim() || null,
        send_email: form.send_email,
      })
      .subscribe({
        next: (result) => {
          this.issuing.set(false);
          this.notice.set(
            form.send_email
              ? `${result.message} Emailed to ${form.email.trim()}.`
              : `${result.message} Nothing was sent — the code is on the list below.`,
          );

          this.issue.set({ ...this.issue(), name: '', email: '', quantity: '1', note: '' });
          this.load();
        },
        error: (response) => {
          this.issuing.set(false);
          this.error.set(messageFor(response, 'That ticket could not be issued.'));
        },
      });
  }

  /** Proportion arrived, for the bar. Null before anyone is expected. */
  arrivalRate(): number | null {
    return this.total() === 0 ? null : Math.round((this.arrived() / this.total()) * 100);
  }
}
