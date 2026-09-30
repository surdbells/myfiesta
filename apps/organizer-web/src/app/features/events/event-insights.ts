import { Component, computed, input } from '@angular/core';
import { DatePipe } from '@angular/common';
import { UiScrollRegion } from '@myfiesta/ui';
import { InsightSource, InsightSummary, SalesInsights } from '../../core/api.types';
import { formatMoney } from '../../core/money';

interface Line {
  points: string;
  last: { x: number; y: number } | null;
}

const SOURCE_LABELS: Record<InsightSource, { label: string; hint: string }> = {
  direct: { label: 'Found it themselves', hint: 'Search, a shared link, your socials' },
  link: { label: 'Promoter links', hint: 'Links and codes you gave out' },
  campaign: { label: 'Your emails', hint: 'Campaigns sent from here' },
  embed: { label: 'Your website', hint: 'The tickets on your own site' },
  door: { label: 'At the door', hint: 'Sold on the night' },
};

/**
 * How the night is doing, beyond what it sold.
 *
 * Four answers, in the order an organizer asks: how many looked and how many
 * of them bought; how it is pacing against last time; where the buyers came
 * from; and, once the doors have opened, how many turned up.
 *
 * Every rate says what it is a rate of, in words, beside it. "3.2%" alone is a
 * number people argue about; "32 of every 1,000 who looked bought" is one
 * they act on.
 */
