import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import type { EventSummary, InsightSource, OrganizerEventDetail, SalesReport } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { formatMoney } from '../../core/money';
import { messageOf } from '../../core/errors';
import { MfButton, MfCard, MfEmpty, MfScreen, MfSegmented, MfSkeleton, MfSpark, MfStat, type MfSegment } from '../../ui';
import { EventContext } from './event-context';

const SOURCES: Record<InsightSource, { label: string; hint: string }> = {
  direct: { label: 'Found it themselves', hint: 'Search, a shared link, your socials' },
  link: { label: 'Promoter links', hint: 'Links and codes you gave out' },
  campaign: { label: 'Your emails', hint: 'Campaigns sent from here' },
  embed: { label: 'Your website', hint: 'The tickets on your own site' },
  door: { label: 'At the door', hint: 'Sold on the night' },
};

/**
 * What the ledger lines do not account for: money that reached the organizer
 * another way — at the door, in a payout already sent — or went back in a
 * dispute. Negative when it has already left what is owed. Shown as one line
 * so the column adds up to the figure at the bottom.
 */
export function unaccounted(s: EventSummary): number {
  return s.net.amount - (s.gross.amount - s.discounts.amount - s.tax.amount - s.refunds.amount);
}

/**
 * How the night is doing: the money first, then what sold, then where the
 * buyers came from.
 *
 * Every rate is said as what it is a rate of. "3.2%" is a number people argue
 * about; "32 of every 1,000 who looked bought" is one they act on.
 */
