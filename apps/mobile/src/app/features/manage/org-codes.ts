import { Component, OnInit, computed, inject, signal, viewChild } from '@angular/core';
import { Router } from '@angular/router';
import { Plus } from 'lucide-angular';
import type { EventOption, OrganizationCode, PageMeta } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { formatMoney } from '../../core/money';
import { messageOf } from '../../core/errors';
import { shortEventTime } from '../../core/event-time';
import { MfBadge, MfButton, MfCard, MfEmpty, MfIconButton, MfScreen, MfSearch, MfSelect, MfSkeleton, type MfOption } from '../../ui';

/** The filter value for codes made for every event rather than one. */
const ALL_EVENTS = 'all-events';

/**
 * Every discount, promoter and presale code, across the organization's events.
 *
 * A place to find a code, not a second place to change one: a code belongs to
 * an event — its tickets, its currency, its links — so tapping it opens that
 * event's codes, and a new one starts by choosing the event.
 */
@Component({
  selector: 'mf-org-codes',
  imports: [MfScreen, MfSearch, MfSelect, MfIconButton, MfCard, MfBadge, MfButton, MfEmpty, MfSkeleton],
  template: `
    <mf-screen title="Discount codes" back backTo="/manage" refreshable [busy]="loading()" (refresh)="reload()">
      <button mfIconButton screenActions tone="tonal" [icon]="plusIcon" label="New code" (click)="make()"></button>
      <mf-search screenBar placeholder="Code, label or promoter" [(value)]="query" (searched)="reload()" />

      <mf-select #picker bare heading="Which event is it for?" subheading="Fixed amounts come off in that event’s currency." [options]="eventChoices()" (valueChange)="makeFor($event)" />
      <mf-select class="filter" heading="Which codes" placeholder="All codes" [options]="filterOptions()" [value]="eventFilter()" (valueChange)="eventFilter.set($event ?? ''); reload()" />

      @if (error(); as message) {
        <mf-empty title="Could not load the codes" [hint]="message">
          <button mfButton variant="secondary" (click)="reload()">Try again</button>
        </mf-empty>
      } @else if (codes().length === 0 && loading()) {
        <mf-card quiet><mf-skeleton height="4rem" /></mf-card>
      } @else if (codes().length === 0) {
        <mf-empty [title]="query() || eventFilter() ? 'Nothing matches' : 'No codes yet'" hint="Money off, a promoter's link, or early access to a tier.">
          <button mfButton (click)="make()">Make a code</button>
        </mf-empty>
      } @else {
        @if (meta(); as m) {
          <p class="count">{{ m.total }} {{ m.total === 1 ? 'code' : 'codes' }}</p>
        }
        <ul class="items">
          @for (code of codes(); track code.id) {
            <li>
              <mf-card [tappable]="!!code.event" (click)="open(code)">
                <div class="top">
                  <div class="name">
                    <h3 class="code">{{ code.code }}</h3>
                    <p class="what">{{ describe(code) }}</p>
                  </div>
                  <mf-badge [tone]="status(code).tone">{{ status(code).label }}</mf-badge>
                </div>
                <p class="sub">
                  @if (code.event) {
                    {{ code.event.title }} · {{ eventDate(code) }}
                  } @else {
                    Made for every event — change it on the web
                  }
                </p>
                <p class="sub"><strong>{{ uses(code) }}</strong> used · {{ ticketsSold(code) }} tickets sold</p>
              </mf-card>
            </li>
          }
        </ul>

        @if (hasMore()) {
          <button mfButton class="more" variant="secondary" block [loading]="loading()" (click)="more()">Show more</button>
        }
      }
    </mf-screen>
  `,
  styles: `
    .filter {
      display: block;
      margin-bottom: var(--space-4);
    }

    .count {
      margin: 0 var(--space-1) var(--space-3);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .items {
      display: grid;
      gap: var(--space-3);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .top {
      display: flex;
      align-items: flex-start;
      gap: var(--space-2);
    }

    .name {
      flex: 1;
      min-width: 0;
    }

    h3 {
      font-size: var(--font-size-lg);
    }

    .code {
      font-family: var(--font-family-mono);
      letter-spacing: 0.04em;
    }

    .what {
      color: var(--primary-text);
      font-weight: var(--font-weight-semibold);
      font-size: var(--font-size-sm);
    }

    .sub {
      margin-top: var(--space-1);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .sub strong {
      color: var(--text);
    }

    .more {
      margin-top: var(--space-4);
    }
  `,
})
export class OrgCodes implements OnInit {
  private readonly organizer = inject(Organizer);
  private readonly router = inject(Router);

