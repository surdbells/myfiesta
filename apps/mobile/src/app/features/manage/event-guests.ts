import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { Gift, Share } from 'lucide-angular';
import type { Guest, OrganizerEventDetail, PageMeta, TicketType } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { fieldErrors, messageOf } from '../../core/errors';
import { shareFile } from '../../core/share-file';
import { ago } from '../../core/when';
import {
  MfAvatar,
  MfBadge,
  MfButton,
  MfCard,
  MfChips,
  MfEmpty,
  MfField,
  MfIconButton,
  MfScreen,
  MfSearch,
  MfSelect,
  MfSheet,
  MfSkeleton,
  MfStepper,
  ToastStore,
  type MfChip,
  type MfOption,
} from '../../ui';
import { EventContext } from './event-context';

/**
 * Who is coming, and who is in.
 *
 * The question at a door is never "who is coming" — it is "is this person on
 * the list", and then "who has not arrived yet". So the search is the first
 * thing on the screen, the filter is arrived / not yet, and the count says
 * how much of the room is in.
 *
 * The search asks the server rather than filtering what was fetched: a
 * sold-out show is thousands of names, and the phone should not hold all of
 * them to find one. No ticket code is ever on this screen — a guest list is
 * shown to people at a door, and a code is a way in.
 */
