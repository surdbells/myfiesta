import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { AttendeeMessage, MessageAudience } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';

/**
 * Writing to the people holding tickets.
 *
 * Without this an organizer with news had no way to reach their own audience
 * except by exporting a guest list into a personal mail client, which puts
 * buyers' addresses somewhere no opt-out here can reach.
 *
 * The one decision on the screen is ordinary news versus something somebody
 * needs before they travel. Marking a message important overrides an opt-out,
 * so the control says exactly that rather than "high priority" — an organizer
 * choosing it should know what they are choosing.
 */
@Component({
  selector: 'app-event-messages',
  imports: [FormsModule, RouterLink],
  templateUrl: './event-messages.html',
  styleUrl: './event-messages.css',
})
export class EventMessages {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  readonly session = inject(SessionStore);

  readonly eventId = this.route.snapshot.paramMap.get('id')!;

  readonly sent = signal<AttendeeMessage[]>([]);
  readonly audience = signal<MessageAudience>({ holders: 0, reachable: 0 });
  readonly loading = signal(true);

  readonly subject = signal('');
  readonly body = signal('');
  readonly important = signal(false);

  readonly sending = signal(false);
  readonly notice = signal<string | null>(null);
  readonly error = signal<string | null>(null);

  /** How many this send would actually reach, given the flag. */
  readonly willReach = computed(() =>
    this.important() ? this.audience().holders : this.audience().reachable,
  );

  /** People who will not get it unless it is marked important. */
  readonly suppressed = computed(() => this.audience().holders - this.audience().reachable);

  readonly ready = computed(
    () => this.subject().trim().length > 0 && this.body().trim().length > 0,
  );

  constructor() {
    this.load();
  }

  private load(): void {
    this.api.messages(this.eventId).subscribe({
      next: ({ data, audience }) => {
        this.sent.set(data);
        this.audience.set(audience);
        this.loading.set(false);
      },
      error: (response) => {
        this.loading.set(false);
        this.error.set(messageFor(response, 'Could not load messages for this event.'));
      },
    });
  }

  submit(): void {
    if (!this.ready() || this.sending()) return;

    this.sending.set(true);
    this.error.set(null);
    this.notice.set(null);

    this.api
      .sendMessage(this.eventId, {
        subject: this.subject().trim(),
        body: this.body().trim(),
        important: this.important(),
      })
      .subscribe({
        next: (result) => {
          this.sending.set(false);
          this.notice.set(result.message);
          this.subject.set('');
          this.body.set('');
          this.important.set(false);
          this.load();
        },
        error: (response) => {
          this.sending.set(false);
          this.error.set(messageFor(response, 'That message could not be sent.'));
        },
      });
  }

  when(iso: string | null): string {
    if (!iso) return '';

    return new Intl.DateTimeFormat('en-CA', {
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
    }).format(new Date(iso));
  }
}
