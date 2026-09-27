import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import {
  AlertTriangle,
  Building2,
  CalendarDays,
  CreditCard,
  Megaphone,
  Palette,
  Plug,
  Plus,
  Receipt,
  TicketPercent,
  Users,
  Wallet,
} from 'lucide-angular';
import type { Overview } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { formatMoney } from '../../core/money';
import { messageOf } from '../../core/errors';
import { ago, count } from '../../core/when';
import {
  Dialogs,
  MfButton,
  MfCard,
  MfEmpty,
  MfIcon,
  MfIconButton,
  MfList,
  MfRow,
  MfScreen,
  MfSkeleton,
  MfSpark,
  MfStat,
} from '../../ui';
import { MfEventRow } from './event-row';
import { MfVerifyEmail } from './verify-email';

/**
 * The organizer's tab: everything they run, from the phone in their pocket.
 *
 * In the order it is looked at. What needs them first — a night on sale with
 * nothing selling, a refund that failed — because that is why they opened
 * the app. Then the money, the month's sales as a shape, and every night on
 * sale with how full it is. Then the organization's own tools.
 *
 * Money is only here for somebody who may see it: the server sends null, not
 * zero, and a door-staff member of the team sees the events without the sums.
 */
@Component({
  selector: 'mf-manage-hub',
  imports: [MfScreen, MfIconButton, MfButton, MfCard, MfEmpty, MfSkeleton, MfStat, MfSpark, MfList, MfRow, MfIcon, MfEventRow, MfVerifyEmail],
  template: `
    <mf-screen title="Manage" [subtitle]="session.organization()?.name ?? null" large refreshable [busy]="loading()" (refresh)="load()">
      @if (session.organizations().length > 1) {
        <button mfIconButton screenActions [icon]="switchIcon" label="Switch organization" (click)="switchOrganization()"></button>
      }
      @if (session.can('events.create')) {
        <button mfIconButton screenActions tone="tonal" [icon]="plusIcon" label="New event" (click)="router.navigate(['/manage/events/new'])"></button>
      }

      <!-- Until the address is proved: what waits for it, and another link. -->
      <mf-verify-email />

      @if (overview(); as o) {
        @if (o.attention.length > 0) {
          <section class="attention" aria-label="Needs you">
            @for (item of o.attention; track item.event_id + item.reason) {
              <button type="button" class="alert" [class.danger]="item.severity === 'danger'" (click)="router.navigate(['/manage/events', item.event_id])">
                <mf-icon [icon]="alertIcon" />
                <span class="alert-text">
                  <strong>{{ item.reason }}</strong>
                  <span>{{ item.title }} — {{ item.detail }}</span>
                </span>
              </button>
            }
          </section>
        }

        @if (o.money; as money) {
          <div class="figures">
            <mf-stat class="span" lead label="Owed to you" [value]="cash(money.balance)" hint="Paid out after each event" />
            <mf-stat label="Sold, last 7 days" [value]="cash(money.sold_7d)" [hint]="countOf(money.orders_7d, 'order')" />
            <mf-stat label="Tickets out" [value]="o.selling.tickets_upcoming.toLocaleString()" [hint]="countOf(o.selling.upcoming_events, 'upcoming event')" />
          </div>

          @if (salesShape(); as shape) {
            <mf-card class="sales">
              <div class="sales-head">
                <span class="label">Sales, last 30 days</span>
                <span class="figure">{{ cash(money.sold_30d) }}</span>
              </div>
              <mf-spark [values]="shape" label="Sales by day over the last 30 days" />
            </mf-card>
          }
        } @else {
          <div class="figures">
            <mf-stat label="Tickets out" [value]="o.selling.tickets_upcoming.toLocaleString()" />
            <mf-stat label="On sale" [value]="o.selling.upcoming_events.toString()" [hint]="o.selling.draft_events ? countOf(o.selling.draft_events, 'draft') : null" />
          </div>
        }

        <h2 class="section">Selling now</h2>
        @if (o.selling_events.length === 0) {
          <mf-empty title="Nothing on sale" hint="A night you publish shows up here with how full it is.">
            @if (session.can('events.create')) {
              <button mfButton (click)="router.navigate(['/manage/events/new'])">Put on an event</button>
            }
          </mf-empty>
        } @else {
          <div class="stack">
            @for (event of o.selling_events; track event.id) {
              <mf-event-row [event]="event" />
            }
          </div>
        }

        @if (o.recent_orders?.length) {
          <mf-list class="block" heading="Latest orders">
            @for (order of o.recent_orders!.slice(0, 4); track order.reference) {
              <mf-row [label]="order.buyer_name" [sub]="order.event_title + ' · ' + since(order.paid_at)" [value]="cash(order.total)" />
            }
          </mf-list>
        }
      } @else if (error(); as message) {
        <mf-empty title="Could not load" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else {
        <div class="figures">
          <mf-card class="span"><mf-skeleton height="5rem" /></mf-card>
          <mf-card><mf-skeleton height="3rem" /></mf-card>
          <mf-card><mf-skeleton height="3rem" /></mf-card>
        </div>
      }

      <mf-list class="block" heading="Your organization">
        <mf-row label="Events" sub="Every night, upcoming and past" [icon]="eventsIcon" tone="brand" link="/manage/events" />
        @if (session.can('money.view')) {
          <mf-row label="Orders" sub="Search by name, email or reference" [icon]="ordersIcon" link="/manage/orders" />
        }
        @if (session.can('codes.manage')) {
          <mf-row label="Discount codes" [icon]="codesIcon" link="/manage/codes" />
        }
        @if (session.can('messages.send')) {
          <mf-row label="Campaigns" sub="Write to people who came before" [icon]="campaignIcon" link="/manage/campaigns" />
        }
        @if (session.can('money.view')) {
          <mf-row label="Payouts" [icon]="payoutIcon" tone="brand" link="/manage/payouts" />
        }
      </mf-list>

      <mf-list class="block" heading="Settings">
        @if (session.can('team.manage')) {
          <mf-row label="Team" sub="Who can do what" [icon]="teamIcon" link="/manage/team" />
        }
        @if (session.can('organization.brand')) {
          <mf-row label="How you appear" sub="Name, logo and about" [icon]="brandIcon" link="/manage/brand" />
        }
        @if (session.can('organization.integrations')) {
          <mf-row label="Integrations" sub="Webhooks and API keys" [icon]="plugIcon" link="/manage/integrations" />
        }
        <mf-row label="Scan tickets" sub="Open the door for one of your nights" [icon]="cardIcon" link="/manage/events" />
      </mf-list>
    </mf-screen>
  `,
  styles: `
    .attention {
      display: grid;
      gap: var(--space-2);
      margin-bottom: var(--space-5);
    }

    .alert {
      display: flex;
      align-items: flex-start;
      gap: var(--space-3);
      width: 100%;
      padding: var(--space-4);
      border: 0;
      border-radius: var(--radius-xl);
      background: color-mix(in srgb, var(--warning) 12%, var(--surface-raised));
      box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--warning) 35%, transparent);
      color: var(--text);
      font: inherit;
      text-align: left;
      cursor: pointer;
    }

    .alert mf-icon {
      color: var(--warning);
      margin-top: 1px;
    }

    .alert.danger {
      background: color-mix(in srgb, var(--danger) 10%, var(--surface-raised));
      box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--danger) 35%, transparent);
    }

    .alert.danger mf-icon {
      color: var(--danger-text);
    }

    .alert-text {
      display: grid;
      gap: 2px;
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .alert-text strong {
      color: var(--text);
      font-size: var(--font-size-base);
    }

    .figures {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-3);
    }

    .span {
      grid-column: 1 / -1;
    }

    .sales {
      display: block;
      margin-top: var(--space-3);
    }

    .sales-head {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      margin-bottom: var(--space-3);
    }

    .label {
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-semibold);
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--text-subtle);
    }

    .section {
      margin: var(--space-8) var(--space-1) var(--space-3);
      font-size: var(--font-size-lg);
    }

    .stack {
      display: grid;
      gap: var(--space-3);
    }

    .block {
      display: block;
      margin-top: var(--space-8);
    }
  `,
})
export class ManageHub {
  protected readonly session = inject(SessionStore);
  protected readonly router = inject(Router);
  private readonly organizer = inject(Organizer);
  private readonly dialogs = inject(Dialogs);

