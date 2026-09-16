import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Api, ApiError, Ticket } from '../../core/api';
import { SessionStore } from '../../core/session';
import { shortEventTime, longEventTime } from '../../core/event-time';
import { MfBadge, MfButton, MfCard, MfEmpty, MfScreen, MfSheet, MfSkeleton, ToastStore } from '../../ui';
import { MfQr } from '../../ui/qr';

/**
 * The tickets somebody holds.
 *
 * Soonest first, because the one you need next is the one you want on screen
 * when you open the app in a queue. Tapping a ticket brings the code up from
 * the bottom at the size a scanner wants, with the screen brightened while it
 * is open — the two things anybody actually does with this screen.
 */
@Component({
  selector: 'mf-tickets',
  imports: [MfScreen, MfCard, MfBadge, MfEmpty, MfSkeleton, MfSheet, MfButton, MfQr],
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
              <mf-card tappable (click)="show(ticket)">
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

    <mf-sheet
      [open]="!!showing()"
      [heading]="showing()?.event?.title ?? ''"
      [subheading]="showing() ? when(showing()!) : null"
      (closed)="hide()"
    >
      @if (showing(); as ticket) {
        <div class="ticket">
          <mf-qr [code]="ticket.code" [size]="232" />

          <p class="code tabular">{{ ticket.code }}</p>

          <p class="hint muted">
            @if (ticket.status === 'checked_in') {
              Already scanned. Keep it for re-entry if the venue allows it.
            } @else {
              Show this at the door. It works without a connection.
            }
          </p>

          <dl class="facts">
            <div>
              <dt>Ticket</dt>
              <dd>{{ ticket.type ?? 'Standard' }}</dd>
            </div>
            <div>
              <dt>Name on it</dt>
              <dd>{{ ticket.holder_name ?? session.session()?.name ?? '—' }}</dd>
            </div>
            <div>
              <dt>When</dt>
              <dd>{{ long(ticket) }}</dd>
            </div>
          </dl>
        </div>
      }
    </mf-sheet>
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

    .ticket {
      display: grid;
      justify-items: center;
      gap: var(--space-3);
    }

    .code {
      font-family: var(--font-family-mono);
      font-size: var(--font-size-lg);
      letter-spacing: 0.08em;
    }

    .hint {
      max-width: 34ch;
      font-size: var(--font-size-sm);
      text-align: center;
    }

    .facts {
      display: grid;
      gap: var(--space-3);
      width: 100%;
      margin: var(--space-3) 0 0;
      padding: var(--space-4);
      border-radius: var(--radius-lg);
      background: var(--surface-inset);
    }

    .facts div {
      display: flex;
      justify-content: space-between;
      gap: var(--space-4);
      font-size: var(--font-size-sm);
    }

    dt {
      color: var(--text-muted);
    }

    dd {
      margin: 0;
      font-weight: var(--font-weight-medium);
      text-align: right;
    }
  `,
})
export class Tickets {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly toasts = inject(ToastStore);
  readonly session = inject(SessionStore);

  readonly tickets = signal<Ticket[]>([]);
  readonly loading = signal(true);
  readonly failed = signal<string | null>(null);
  readonly showing = signal<Ticket | null>(null);

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
      this.tickets.set(await this.api.tickets());
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

  long(ticket: Ticket): string {
    return longEventTime(ticket.event.starts_at, ticket.event.timezone);
  }

  show(ticket: Ticket): void {
    this.showing.set(ticket);

    if (ticket.status === 'checked_in') {
      this.toasts.show('This one has already been scanned.', 'neutral');
    }
  }

  hide(): void {
    this.showing.set(null);
  }

  settings(): void {
    void this.router.navigate(['/settings']);
  }
}
