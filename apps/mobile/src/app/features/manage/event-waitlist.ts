import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import type { OrganizerEventDetail, WaitlistPage } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { messageOf } from '../../core/errors';
import { ago } from '../../core/when';
import {
  MfAvatar,
  MfBadge,
  MfButton,
  MfCard,
  MfEmpty,
  MfField,
  MfScreen,
  MfSheet,
  MfSkeleton,
  MfStat,
  MfStepper,
  ToastStore,
} from '../../ui';
import { EventContext } from './event-context';

type Entry = WaitlistPage['data'][number];

/**
 * People who asked to hear when tickets come back.
 *
 * Telling them is refused while nothing can be bought — an email that says
 * "tickets are back" and a page that says "sold out" loses the organizer that
 * person — so the button says why it is not there rather than just greying.
 * The first in line are told first, as many as there is room for.
 */
@Component({
  selector: 'mf-event-waitlist',
  imports: [MfScreen, MfCard, MfStat, MfBadge, MfButton, MfEmpty, MfSkeleton, MfSheet, MfStepper, MfField, MfAvatar],
  template: `
    <mf-screen title="Waitlist" [subtitle]="event()?.title ?? null" back [backTo]="'/manage/events/' + id()" refreshable [busy]="loading()" (refresh)="load()">
      @if (error(); as message) {
        <mf-empty title="Could not load the waitlist" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (page(); as p) {
        <div class="stats">
          <mf-stat lead label="Waiting" [value]="p.summary.waiting.toLocaleString()" [hint]="p.summary.waiting_tickets + ' tickets wanted'" />
          <mf-stat label="Told" [value]="p.summary.notified.toLocaleString()" />
          <mf-stat label="Bought" [value]="p.summary.purchased.toLocaleString()" [portion]="p.summary.notified ? p.summary.purchased / p.summary.notified : null" />
        </div>

        <div class="tell">
          <button mfButton block [disabled]="p.summary.waiting === 0 || !p.on_sale" (click)="openTell(p)">Tell the waitlist</button>
          @if (!p.on_sale && p.summary.waiting > 0) {
            <p class="why">Nothing is on sale right now. Put a tier back on sale, or add tickets, and this opens.</p>
          }
        </div>

        @if (p.data.length === 0) {
          <mf-empty title="Nobody is waiting" hint="When it sells out, the event page offers a waitlist instead of a dead end." />
        } @else {
          <ul class="people">
            @for (entry of p.data; track entry.id) {
              <li>
                <mf-avatar [name]="entry.name || entry.email" [size]="36" />
                <span class="who">
                  <span class="name">{{ entry.name || entry.email }}</span>
                  <span class="sub">{{ entry.quantity }} {{ entry.quantity === 1 ? 'ticket' : 'tickets' }} · joined {{ since(entry.joined_at) }}</span>
                </span>
                <mf-badge [tone]="tone(entry)">{{ label(entry) }}</mf-badge>
              </li>
            }
          </ul>
        }
      } @else {
        <mf-card><mf-skeleton height="5rem" /></mf-card>
      }
    </mf-screen>

    <mf-sheet [open]="telling()" heading="Tell the waitlist" subheading="The first in line hear first." closable (closed)="telling.set(false)">
      <div class="form">
        <mf-stepper label="How many people" [hint]="'Of ' + (page()?.summary?.waiting ?? 0) + ' waiting'" [min]="1" [max]="page()?.summary?.waiting ?? 1" [value]="limit()" (valueChange)="limit.set($event)" />
        <mf-field label="A line from you" optional [limit]="500" [count]="note().length">
          <textarea [value]="note()" (input)="note.set($any($event.target).value)" maxlength="500" placeholder="We opened the balcony — first come, first served."></textarea>
        </mf-field>
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="telling.set(false)">Not now</button>
        <button mfButton [loading]="sending()" (click)="tell()">Email {{ limit() }} {{ limit() === 1 ? 'person' : 'people' }}</button>
      </ng-container>
    </mf-sheet>
  `,
  styles: `
    .stats {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: var(--space-3);
    }

    .stats mf-stat:first-child {
      grid-column: 1 / -1;
    }

    .tell {
      display: grid;
      gap: var(--space-2);
      margin: var(--space-5) 0;
    }

    .why {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      text-align: center;
    }

    .people {
      display: grid;
      margin: 0;
      padding: 0;
      list-style: none;
      border-radius: var(--radius-xl);
      background: var(--surface-raised);
      box-shadow: var(--shadow-sm);
    }

    .people li {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      padding: var(--space-3) var(--space-4);
    }

    .people li + li {
      border-top: 1px solid var(--border-subtle);
    }

    .who {
      flex: 1;
      display: grid;
      min-width: 0;
    }

    .name {
      font-weight: var(--font-weight-medium);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .sub {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .form {
      display: grid;
      gap: var(--space-4);
    }
  `,
})
export class EventWaitlist implements OnInit {
  readonly id = input.required<string>();

  private readonly organizer = inject(Organizer);
  private readonly context = inject(EventContext);
  private readonly toasts = inject(ToastStore);

  protected readonly event = signal<OrganizerEventDetail | null>(null);
  protected readonly page = signal<WaitlistPage | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly telling = signal(false);
  protected readonly limit = signal(1);
  protected readonly note = signal('');
  protected readonly sending = signal(false);

  protected readonly since = ago;
  protected readonly waiting = computed(() => this.page()?.summary.waiting ?? 0);

  ngOnInit(): void {
    this.event.set(this.context.peek(this.id()));
    void this.context.get(this.id()).then((e) => this.event.set(e)).catch(() => undefined);
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      this.page.set(await this.organizer.waitlist(this.id()));
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected tone(entry: Entry): 'success' | 'warning' | 'neutral' {
    return entry.status === 'purchased' ? 'success' : entry.status === 'notified' ? 'warning' : 'neutral';
  }

  protected label(entry: Entry): string {
    return entry.status === 'purchased' ? 'Bought' : entry.status === 'notified' ? `Told ${ago(entry.notified_at)}` : 'Waiting';
  }

  protected openTell(p: WaitlistPage): void {
    this.limit.set(p.summary.waiting);
    this.note.set('');
    this.telling.set(true);
  }

  protected async tell(): Promise<void> {
    this.sending.set(true);

    try {
      const result = await this.organizer.notifyWaitlist(this.id(), this.limit(), this.note().trim() || null);
      this.telling.set(false);
      this.toasts.show(result.message, 'success');
      await this.load();
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
    } finally {
      this.sending.set(false);
    }
  }
}