@Component({
  selector: 'app-event-insights',
  imports: [DatePipe, UiScrollRegion],
  template: `
    @let s = insights().summary;
    @let prev = insights().previous;

    <div class="grid gap-4">
      <!-- The headline four. -->
      <dl class="m-0 grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-px overflow-hidden rounded-xl border border-border bg-border shadow-(--shadow-card)">
        <div class="bg-surface-raised px-5 py-4">
          <dt class="text-xs font-medium uppercase tracking-[0.06em] text-text-muted">Looked</dt>
          <dd class="figure m-0 mt-1 text-2xl">{{ (s.views + s.embed_views).toLocaleString() }}</dd>
          <p class="mt-1 text-xs text-text-muted">
            @if (s.embed_views > 0) {
              {{ s.embed_views.toLocaleString() }} on your own site
            } @else {
              Page views, once per visit
            }
          </p>
        </div>

        <div class="bg-surface-raised px-5 py-4">
          <dt class="text-xs font-medium uppercase tracking-[0.06em] text-text-muted">Bought</dt>
          <dd class="figure m-0 mt-1 text-2xl">{{ percent(s.conversion) }}</dd>
          <p class="mt-1 text-xs text-text-muted">
            @if (s.conversion !== null) {
              {{ perThousand(s.conversion) }} of every 1,000 who looked
            } @else {
              Counted from September 2026
            }
          </p>
        </div>

        <div class="bg-surface-raised px-5 py-4">
          <dt class="text-xs font-medium uppercase tracking-[0.06em] text-text-muted">Came</dt>
          <dd class="figure m-0 mt-1 text-2xl">{{ s.attendance !== null ? percent(s.attendance) : '—' }}</dd>
          <p class="mt-1 text-xs text-text-muted">
            @if (s.attendance !== null) {
              {{ s.arrived.toLocaleString() }} of {{ s.people.toLocaleString() }} people through the door
            } @else {
              Once the doors open
            }
          </p>
        </div>

        <div class="bg-surface-raised px-5 py-4">
          <dt class="text-xs font-medium uppercase tracking-[0.06em] text-text-muted">Against last time</dt>
          <dd class="figure m-0 mt-1 text-2xl">{{ versus() ?? '—' }}</dd>
          <p class="mt-1 text-xs text-text-muted">
            @if (byNowLastTime(); as then) {
              {{ then.tickets }} sold by this point before {{ prev!.title }}
            } @else if (prev) {
              Tickets, against {{ prev.title }}
            } @else {
              Your first night here
            }
          </p>
        </div>
      </dl>

      <!-- Pace: this night against the last, lined up by days to go. -->
      @if (insights().pace.this.length > 1 || (insights().pace.previous?.length ?? 0) > 1) {
        <div class="rounded-(--radius-card) border border-border-subtle bg-surface-raised p-6 shadow-(--shadow-card)">
          <header class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
            <h3 class="text-base font-semibold">Pace</h3>
            <p class="flex flex-wrap items-center gap-x-4 text-xs text-text-muted">
              <span class="inline-flex items-center gap-1.5"><span class="inline-block h-0.5 w-4 bg-primary"></span>This night</span>
              @if (prev) {
                <span class="inline-flex items-center gap-1.5"><span class="inline-block h-0 w-4 border-t-2 border-dashed border-text-subtle"></span>{{ prev.title }}</span>
              }
            </p>
          </header>

          <svg class="mt-4 block h-36 w-full overflow-visible" [attr.viewBox]="'0 0 ' + width + ' ' + height" preserveAspectRatio="none" role="img" [attr.aria-label]="paceLabel()">
            <line x1="0" [attr.y1]="height" [attr.x2]="width" [attr.y2]="height" stroke="var(--border)" stroke-width="1" vector-effect="non-scaling-stroke" />
            @if (lines().previous; as p) {
              <polyline [attr.points]="p.points" fill="none" stroke="var(--text-subtle)" stroke-width="2" stroke-dasharray="5 4" vector-effect="non-scaling-stroke" />
            }
            @if (lines().current; as c) {
              <polyline [attr.points]="c.points" fill="none" stroke="var(--primary)" stroke-width="2.5" vector-effect="non-scaling-stroke" />
            }
          </svg>
          <div class="mt-2 flex justify-between text-xs text-text-subtle">
            <span>{{ window }} days before</span>
            <span>The night</span>
          </div>
        </div>
      }

      <!-- Where they came from. -->
      @if (insights().sources.length > 0) {
        <div class="overflow-hidden rounded-(--radius-card) border border-border-subtle bg-surface-raised shadow-(--shadow-card)">
          <h3 class="px-6 pt-5 text-base font-semibold">Where buyers came from</h3>
          <div uiScrollRegion="Where buyers came from">
            <table class="mt-3 w-full text-left text-sm tabular-nums">
              <thead class="text-xs text-text-muted">
                <tr>
                  <th class="px-6 py-2 font-medium">From</th>
                  <th class="px-3 py-2 text-right font-medium">Orders</th>
                  <th class="px-3 py-2 text-right font-medium">Tickets</th>
                  <th class="px-6 py-2 text-right font-medium">Earned</th>
                </tr>
              </thead>
              <tbody>
                @for (row of insights().sources; track row.source) {
                  <tr class="border-t border-border">
                    <td class="px-6 py-3">
                      <span class="font-medium">{{ labels[row.source].label }}</span>
                      <span class="block text-xs text-text-muted">{{ labels[row.source].hint }}</span>
                      <!-- Share of tickets, drawn: the order is easier to see than to read. -->
                      <span class="mt-1.5 block h-1 rounded-full bg-surface-inset" aria-hidden="true">
                        <span class="block h-1 rounded-full bg-primary" [style.width.%]="share(row.tickets)"></span>
                      </span>
                    </td>
                    <td class="px-3 py-3 text-right">{{ row.orders }}</td>
                    <td class="px-3 py-3 text-right">{{ row.tickets }}</td>
                    <td class="px-6 py-3 text-right">{{ money(row.revenue) }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        </div>
      }

      <!-- Side by side with the last night, on the same measures. -->
      @if (prev) {
        <div class="overflow-hidden rounded-(--radius-card) border border-border-subtle bg-surface-raised shadow-(--shadow-card)">
          <h3 class="px-6 pt-5 text-base font-semibold">Against {{ prev.title }}</h3>
          <p class="px-6 text-xs text-text-muted">{{ prev.starts_at | date: 'd MMM y' }} — your last night before this one.</p>
          <div [uiScrollRegion]="'Against ' + prev.title">
            <table class="mt-3 w-full text-left text-sm tabular-nums">
              <thead class="text-xs text-text-muted">
                <tr>
                  <th class="px-6 py-2 font-medium"><span class="sr-only">Measure</span></th>
                  <th class="px-3 py-2 text-right font-medium">This night</th>
                  <th class="px-6 py-2 text-right font-medium">Last time</th>
                </tr>
              </thead>
              <tbody>
                @for (row of comparison(s, prev.summary); track row.label) {
                  <tr class="border-t border-border">
                    <th class="px-6 py-2.5 font-normal text-text-muted" scope="row">{{ row.label }}</th>
                    <td class="px-3 py-2.5 text-right font-medium">{{ row.now }}</td>
                    <td class="px-6 py-2.5 text-right">{{ row.then }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        </div>
      }
    </div>
  `,
})
export class EventInsights {
  readonly insights = input.required<SalesInsights>();