@Component({
  selector: 'mf-event-sales',
  imports: [MfScreen, MfSegmented, MfCard, MfStat, MfSpark, MfButton, MfEmpty, MfSkeleton],
  template: `
    <mf-screen title="Sales" [subtitle]="event()?.title ?? null" back [backTo]="'/manage/events/' + id()" refreshable [busy]="loading()" (refresh)="load()">
      <mf-segmented screenBar ariaLabel="Which part" [segments]="views" [(value)]="view" />

      @if (error(); as message) {
        <mf-empty title="Could not load the sales" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (report(); as r) {
        @switch (view()) {
          @case ('money') {
            @if (summary(); as s) {
              <mf-card class="net">
                <p class="eyebrow">Owed to you</p>
                <p class="big figure">{{ cash(s.net) }}</p>
                <p class="muted">{{ s.orders }} {{ s.orders === 1 ? 'order' : 'orders' }} · {{ s.tickets_issued }} {{ s.tickets_issued === 1 ? 'ticket' : 'tickets' }}</p>
              </mf-card>
              <dl class="ledger">
                <div><dt>Sold</dt><dd>{{ cash(s.gross) }}</dd></div>
                @if (s.discounts.amount > 0) {
                  <div><dt>Codes took off</dt><dd>− {{ cash(s.discounts) }}</dd></div>
                }
                @if (s.tax.amount > 0) {
                  <div><dt>Sales tax<small>Collected with the price, and passed on</small></dt><dd>− {{ cash(s.tax) }}</dd></div>
                }
                @if (s.refunds.amount > 0) {
                  <div><dt>Refunded</dt><dd>− {{ cash(s.refunds) }}</dd></div>
                }
                @if (settled(s); as other) {
                  <div><dt>Already with you<small>Door takings, payouts sent, disputes</small></dt><dd>{{ other }}</dd></div>
                }
                <div class="total"><dt>Owed to you</dt><dd>{{ cash(s.net) }}</dd></div>
                @if (s.service_charge.amount > 0) {
                  <div class="aside"><dt>Buyers also paid the service fee</dt><dd>{{ cash(s.service_charge) }}</dd></div>
                }
              </dl>
            }

            @if (days().length > 1) {
              <mf-card class="block">
                <p class="eyebrow">Sales by day</p>
                <mf-spark [values]="days()" label="Revenue by day" />
                <p class="muted small">From {{ r.days[0].date }} to {{ r.days[r.days.length - 1].date }}</p>
              </mf-card>
            }

            @if (r.door.tickets > 0) {
              <mf-card class="block">
                <p class="eyebrow">At the door</p>
                <p class="figure mid">{{ cash(r.door.total) }}</p>
                <p class="muted small">{{ r.door.tickets }} {{ r.door.tickets === 1 ? 'ticket' : 'tickets' }} · in your own hands, so not paid out</p>
              </mf-card>
            }
          }

          @case ('tickets') {
            <ul class="rows">
              @for (t of r.ticket_types; track t.id) {
                <li>
                  <div class="row-top">
                    <span class="name">{{ t.name }}</span>
                    <span class="figure">{{ cash(t.revenue) }}</span>
                  </div>
                  @if (t.capacity) {
                    <span class="bar"><span [style.width.%]="Math.min(100, (t.sold / t.capacity) * 100)"></span></span>
                  }
                  <p class="muted small">
                    {{ t.sold }}@if (t.capacity) { of {{ t.capacity }} } sold
                    @if (t.comps > 0) { · {{ t.comps }} free }
                    · {{ t.arrived }} of {{ t.people }} in
                  </p>
                </li>
              }
            </ul>

            @if (r.add_ons.length > 0) {
              <h2 class="heading">Extras</h2>
              <ul class="rows">
                @for (a of r.add_ons; track a.id) {
                  <li>
                    <div class="row-top">
                      <span class="name">{{ a.name }}</span>
                      <span class="figure">{{ cash(a.revenue) }}</span>
                    </div>
                    <p class="muted small">{{ a.sold }}@if (a.capacity) { of {{ a.capacity }} } sold</p>
                  </li>
                }
              </ul>
            }

            @if (r.codes.length > 0) {
              <h2 class="heading">Codes and promoters</h2>
              <ul class="rows">
                @for (c of r.codes; track c.code_id ?? c.code) {
                  <li>
                    <div class="row-top">
                      <span class="name mono">{{ c.code ?? 'Removed code' }}</span>
                      <span class="figure">{{ cash(c.revenue) }}</span>
                    </div>
                    <p class="muted small">
                      {{ c.orders }} {{ c.orders === 1 ? 'order' : 'orders' }} · {{ c.tickets }} {{ c.tickets === 1 ? 'ticket' : 'tickets' }}
                      @if (c.discount.amount > 0) { · {{ cash(c.discount) }} off }
                      @if (c.promoter) { · {{ c.promoter }} }
                    </p>
                  </li>
                }
              </ul>
            }
          }

          @case ('buyers') {
            @if (insights(); as i) {
              <div class="stats">
                <mf-stat lead label="Looked" [value]="i.summary.views.toLocaleString()" [hint]="i.summary.embed_views ? i.summary.embed_views + ' on your website' : 'Event page views'" />
                <mf-stat label="Bought" [value]="i.summary.orders.toLocaleString()" [hint]="conversion()" />
                <mf-stat
                  label="Came"
                  [value]="i.summary.started ? i.summary.arrived.toLocaleString() : '—'"
                  [hint]="i.summary.started ? 'of ' + i.summary.people : 'Once the doors open'"
                  [portion]="i.summary.attendance"
                />
              </div>

              @if (pace(); as p) {
                <mf-card class="block">
                  <p class="eyebrow">Against {{ i.previous?.title }}</p>
                  <p>
                    <strong class="figure mid">{{ p.now }}</strong> tickets {{ p.days === 0 ? 'on the day' : p.days + ' days out' }},
                    against <strong>{{ p.then }}</strong> last time.
                  </p>
                  <p class="muted small">{{ p.now >= p.then ? 'Ahead of' : 'Behind' }} it by {{ Math.abs(p.now - p.then) }}.</p>
                </mf-card>
              }

              @if (i.sources.length > 0) {
                <h2 class="heading">Where buyers came from</h2>
                <ul class="rows">
                  @for (s of i.sources; track s.source) {
                    <li>
                      <div class="row-top">
                        <span class="name">{{ sourceLabel(s.source) }}</span>
                        <span class="figure">{{ s.orders }}</span>
                      </div>
                      <span class="bar"><span [style.width.%]="share(s.orders)"></span></span>
                      <p class="muted small">{{ sourceHint(s.source) }} · {{ cash(s.revenue) }}</p>
                    </li>
                  }
                </ul>
              }
            }
          }
        }
      } @else {
        <mf-card><mf-skeleton height="8rem" /></mf-card>
      }
    </mf-screen>
  `,
  styles: `
    .net {
      display: grid;
      gap: var(--space-1);
    }

    .eyebrow {
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-semibold);
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--text-subtle);
    }

    .big {
      font-size: var(--font-size-4xl);
      line-height: 1.05;
    }

    .mid {
      font-size: var(--font-size-2xl);
    }

    .muted {
      color: var(--text-muted);
    }

    .small {
      font-size: var(--font-size-sm);
    }

    .ledger {
      display: grid;
      margin: var(--space-4) 0;
      padding: var(--space-2) var(--space-4);
      border-radius: var(--radius-xl);
      background: var(--surface-raised);
      box-shadow: var(--shadow-sm);
    }

    .ledger div {
      display: flex;
      justify-content: space-between;
      gap: var(--space-3);
      padding: var(--space-3) 0;
      font-variant-numeric: tabular-nums;
    }

    .ledger div + div {
      border-top: 1px solid var(--border-subtle);
    }

    .ledger dt {
      color: var(--text-muted);
    }

    .ledger small {
      display: block;
      font-size: var(--font-size-xs);
      color: var(--text-subtle);
    }

    .ledger dd {
      margin: 0;
      font-weight: var(--font-weight-medium);
    }

    .ledger .total dt,
    .ledger .total dd {
      color: var(--text);
      font-weight: var(--font-weight-bold);
    }

    .ledger .aside {
      font-size: var(--font-size-sm);
    }

    .block {
      display: grid;
      gap: var(--space-2);
      margin-bottom: var(--space-4);
    }

    .heading {
      margin: var(--space-6) 0 var(--space-3);
      font-size: var(--font-size-lg);
    }

    .rows {
      display: grid;
      margin: 0;
      padding: var(--space-1) var(--space-4);
      list-style: none;
      border-radius: var(--radius-xl);
      background: var(--surface-raised);
      box-shadow: var(--shadow-sm);
    }

    .rows li {
      display: grid;
      gap: var(--space-2);
      padding: var(--space-3) 0;
    }

    .rows li + li {
      border-top: 1px solid var(--border-subtle);
    }

    .row-top {
      display: flex;
      justify-content: space-between;
      gap: var(--space-3);
    }

    .name {
      font-weight: var(--font-weight-medium);
    }

    .mono {
      font-family: var(--font-family-mono);
    }

    .bar {
      display: block;
      height: 6px;
      border-radius: var(--radius-full);
      background: var(--surface-inset);
      overflow: hidden;
    }

    .bar span {
      display: block;
      height: 100%;
      border-radius: inherit;
      background: var(--primary);
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: var(--space-3);
      margin-bottom: var(--space-4);
    }

    .stats mf-stat:first-child {
      grid-column: 1 / -1;
    }
  `,
})
export class EventSales implements OnInit {
  readonly id = input.required<string>();

