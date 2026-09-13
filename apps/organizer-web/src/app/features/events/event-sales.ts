import { Component, computed, inject, input, signal } from '@angular/core';
import { Api } from '../../core/api';
import { Money, SalesReport } from '../../core/api.types';
import { formatMoney } from '../../core/money';

/** One bar of the daily chart, in viewBox units. */
interface Bar {
  x: number;
  y: number;
  width: number;
  height: number;
  label: string;
  quiet: boolean;
}

/**
 * Where an event's sales came from: which days, which tickets, which codes.
 *
 * The totals above it say what the event took. This answers what an organizer
 * asks the week before — is Early Bird nearly gone, did Tuesday's post sell
 * anything, is the promoter link earning its commission.
 */
@Component({
  selector: 'app-event-sales',
  templateUrl: './event-sales.html',
})
export class EventSales {
  private readonly api = inject(Api);

  readonly eventId = input.required<string>();

  readonly report = signal<SalesReport | null>(null);
  readonly failed = signal(false);

  /** A month by default: the pace that matters is recent. */
  readonly showAll = signal(false);

  readonly chartWidth = 600;
  readonly chartHeight = 120;

  readonly days = computed(() => {
    const days = this.report()?.days ?? [];
    const shown = this.showAll() ? days : days.slice(-30);

    // Two weeks at least. A first sale yesterday would otherwise draw one bar
    // the width of the chart, which reads as a lot of anything. The days
    // before the first sale did sell nothing, so padding them is true.
    if (shown.length === 0 || shown.length >= 14) return shown;

    const first = new Date(shown[0].date + 'T12:00:00Z');
    const padding = Array.from({ length: 14 - shown.length }, (_, i) => {
      const day = new Date(first);
      day.setUTCDate(first.getUTCDate() - (14 - shown.length - i));

      return { date: day.toISOString().slice(0, 10), orders: 0, tickets: 0, revenue: 0 };
    });

    return [...padding, ...shown];
  });

  readonly hasMoreDays = computed(() => (this.report()?.days.length ?? 0) > 30);

  readonly bars = computed<Bar[]>(() => {
    const days = this.days();
    if (days.length === 0) return [];

    const max = Math.max(...days.map((d) => d.tickets), 1);
    const slot = this.chartWidth / days.length;
    const currency = this.report()!.currency;

    return days.map((day, i) => {
      // A quiet day keeps a stub, so the gap reads as "nothing sold" rather
      // than as a hole in the chart.
      const height = day.tickets === 0 ? 2 : Math.max((day.tickets / max) * this.chartHeight, 3);

      return {
        x: i * slot + slot * 0.15,
        y: this.chartHeight - height,
        width: slot * 0.7,
        height,
        quiet: day.tickets === 0,
        label: `${this.dayLabel(day.date)} — ${day.tickets} ${day.tickets === 1 ? 'ticket' : 'tickets'}, ${formatMoney({ amount: day.revenue, currency } as Money)}`,
      };
    });
  });

  readonly range = computed(() => {
    const days = this.days();

    return days.length ? { from: this.dayLabel(days[0].date), to: this.dayLabel(days[days.length - 1].date) } : null;
  });

  /** The last seven days against the seven before: the one comparison worth a sentence. */
  readonly pace = computed(() => {
    const days = this.report()?.days ?? [];
    if (days.length < 2) return null;

    const sum = (slice: typeof days) => slice.reduce((total, day) => total + day.tickets, 0);
    const lastWeek = sum(days.slice(-7));
    const weekBefore = days.length > 7 ? sum(days.slice(-14, -7)) : null;

    return { lastWeek, weekBefore };
  });

  readonly best = computed(() => {
    const days = this.report()?.days ?? [];
    if (days.length === 0) return null;

    const top = days.reduce((a, b) => (b.tickets > a.tickets ? b : a));

    return top.tickets > 0 ? { date: this.dayLabel(top.date), tickets: top.tickets } : null;
  });

  readonly totals = computed(() => {
    const types = this.report()?.ticket_types ?? [];

    return {
      sold: types.reduce((n, t) => n + t.sold, 0),
      comps: types.reduce((n, t) => n + t.comps, 0),
    };
  });

  ngOnInit(): void {
    this.api.sales(this.eventId()).subscribe({
      next: (report) => this.report.set(report),
      error: () => this.failed.set(true),
    });
  }

  money = formatMoney;

  /** Percent of a limited tier taken, sold and comps together: both use a place. */
  taken(type: SalesReport['ticket_types'][number]): number | null {
    if (type.capacity === null || type.capacity === 0) return null;

    return Math.min(Math.round(((type.sold + type.comps) / type.capacity) * 100), 100);
  }

  codeName(code: SalesReport['codes'][number]): string {
    return code.code ?? (code.ref_slug ? `?ref=${code.ref_slug}` : 'Unknown code');
  }

  private dayLabel(date: string): string {
    // Noon, so the date does not shift a day either side of Greenwich.
    return new Intl.DateTimeFormat('en-CA', { day: 'numeric', month: 'short' }).format(new Date(date + 'T12:00:00'));
  }
}
