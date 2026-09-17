import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Reminders } from '../../core/reminders';
import { Api, ApiError, Ticket } from '../../core/api';
import { SessionStore } from '../../core/session';
import { shortEventTime } from '../../core/event-time';
import { MfBadge, MfButton, MfCard, MfEmpty, MfScreen, MfSkeleton } from '../../ui';

/**
 * The tickets somebody holds.
 *
 * Soonest first, because the one you need next is the one you want on screen
 * when you open the app in a queue. Tapping one opens it full screen, where
 * the code is as large as the phone can draw it.
 */
@Component({
  selector: 'mf-tickets',
  imports: [MfScreen, MfCard, MfBadge, MfEmpty, MfSkeleton, MfButton],
  template: `
    <mf-screen title="Your tickets" [subtitle]="subtitle()">
      <button mfButton variant="ghost" size="sm" screenActions (click)="settings()">Settings</button>

      @if (loading()) {
        <div class="stack">
          @for (n of [0, 1, 2]; track n) {
            <mf-card><mf-skeleton height="3.5rem" /></mf-card>
          }
        </div>
      } @else if (failed(); as message) {
        <mf-empty title="Could not load your tickets" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (tickets().length === 0) {
        <mf-empty
          title="No tickets yet"
          hint="Tickets you buy show up here, ready to scan — even with no signal at the door."
        />
      } @else {
        <ul class="stack">
          @for (ticket of tickets(); track ticket.id) {
            <li>
              <mf-card tappable (click)="open(ticket)">
                <p class="when">{{ when(ticket) }}</p>
                <h2>{{ ticket.event.title }}</h2>
                <p class="where muted">{{ ticket.event.city }}</p>

                <div class="row">
                  @if (ticket.type) {
                    <span class="type">{{ ticket.type }}</span>
                  }
                  @if (ticket.status === 'checked_in') {
                    <mf-badge tone="neutral">Used</mf-badge>
                  } @else {
                    <mf-badge tone="success">Ready</mf-badge>
                  }
                </div>
              </mf-card>
            </li>
          }
        </ul>
      }
    </mf-screen>

  `,
  styles: `
    .stack {
      display: grid;
      gap: var(--space-3);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .when {
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-semibold);
      color: var(--primary-text);
    }

    .where {
      font-size: var(--font-size-sm);
    }

    .row {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      margin-top: var(--space-3);
    }

    .type {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

  `,
})
export class Tickets {
  private readonly api = inject(Api);
  private readonly reminders = inject(Reminders);
  private readonly router = inject(Router);
  readonly session = inject(SessionStore);

  readonly tickets = signal<Ticket[]>([]);
  readonly loading = signal(true);
  readonly failed = signal<string | null>(null);

  readonly subtitle = computed(() => {
    const count = this.tickets().length;

    if (this.loading()) return null;

    return count === 0 ? null : `${count} ${count === 1 ? 'ticket' : 'tickets'}`;
  });

  constructor() {
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.failed.set(null);

    try {
      const tickets = await this.api.tickets();

      this.tickets.set(tickets);
      // A ticket handed to a friend should stop reminding this phone about a
      // night it is no longer going to, so the schedule is rebuilt from what
      // came back rather than added to.
      void this.reminders.reconcile(tickets);
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        await this.session.clear();
        await this.router.navigate(['/sign-in'], { replaceUrl: true });

        return;
      }

      this.failed.set(error instanceof ApiError ? error.message : 'Something went wrong.');
    } finally {
      this.loading.set(false);
    }
  }

  when(ticket: Ticket): string {
    return shortEventTime(ticket.event.starts_at, ticket.event.timezone);
  }

  open(ticket: Ticket): void {
    void this.router.navigate(['/tickets', ticket.id]);
  }

  settings(): void {
    void this.router.navigate(['/settings']);
  }
}