  private readonly organizer = inject(Organizer);
  private readonly context = inject(EventContext);

  protected readonly Math = Math;
  protected readonly cash = formatMoney;

  protected readonly view = signal<'money' | 'tickets' | 'buyers'>('money');
  protected readonly views: MfSegment[] = [
    { value: 'money', label: 'Money' },
    { value: 'tickets', label: 'What sold' },
    { value: 'buyers', label: 'Buyers' },
  ];

  protected readonly event = signal<OrganizerEventDetail | null>(null);
  protected readonly report = signal<SalesReport | null>(null);
  protected readonly summary = signal<EventSummary | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly insights = computed(() => this.report()?.insights ?? null);
  protected readonly days = computed(() => (this.report()?.days ?? []).map((d) => d.revenue));

  /** "32 of every 1,000 who looked bought" — the rate, said as what it is. */
  protected readonly conversion = computed(() => {
    const c = this.insights()?.summary.conversion;
    if (c === null || c === undefined) return 'Nothing counted yet';

    const perThousand = Math.round(c * 1000);
    return perThousand >= 10 ? `${Math.round(c * 100)} of every 100 who looked` : `${perThousand} of every 1,000 who looked`;
  });

  /** Where this night stands against the last one, at the same distance from the doors. */
  protected readonly pace = computed(() => {
    const p = this.insights()?.pace;
    if (!p?.previous?.length || !p.this.length) return null;

    const latest = p.this.reduce((a, b) => (b.days_before < a.days_before ? b : a));
    const then = p.previous.filter((x) => x.days_before >= latest.days_before).reduce<{ days_before: number; tickets: number } | null>(
      (best, x) => (!best || x.days_before < best.days_before ? x : best),
      null,
    );

    return then ? { days: latest.days_before, now: latest.tickets, then: then.tickets } : null;
  });

  ngOnInit(): void {
    this.event.set(this.context.peek(this.id()));
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      const [event, report, summary] = await Promise.all([
        this.context.get(this.id()),
        this.organizer.sales(this.id()),
        this.organizer.summary(this.id()),
      ]);
      this.event.set(event);
      this.report.set(report);
      this.summary.set(summary);
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected settled(s: EventSummary): string | null {
    const rest = unaccounted(s);
    if (rest === 0) return null;

    const amount = formatMoney({ amount: Math.abs(rest), currency: s.currency });
    return rest < 0 ? `− ${amount}` : amount;
  }

  protected sourceLabel(source: InsightSource): string {
    return SOURCES[source]?.label ?? source;
  }

  protected sourceHint(source: InsightSource): string {
    return SOURCES[source]?.hint ?? '';
  }

  protected share(orders: number): number {
    const total = (this.insights()?.sources ?? []).reduce((sum, s) => sum + s.orders, 0);
    return total ? (orders / total) * 100 : 0;
  }
}
