import { Component, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { HeldTicketStore } from '../../core/held-tickets';
import { Api, ApiError, Ticket } from '../../core/api';
import { SessionStore } from '../../core/session';
import { longEventTime } from '../../core/event-time';
import {
  Dialogs,
  MfBadge,
  MfButton,
  MfCard,
  MfEmpty,
  MfField,
  MfQr,
  MfScreen,
  MfSheet,
  MfSkeleton,
  ToastStore,
} from '../../ui';
import { Navigation } from '../../core/navigation';
import { MfReceipt } from './receipt';

/**
 * One ticket, full screen.
 *
 * The code is the whole point, so it gets the width of the phone and the top
 * of the screen: at the front of a queue nobody should be scrolling, and a
 * scanner wants the symbol as large and as bright as the screen can make it.
 *
 * Everything else — what it admits, whose name is on it, where to go — sits
 * underneath in the order it gets asked for at a door.
 */
@Component({
  selector: 'mf-ticket',
  imports: [FormsModule, MfScreen, MfCard, MfBadge, MfButton, MfQr, MfSheet, MfField, MfEmpty, MfSkeleton, MfReceipt],
  template: `
    <mf-screen [title]="ticket()?.event?.title ?? 'Ticket'" back backTo="/tickets">
      @if (loading()) {
        <mf-card><mf-skeleton height="16rem" /></mf-card>
      } @else if (!ticket()) {
        <mf-empty title="That ticket is not on this account" hint="It may have been transferred.">
          <button mfButton variant="secondary" (click)="leave()">Back to your tickets</button>
        </mf-empty>
      } @else if (ticket(); as held) {
        <section class="code">
          <mf-qr [code]="held.code" [size]="248" />
          <p class="digits tabular">{{ held.code }}</p>

          @if (held.status === 'checked_in') {
            <mf-badge>Already scanned</mf-badge>
          } @else {
            <mf-badge tone="success">Ready to scan</mf-badge>
          }

          <p class="hint subtle">
            @if (held.status === 'checked_in') {
              You are in. Keep it for re-entry if the venue allows it.
            } @else {
              Show this at the door. It works with no signal.
            }
          </p>
        </section>

        <mf-card class="facts">
          <dl>
            <div>
              <dt>When</dt>
              <dd>{{ when(held) }}</dd>
            </div>
            <div>
              <dt>Where</dt>
              <!-- The venue and its street, as the site's ticket page has
                   them — a city is not somewhere a door can be found. A
                   ticket saved on this phone before the venue came with it
                   still has its city. -->
              <dd class="where">
                @if (held.event.venue; as venue) {
                  <span class="venue">{{ venue.name }}</span>
                  <span class="subtle">{{ venue.address ? venue.address + ', ' : '' }}{{ held.event.city }}</span>
                } @else {
                  {{ held.event.city }}
                }
              </dd>
            </div>
            <div>
              <dt>Ticket</dt>
              <dd>{{ held.type ?? 'Standard' }}</dd>
            </div>
            <div>
              <dt>Name on it</dt>
              <dd>{{ held.holder_name ?? session.session()?.name ?? '—' }}</dd>
            </div>
          </dl>
        </mf-card>

        <div class="actions">
          <button mfButton variant="secondary" block (click)="viewEvent(held)">See the event</button>

          @if (held.status !== 'checked_in') {
            <button mfButton variant="ghost" block (click)="transferring.set(true)">Send to somebody else</button>
          }
        </div>

        @if (held.receipt; as receipt) {
          <mf-receipt [receipt]="receipt" [timezone]="held.event.timezone" />
        }
      }
    </mf-screen>

    <mf-sheet
      [open]="transferring()"
      heading="Send this ticket"
      subheading="It leaves your account and lands in theirs. You cannot take it back."
      (closed)="transferring.set(false)"
    >
      <form class="transfer" (ngSubmit)="transfer()">
        <mf-field label="Their name">
          <input #control name="name" autocomplete="name" [ngModel]="name()" (ngModelChange)="name.set($event)" />
        </mf-field>

        <mf-field label="Their email" [error]="error()">
          <input
            #control
            name="email"
            type="email"
            inputmode="email"
            autocapitalize="off"
            autocorrect="off"
            spellcheck="false"
            [ngModel]="email()"
            (ngModelChange)="email.set($event)"
          />
        </mf-field>

        <button mfButton type="submit" block label="Sending…" [loading]="sending()" [disabled]="!name().trim() || !email().trim()">
          Send the ticket
        </button>
      </form>
    </mf-sheet>
  `,
  styles: `
    .code {
      display: grid;
      justify-items: center;
      gap: var(--space-3);
      padding: var(--space-4) 0 var(--space-5);
    }

    .digits {
      font-family: var(--font-family-mono);
      font-size: var(--font-size-lg);
      letter-spacing: 0.08em;
    }

    .hint {
      max-width: 32ch;
      font-size: var(--font-size-sm);
      text-align: center;
    }

    .facts {
      display: block;
    }

    dl {
      display: grid;
      gap: var(--space-3);
      margin: 0;
    }

    dl div {
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

    .where {
      display: grid;
      gap: 2px;
      min-width: 0;
      overflow-wrap: anywhere;
    }

    .where .subtle {
      font-weight: var(--font-weight-regular);
    }

    .actions {
      display: grid;
      gap: var(--space-2);
      margin-top: var(--space-4);
    }

    .transfer {
      display: grid;
      gap: var(--space-4);
      padding-top: var(--space-2);
    }
  `,
})
export class TicketDetail {
  private readonly nav = inject(Navigation);
  private readonly api = inject(Api);
  private readonly held = inject(HeldTicketStore);
  private readonly router = inject(Router);
  private readonly toasts = inject(ToastStore);
  private readonly dialogs = inject(Dialogs);
  readonly session = inject(SessionStore);

  /** From the route. */
  readonly id = input.required<string>();

  readonly tickets = signal<Ticket[]>([]);
  readonly loading = signal(true);
  readonly stale = signal(false);

  readonly transferring = signal(false);
  readonly sending = signal(false);
  readonly name = signal('');
  readonly email = signal('');
  readonly error = signal<string | null>(null);

  readonly ticket = computed(() => this.tickets().find((held) => held.id === this.id()) ?? null);

  constructor() {
    queueMicrotask(() => void this.load());
  }

  private async load(): Promise<void> {
    try {
      const { tickets, stale } = await this.held.list();

      this.tickets.set(tickets);
      this.stale.set(stale);
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        await this.session.clear();
        await this.router.navigate(['/sign-in'], { replaceUrl: true });

        return;
      }

      this.toasts.show(error instanceof ApiError ? error.message : 'Could not load that ticket.', 'danger');
    } finally {
      this.loading.set(false);
    }
  }

  when(held: Ticket): string {
    return longEventTime(held.event.starts_at, held.event.timezone);
  }

  async transfer(): Promise<void> {
    const held = this.ticket();
    if (!held || this.sending()) return;

    const name = this.name().trim();
    const email = this.email().trim();

    // Said back with the address in it: a ticket sent to a typo is a ticket
    // gone, and this one cannot be taken back.
    const sure = await this.dialogs.confirm({
      title: `Send your ${held.type ?? ''} ticket to ${name}?`.replace(/\s+/g, ' '),
      body: `Your ticket for ${held.event.title} goes to ${email}, and stops working on this phone straight away.`,
      consequences: ['You cannot take it back.'],
      confirmLabel: 'Send the ticket',
      tone: 'danger',
    });

    if (!sure || this.sending()) return;

    this.sending.set(true);
    this.error.set(null);

    try {
      await this.api.transfer(held.id, email, name);

      this.transferring.set(false);
      this.toasts.show(`Sent to ${this.email().trim()}.`, 'success');
      await this.router.navigate(['/tickets'], { replaceUrl: true });
    } catch (error) {
      this.error.set(error instanceof ApiError ? error.message : 'That could not be sent.');
    } finally {
      this.sending.set(false);
    }
  }

  viewEvent(held: Ticket): void {
    void this.router.navigate(['/e', held.event.slug]);
  }

  leave(): void {
    this.nav.back('/tickets');
  }
}
