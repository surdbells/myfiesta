import { Component, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Api, ApiError, EventTotals, Guest } from '../../core/api';
import { SessionStore } from '../../core/session';
import { formatMoney } from '../../core/money';
import {
  MfBadge,
  MfButton,
  MfCard,
  MfEmpty,
  MfField,
  MfScreen,
  MfSelect,
  MfSkeleton,
  type MfOption,
} from '../../ui';

/**
 * One night: what it took, and who is inside.
 *
 * Money first, because that is what an organizer opens their phone for between
 * sets — and then the guest list, which is what they open it for when somebody
 * at the door says their name is on it.
 *
 * The guest search asks the server rather than filtering what was fetched: a
 * sold-out show is thousands of names, and the phone should not be holding all
 * of them to find one.
 */
@Component({
  selector: 'mf-event-night',
  imports: [FormsModule, MfScreen, MfCard, MfBadge, MfEmpty, MfField, MfSelect, MfSkeleton, MfButton],
  template: `
    <mf-screen [title]="title() ?? 'Tonight'" subtitle="What it took, and who is inside" back backTo="/manage">
      <button mfButton variant="ghost" size="sm" screenActions (click)="scan()">Scan</button>

      @if (totals(); as money) {
        <div class="figures">
          <mf-card>
            <p class="label">Sold</p>
            <p class="figure tabular">{{ cash(money.gross) }}</p>
          </mf-card>
          <mf-card>
            <p class="label">Owed to you</p>
            <p class="figure tabular">{{ cash(money.net) }}</p>
          </mf-card>
          <mf-card quiet>
            <p class="label">Tickets</p>
            <p class="figure small tabular">{{ money.tickets_issued }}</p>
          </mf-card>
          <mf-card quiet>
            <p class="label">Arrived</p>
            <p class="figure small tabular">{{ money.checked_in }}</p>
          </mf-card>
        </div>
      } @else if (loadingTotals()) {
        <mf-card><mf-skeleton height="4rem" /></mf-card>
      }

      <h2 class="section">Guest list</h2>

      <div class="filters">
        <mf-field label="Search">
          <input
            #control
            name="q"
            type="search"
            inputmode="search"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            placeholder="Name or email"
            [ngModel]="query()"
            (ngModelChange)="search($event)"
          />
        </mf-field>

        @if (typeOptions().length > 2) {
          <mf-select
            heading="Ticket type"
            placeholder="Any ticket"
            [options]="typeOptions()"
            [value]="type()"
            (valueChange)="type.set($event)"
          />
        }
      </div>

      @if (loadingGuests()) {
        <div class="stack">
          @for (n of [0, 1, 2]; track n) {
            <mf-card quiet><mf-skeleton height="2rem" /></mf-card>
          }
        </div>
      } @else if (failed(); as message) {
        <mf-empty title="Could not load the guest list" [hint]="message">
          <button mfButton variant="secondary" (click)="loadGuests()">Try again</button>
        </mf-empty>
      } @else if (shown().length === 0) {
        <mf-empty
          [title]="query() ? 'Nobody matches' : 'Nobody on the list yet'"
          [hint]="query() ? 'Try part of a name, or the email they bought with.' : null"
        />
      } @else {
        <ul class="stack">
          @for (guest of shown(); track guest.email + guest.name) {
            <li>
              <mf-card quiet>
                <div class="guest">
                  <span class="who">
                    <span class="name">{{ guest.name || 'No name given' }}</span>
                    <span class="email muted">{{ guest.email }}</span>
                  </span>
                  @if (guest.ticket_type) {
                    <span class="type muted">{{ guest.ticket_type }}</span>
                  }
                  <mf-badge [tone]="guest.checked_in ? 'success' : 'neutral'">
                    {{ guest.checked_in ? 'In' : 'Not yet' }}
                  </mf-badge>
                </div>
              </mf-card>
            </li>
          }
        </ul>
      }
    </mf-screen>
  `,
  styles: `
    .figures {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-3);
    }

    .label {
      font-size: var(--font-size-xs);
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--text-muted);
    }

    .figure {
      margin-top: var(--space-1);
      font-size: var(--font-size-xl);
      font-weight: var(--font-weight-bold);
    }

    .figure.small {
      font-size: var(--font-size-lg);
    }

    .section {
      margin: var(--space-6) 0 var(--space-3);
      font-size: var(--font-size-xs);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--text-subtle);
    }

    .filters {
      display: grid;
      gap: var(--space-3);
      margin-bottom: var(--space-4);
    }

    .stack {
      display: grid;
      gap: var(--space-2);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .guest {
      display: flex;
      align-items: center;
      gap: var(--space-3);
    }

    .who {
      display: grid;
      flex: 1;
      min-width: 0;
    }

    .name,
    .email,
    .type {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .name {
      font-weight: var(--font-weight-medium);
    }

    .email,
    .type {
      font-size: var(--font-size-sm);
    }
  `,
})
export class EventNight {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly session = inject(SessionStore);

  /** From the route. */
  readonly id = input.required<string>();

  /** Carried from the list, so the header is right on the first frame. */
  readonly title = input<string | null>(null);

  readonly totals = signal<EventTotals | null>(null);
  readonly loadingTotals = signal(true);

  readonly guests = signal<Guest[]>([]);
  readonly loadingGuests = signal(true);
  readonly failed = signal<string | null>(null);

  readonly query = signal('');
  readonly type = signal<string | null>(null);

  readonly typeOptions = computed<MfOption[]>(() => {
    const types = new Set(this.guests().map((guest) => guest.ticket_type).filter(Boolean) as string[]);

    return [{ value: '', label: 'Any ticket' }, ...[...types].sort().map((name) => ({ value: name, label: name }))];
  });

  readonly shown = computed(() => {
    const type = this.type();

    return type ? this.guests().filter((guest) => guest.ticket_type === type) : this.guests();
  });

  private typing: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    queueMicrotask(() => {
      void this.loadTotals();
      void this.loadGuests();
    });
  }

  private async loadTotals(): Promise<void> {
    try {
      this.totals.set(await this.api.summary(this.id()));
    } catch (error) {
      // Money is a permission: a manager sees it, somebody on the door does
      // not, and the screen is still useful without it.
      if (!(error instanceof ApiError) || error.status !== 403) {
        this.totals.set(null);
      }
    } finally {
      this.loadingTotals.set(false);
    }
  }

  async loadGuests(): Promise<void> {
    this.loadingGuests.set(true);
    this.failed.set(null);

    try {
      this.guests.set(await this.api.guests(this.id(), this.query()));
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        await this.session.clear();
        await this.router.navigate(['/sign-in'], { replaceUrl: true });

        return;
      }

      this.failed.set(error instanceof ApiError ? error.message : 'Something went wrong.');
    } finally {
      this.loadingGuests.set(false);
    }
  }

  /** Typed searches wait for a pause: one request per name, not per letter. */
  search(value: string): void {
    this.query.set(value);

    if (this.typing) clearTimeout(this.typing);

    this.typing = setTimeout(() => void this.loadGuests(), 300);
  }

  cash(money: { amount: number; currency: string }): string {
    return formatMoney(money);
  }

  scan(): void {
    void this.router.navigate(['/door'], { queryParams: { event: this.id(), title: this.title() } });
  }
}