  readonly labels = SOURCE_LABELS;
  readonly width = 600;
  readonly height = 140;
  readonly window = 60;

  readonly money = formatMoney;

  /** The largest either night reached, so both lines share one scale. */
  private readonly peak = computed(() => {
    const pace = this.insights().pace;
    const all = [...pace.this, ...(pace.previous ?? [])].map((p) => p.tickets);

    return Math.max(1, ...all);
  });

  readonly lines = computed(() => ({
    current: this.line(this.insights().pace.this),
    previous: this.insights().pace.previous ? this.line(this.insights().pace.previous!) : null,
  }));

  /** Where last time stood at the same number of days to go as today. */
  readonly byNowLastTime = computed(() => {
    const { pace, summary } = this.insights();
    const today = pace.this.at(-1);

    // Once this night has happened the fair comparison is the whole of the
    // last one, not a point partway along it.
    if (summary.started || !today || !pace.previous) return null;

    return pace.previous.find((p) => p.days_before === today.days_before) ?? null;
  });

  /** Tickets now against the same point last time — or against its total, once this one has happened. */
  readonly versus = computed(() => {
    const previous = this.insights().previous;
    if (!previous) return null;

    const then = this.byNowLastTime()?.tickets ?? previous.summary.tickets;
    const now = this.insights().summary.tickets;
    if (then === 0) return now > 0 ? `+${now}` : '0';

    const change = Math.round(((now - then) / then) * 100);

    return `${change > 0 ? '+' : ''}${change}%`;
  });

  readonly paceLabel = computed(() => {
    const today = this.insights().pace.this.at(-1);
    const then = this.byNowLastTime();

    return today
      ? `Tickets sold by days before the night: ${today.tickets} with ${today.days_before} days to go` + (then ? `, against ${then.tickets} last time` : '')
      : 'Tickets sold by days before the night';
  });

  percent(rate: number | null): string {
    if (rate === null) return '—';

    return `${(rate * 100).toFixed(rate < 0.1 ? 1 : 0)}%`;
  }

  perThousand(rate: number): string {
    return Math.round(rate * 1000).toLocaleString();
  }

  share(tickets: number): number {
    const total = this.insights().sources.reduce((sum, row) => sum + row.tickets, 0);

    return total === 0 ? 0 : Math.round((tickets / total) * 100);
  }

  comparison(now: InsightSummary, then: InsightSummary): { label: string; now: string; then: string }[] {
    return [
      { label: 'Tickets', now: now.tickets.toLocaleString(), then: then.tickets.toLocaleString() },
      { label: 'Earned', now: this.money(now.revenue), then: this.money(then.revenue) },
      { label: 'Looked', now: (now.views + now.embed_views).toLocaleString(), then: (then.views + then.embed_views).toLocaleString() },
      { label: 'Bought, of those who looked', now: this.percent(now.conversion), then: this.percent(then.conversion) },
      { label: 'Came', now: now.attendance !== null ? this.percent(now.attendance) : '—', then: then.attendance !== null ? this.percent(then.attendance) : '—' },
    ];
  }

  private line(points: { days_before: number; tickets: number }[]): Line | null {
    if (points.length === 0) return null;

    const xy = points.map((p) => ({
      x: ((this.window - p.days_before) / this.window) * this.width,
      y: this.height - (p.tickets / this.peak()) * (this.height - 6),
    }));

    return { points: xy.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' '), last: xy.at(-1) ?? null };
  }
}