  protected readonly query = signal('');
  protected readonly eventFilter = signal('');
  protected readonly events = signal<EventOption[]>([]);
  protected readonly codes = signal<OrganizationCode[]>([]);
  protected readonly meta = signal<PageMeta | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly plusIcon = Plus;

  private readonly picker = viewChild.required<MfSelect>('picker');

  protected readonly eventChoices = computed<MfOption[]>(() => this.events().map((e) => ({ value: e.id, label: e.title, hint: this.day(e.starts_at) })));

  protected readonly filterOptions = computed<MfOption[]>(() => [
    { value: '', label: 'All codes' },
    { value: ALL_EVENTS, label: 'Made for every event' },
    ...this.eventChoices(),
  ]);

  ngOnInit(): void {
    void this.organizer.eventOptions().then((e) => this.events.set(e)).catch(() => undefined);
    void this.reload();
  }

  async reload(): Promise<void> {
    this.codes.set([]);
    await this.fetch(1);
  }

  protected hasMore(): boolean {
    const m = this.meta();
    return !!m && m.current_page < m.last_page;
  }

  protected more(): void {
    void this.fetch((this.meta()?.current_page ?? 1) + 1);
  }

  private async fetch(page: number): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      const result = await this.organizer.codes({ page, q: this.query().trim() || undefined, event_id: this.eventFilter() || null });
      this.codes.update((rows) => (page === 1 ? result.data : [...rows, ...result.data]));
      this.meta.set(result.meta);
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  private day(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  }

  protected describe(code: OrganizationCode): string {
    if (code.discount_type === 'percentage' && code.discount_value !== null) return `${Number((code.discount_value / 100).toFixed(2))}% off`;
    if (code.discount_type === 'fixed' && code.discount_value !== null && code.discount_currency) {
      return `${formatMoney({ amount: code.discount_value, currency: code.discount_currency })} off`;
    }

    return code.ref_slug ? `Tracks ${code.promoter_name ?? 'a promoter'}` : 'Presale access';
  }

  /** One word for where the code stands, with a tone to scan by. */
  protected status(code: OrganizationCode): { label: string; tone: 'success' | 'warning' | 'neutral' } {
    if (!code.is_active) return { label: 'Off', tone: 'neutral' };
    if (code.max_redemptions !== null && code.redemption_count >= code.max_redemptions) return { label: 'Used up', tone: 'neutral' };
    if (code.ends_at && new Date(code.ends_at).getTime() < Date.now()) return { label: 'Ended', tone: 'neutral' };
    if (code.starts_at && new Date(code.starts_at).getTime() > Date.now()) return { label: 'Scheduled', tone: 'warning' };

    return { label: 'Working', tone: 'success' };
  }

  protected uses(code: OrganizationCode): string {
    return code.max_redemptions === null ? `${code.redemption_count}` : `${code.redemption_count} of ${code.max_redemptions}`;
  }

  protected ticketsSold(code: OrganizationCode): number {
    return code.sales.reduce((total, sale) => total + sale.tickets, 0);
  }

  protected eventDate(code: OrganizationCode): string {
    return code.event ? shortEventTime(code.event.starts_at, code.event.timezone) : '';
  }

  protected open(code: OrganizationCode): void {
    if (code.event) void this.router.navigate(['/manage/events', code.event.id, 'codes']);
  }

  /** A code belongs to an event, so a new one starts by choosing it. */
  protected make(): void {
    if (this.events().length === 0) {
      void this.router.navigate(['/manage/events/new']);
      return;
    }

    this.picker().show();
  }

  protected makeFor(eventId: string | null): void {
    if (eventId) void this.router.navigate(['/manage/events', eventId, 'codes'], { queryParams: { make: 'code' } });
  }
}
