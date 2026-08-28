import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import {
  UiButton,
  UiEmpty,
  UiErrorState,
  UiPageHeader,
  UiIcon,
  UiSkeleton,
} from '@myfiesta/ui';
import { PencilLine, Plus, ReceiptText, Ticket, TrendingUp, Wallet } from 'lucide-angular';
import { Api } from '../../core/api';
import { Money, Overview } from '../../core/api.types';
import { formatMoney } from '../../core/money';
import { SessionStore } from '../../core/session';

/** One bar of the sales chart, in viewBox units. */
interface Bar {
  x: number;
  y: number;
  width: number;
  height: number;
  label: string;
}

/**
 * The screen the console opens on.
 *
 * The order here is deliberate and is not the order of a report. What needs
 * doing comes first, then the money, then the pace — a month of sales and
 * the latest orders side by side — then every upcoming event's progress. An
 * organizer whose event is published with nothing on sale is losing money
 * every hour it stays that way, and that belongs above the takings.
 */
@Component({
  selector: 'app-dashboard',
  imports: [RouterLink, UiPageHeader, UiButton, UiSkeleton, UiEmpty, UiErrorState, UiIcon],
  templateUrl: './dashboard.html',
})
export class Dashboard {
  protected readonly addIcon = Plus;
  protected readonly walletIcon = Wallet;
  protected readonly soldIcon = TrendingUp;
  protected readonly ticketIcon = Ticket;
  protected readonly draftIcon = PencilLine;
  protected readonly orderIcon = ReceiptText;

  private readonly api = inject(Api);
  readonly session = inject(SessionStore);

  readonly overview = signal<Overview | null>(null);
  readonly loading = signal(true);
  readonly error = signal(false);

  /** The chart's drawing space. Wider than tall: it is a pulse, not a poster. */
  readonly chartWidth = 600;
  readonly chartHeight = 120;

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(false);

    this.api.overview().subscribe({
      next: (overview) => {
        this.overview.set(overview);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set(true);
      },
    });
  }

  /**
   * The time of day, in the greeting.
   *
   * Trivial, and it is the one thing on the page that says this was written
   * for a person rather than generated for a record. An organizer opens this
   * at 2am after a door and at 11am before a launch.
   */
  readonly greeting = computed(() => {
    const name = this.session.user()?.name?.split(' ')[0];
    const hour = new Date().getHours();

    const time = hour < 5 ? 'Still up' : hour < 12 ? 'Morning' : hour < 18 ? 'Afternoon' : 'Evening';

    return name ? `${time}, ${name}` : time;
  });

  /**
   * The month's bars, scaled to the best day.
   *
   * A quiet day keeps a 2-unit stub rather than vanishing: thirty bars with
   * gaps where nothing sold reads as a broken chart, and the stub is the
   * honest height of zero once the scale is drawn.
   */
  readonly bars = computed<Bar[]>(() => {
    const series = this.overview()?.sales_by_day ?? [];
    if (series.length === 0) return [];

    const max = Math.max(...series.map((d) => d.net.amount), 1);
    const slot = this.chartWidth / series.length;
    const width = Math.max(slot - 4, 2);

    return series.map((day, i) => {
      const height = day.net.amount === 0 ? 2 : Math.max((day.net.amount / max) * this.chartHeight, 3);

      return {
        x: i * slot + (slot - width) / 2,
        y: this.chartHeight - height,
        width,
        height,
        label: `${this.chartDay(day.date)} — ${formatMoney(day.net)} · ${day.orders} ${day.orders === 1 ? 'order' : 'orders'}`,
      };
    });
  });

  readonly monthTotal = computed<Money | null>(() => {
    const series = this.overview()?.sales_by_day;
    if (!series || series.length === 0) return null;

    return {
      amount: series.reduce((sum, day) => sum + day.net.amount, 0),
      currency: series[0].net.currency,
    };
  });

  readonly chartRange = computed(() => {
    const series = this.overview()?.sales_by_day ?? [];
    if (series.length === 0) return null;

    return {
      from: this.chartDay(series[0].date),
      to: this.chartDay(series[series.length - 1].date),
    };
  });

  cash(money: Money): string {
    return formatMoney(money);
  }

  initial(name: string): string {
    return name.trim().charAt(0).toUpperCase() || '?';
  }

  /** "2h ago" — the pulse reads at a glance or not at all. */
  ago(iso: string): string {
    const minutes = Math.max(Math.round((Date.now() - new Date(iso).getTime()) / 60_000), 0);

    if (minutes < 60) return `${minutes}m ago`;
    if (minutes < 60 * 24) return `${Math.round(minutes / 60)}h ago`;

    return `${Math.round(minutes / (60 * 24))}d ago`;
  }

  sellWhen(startsAt: string, timezone: string): string {
    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
      // The venue's zone, never the reader's.
      timeZone: timezone,
    }).format(new Date(startsAt));
  }

  daysAway(startsAt: string): number {
    return Math.max(Math.ceil((new Date(startsAt).getTime() - Date.now()) / 86_400_000), 0);
  }

  soldPercent(issued: number, capacity: number | null): number | null {
    if (capacity === null || capacity === 0) return null;

    return Math.min(Math.round((issued / capacity) * 100), 100);
  }

  private chartDay(date: string): string {
    return new Intl.DateTimeFormat('en-CA', { day: 'numeric', month: 'short' }).format(
      // Parsed as a local date, not UTC midnight — 'YYYY-MM-DD' alone would
      // shift a day west of Greenwich.
      new Date(date + 'T12:00:00'),
    );
  }
}