@Component({
  selector: 'mf-event-guests',
  imports: [MfScreen, MfIconButton, MfSearch, MfChips, MfSelect, MfCard, MfBadge, MfAvatar, MfButton, MfEmpty, MfSkeleton, MfSheet, MfField, MfStepper],
  template: `
    <mf-screen title="Guest list" [subtitle]="event()?.title ?? null" back [backTo]="'/manage/events/' + id()" refreshable [busy]="loading()" (refresh)="reload()">
      @if (canIssue()) {
        <button mfIconButton screenActions [icon]="giftIcon" label="Give a complimentary ticket" (click)="startIssue()"></button>
      }
      <button mfIconButton screenActions [icon]="exportIcon" label="Export the guest list" (click)="exportList()"></button>

      <div screenBar class="bar-tools">
        <mf-search placeholder="Name or email" [(value)]="query" (searched)="reload()" />
        <mf-chips ariaLabel="Who to show" [options]="statusChips()" [value]="status()" (valueChange)="status.set($event); reload()" />
      </div>

      @if (tierOptions().length > 2) {
        <mf-select class="tier" heading="Ticket type" placeholder="Any ticket" [options]="tierOptions()" [value]="tier()" (valueChange)="tier.set($event ?? ''); reload()" />
      }

      @if (meta(); as m) {
        <p class="count">
          <strong>{{ m.checked_in }}</strong> of {{ total() }} in
          @if (m.total !== total()) {
            <span class="muted"> · showing {{ m.total }}</span>
          }
        </p>
      }

      @if (error(); as message) {
        <mf-empty title="Could not load the guest list" [hint]="message">
          <button mfButton variant="secondary" (click)="reload()">Try again</button>
        </mf-empty>
      } @else if (guests().length === 0 && loading()) {
        <div class="list">
          @for (n of [0, 1, 2, 3]; track n) {
            <mf-card quiet><mf-skeleton height="2.5rem" /></mf-card>
          }
        </div>
      } @else if (guests().length === 0) {
        <mf-empty
          [title]="query() || status() || tier() ? 'Nobody matches' : 'Nobody on the list yet'"
          [hint]="query() ? 'Try part of a name, or the email they bought with.' : null"
        />
      } @else {
        <ul class="list">
          @for (guest of guests(); track guest.id) {
            <li>
              <button type="button" class="guest" (click)="open.set(guest)">
                <mf-avatar [name]="guest.name" [size]="40" />
                <span class="who">
                  <span class="name">{{ guest.name || 'No name given' }}</span>
                  <span class="sub">{{ guest.ticket_type ?? 'Ticket' }}@if (guest.email) { · {{ guest.email }} }</span>
                </span>
                <mf-badge [tone]="guest.checked_in ? 'success' : 'neutral'">{{ guest.checked_in ? 'In' : 'Not yet' }}</mf-badge>
              </button>
            </li>
          }
        </ul>

        @if (hasMore()) {
          <button mfButton class="more" variant="secondary" block [loading]="loading()" (click)="more()">Show more</button>
        }
      }
    </mf-screen>

    <!-- One guest: what they answered at checkout, which is the reason for asking. -->
    <mf-sheet [open]="!!open()" [heading]="open()?.name || 'Guest'" [subheading]="open()?.email ?? null" closable (closed)="open.set(null)">
      @if (open(); as guest) {
        <dl class="facts">
          <div>
            <dt>Ticket</dt>
            <dd>{{ guest.ticket_type ?? '—' }}</dd>
          </div>
          <div>
            <dt>At the door</dt>
            <dd>{{ guest.checked_in ? 'In, ' + since(guest.checked_in_at) : 'Not arrived yet' }}</dd>
          </div>
          @for (answer of guest.answers; track $index) {
            <div>
              <dt>{{ answer.label ?? 'Answer' }}</dt>
              <dd>{{ answer.value }}</dd>
            </div>
          }
        </dl>
      }
    </mf-sheet>

    <mf-sheet [open]="issuing()" heading="Give a complimentary ticket" subheading="Emailed to them now. It counts against the tier's limit and costs them nothing." closable (closed)="issuing.set(false)">
      <div class="form">
        @if (issueError(); as message) {
          <p class="form-error" role="alert">{{ message }}</p>
        }
        <mf-select heading="Which ticket" placeholder="Choose a ticket type" [options]="issueTiers()" [value]="issueTier()" (valueChange)="issueTier.set($event ?? '')" />
        <mf-field label="Their name" [error]="issueErr('name')">
          <input autocomplete="off" [value]="issueName()" (input)="issueName.set($any($event.target).value)" placeholder="Tunde Bakare" />
        </mf-field>
        <mf-field label="Their email" [error]="issueErr('email')">
          <input type="email" inputmode="email" autocomplete="off" autocapitalize="off" [value]="issueEmail()" (input)="issueEmail.set($any($event.target).value)" placeholder="tunde@example.com" />
        </mf-field>
        <mf-stepper label="How many" [min]="1" [max]="20" [value]="issueQuantity()" (valueChange)="issueQuantity.set($event)" />
        <mf-field label="A note for your records" optional>
          <input [value]="issueNote()" (input)="issueNote.set($any($event.target).value)" placeholder="Guest of the DJ" />
        </mf-field>
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="issuing.set(false)">Cancel</button>
        <button mfButton [loading]="sending()" [disabled]="!issueReady()" (click)="issue()">Send it</button>
      </ng-container>
    </mf-sheet>
  `,
  styles: `
    .bar-tools {
      display: grid;
      gap: var(--space-3);
    }

    .tier {
      margin-bottom: var(--space-3);
    }

    .count {
      margin: 0 var(--space-1) var(--space-3);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .count strong {
      font-family: var(--font-family-display);
      font-size: var(--font-size-lg);
      color: var(--text);
    }

    .list {
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
      width: 100%;
      padding: var(--space-3) var(--space-4);
      border: 0;
      border-radius: var(--radius-xl);
      background: var(--surface-raised);
      box-shadow: inset 0 0 0 1px var(--border-subtle);
      color: var(--text);
      font: inherit;
      text-align: left;
      cursor: pointer;
    }

    .guest:active {
      background: var(--surface-hover);
    }

    .who {
      flex: 1;
      display: grid;
      gap: 1px;
      min-width: 0;
    }

    .name {
      font-weight: var(--font-weight-semibold);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .sub {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .more {
      margin-top: var(--space-4);
    }

    .facts {
      display: grid;
      gap: var(--space-3);
      margin: 0;
    }

    .facts div {
      display: grid;
      gap: 2px;
    }

    dt {
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-semibold);
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--text-subtle);
    }

    dd {
      margin: 0;
      font-weight: var(--font-weight-medium);
    }

    .form {
      display: grid;
      gap: var(--space-4);
    }

    .form-error {
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-lg);
      background: color-mix(in srgb, var(--danger) 10%, transparent);
      color: var(--danger-text);
      font-size: var(--font-size-sm);
    }
  `,
})
export class EventGuests implements OnInit {
  readonly id = input.required<string>();

  private readonly organizer = inject(Organizer);
  private readonly session = inject(SessionStore);
  private readonly context = inject(EventContext);
  private readonly toasts = inject(ToastStore);