  protected readonly overview = signal<Overview | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly plusIcon = Plus;
  protected readonly switchIcon = Building2;
  protected readonly alertIcon = AlertTriangle;
  protected readonly eventsIcon = CalendarDays;
  protected readonly ordersIcon = Receipt;
  protected readonly codesIcon = TicketPercent;
  protected readonly campaignIcon = Megaphone;
  protected readonly payoutIcon = Wallet;
  protected readonly teamIcon = Users;
  protected readonly brandIcon = Palette;
  protected readonly plugIcon = Plug;
  protected readonly cardIcon = CreditCard;

  protected readonly cash = formatMoney;
  protected readonly countOf = count;
  protected readonly since = ago;

  /** The month as bar heights, or nothing when not one sale in it. */
  protected readonly salesShape = computed(() => {
    const days = this.overview()?.sales_by_day;

    if (!days || days.every((d) => d.net.amount === 0)) return null;

    return days.map((d) => d.net.amount);
  });

  constructor() {
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      this.overview.set(await this.organizer.overview());
    } catch (error) {
      this.error.set(messageOf(error, 'We could not reach the server. It is usually temporary.'));
    } finally {
      this.loading.set(false);
    }
  }

  async switchOrganization(): Promise<void> {
    const current = this.session.organization()?.id;
    const chosen = await this.dialogs.menu({
      title: 'Which organization',
      actions: this.session.organizations().map((o) => ({
        key: o.id,
        label: o.name,
        hint: o.id === current ? 'Looking at this one' : o.role,
      })),
    });

    if (!chosen || chosen === current) return;

    await this.session.chooseOrganization(chosen);
    this.overview.set(null);
    await this.load();
  }
}