  protected readonly event = signal<OrganizerEventDetail | null>(null);
  protected readonly guests = signal<Guest[]>([]);
  protected readonly meta = signal<(PageMeta & { checked_in: number }) | null>(null);
  protected readonly total = signal(0);
  protected readonly types = signal<TicketType[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly query = signal('');
  protected readonly status = signal('');
  protected readonly tier = signal('');
  protected readonly open = signal<Guest | null>(null);

  protected readonly issuing = signal(false);
  protected readonly sending = signal(false);
  protected readonly issueTier = signal('');
  protected readonly issueName = signal('');
  protected readonly issueEmail = signal('');
  protected readonly issueQuantity = signal(1);
  protected readonly issueNote = signal('');
  protected readonly issueError = signal<string | null>(null);
  protected readonly issueErrors = signal<Record<string, string>>({});

  protected readonly giftIcon = Gift;
  protected readonly exportIcon = Share;
  protected readonly since = ago;

  protected readonly canIssue = computed(() => this.session.can('tickets.manage'));

  protected readonly statusChips = computed<MfChip[]>(() => [
    { value: '', label: 'Everyone' },
    { value: 'checked_in', label: 'Arrived' },
    { value: 'valid', label: 'Not yet' },
  ]);

  protected readonly tierOptions = computed<MfOption[]>(() => [
    { value: '', label: 'Any ticket' },
    ...this.types().map((t) => ({ value: t.id, label: t.name })),
  ]);

  protected readonly issueTiers = computed<MfOption[]>(() => this.types().map((t) => ({ value: t.id, label: t.name, hint: t.remaining === null ? 'No limit' : `${t.remaining} left` })));

  protected readonly issueReady = computed(
    () => !!this.issueTier() && this.issueName().trim() !== '' && /.+@.+\..+/.test(this.issueEmail().trim()) && !this.sending(),
  );

  protected hasMore(): boolean {
    const m = this.meta();
    return !!m && m.current_page < m.last_page;
  }

  ngOnInit(): void {
    this.event.set(this.context.peek(this.id()));
    void this.context.get(this.id()).then((e) => this.event.set(e)).catch(() => undefined);
    void this.organizer.ticketTypes(this.id()).then((t) => this.types.set(t)).catch(() => undefined);
    void this.reload();
  }

  async reload(): Promise<void> {
    this.guests.set([]);
    await this.fetch(1);
  }

  protected more(): void {
    void this.fetch((this.meta()?.current_page ?? 1) + 1);
  }

  private async fetch(page: number): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      const result = await this.organizer.guests(this.id(), this.query(), page, { status: this.status(), ticket_type_id: this.tier() });

      this.guests.update((rows) => (page === 1 ? result.data : [...rows, ...result.data]));
      this.meta.set(result.meta);

      // The room's size, for "12 of 80 in". A filter narrows the rows, not
      // the room — taking the filtered count here read as 12 of 3.
      if (!this.query() && !this.status() && !this.tier()) this.total.set(result.meta.total);
      else if (this.total() === 0) this.total.set(result.meta.total);
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected issueErr(field: string): string | null {
    return this.issueErrors()[field] ?? null;
  }

  protected startIssue(): void {
    this.issueTier.set(this.types()[0]?.id ?? '');
    this.issueName.set('');
    this.issueEmail.set('');
    this.issueQuantity.set(1);
    this.issueNote.set('');
    this.issueError.set(null);
    this.issueErrors.set({});
    this.issuing.set(true);
  }

  protected async issue(): Promise<void> {
    if (!this.issueReady()) return;

    this.sending.set(true);
    this.issueError.set(null);
    this.issueErrors.set({});

    try {
      const result = await this.organizer.issueTicket(this.id(), {
        ticket_type_id: this.issueTier(),
        name: this.issueName().trim(),
        email: this.issueEmail().trim(),
        quantity: this.issueQuantity(),
        note: this.issueNote().trim() || null,
        // Always emailed from the phone: the ticket reaches its holder, and no
        // code is ever put on this screen to be read over a shoulder.
        send_email: true,
      });

      this.issuing.set(false);
      this.toasts.show(`${result.message} Emailed to ${this.issueEmail().trim()}.`, 'success');
      await this.reload();
    } catch (error) {
      this.issueErrors.set(fieldErrors(error));
      this.issueError.set(Object.keys(this.issueErrors()).length ? null : messageOf(error));
    } finally {
      this.sending.set(false);
    }
  }

  protected async exportList(): Promise<void> {
    try {
      const csv = await this.organizer.exportGuests(this.id());
      const name = (this.event()?.slug ?? 'guests').replace(/[^a-z0-9-]/gi, '-');

      await shareFile(csv, `${name}-guest-list.csv`, `Guest list — ${this.event()?.title ?? ''}`);
    } catch (error) {
      this.toasts.show(messageOf(error, 'The guest list could not be exported.'), 'danger');
    }
  }
}
