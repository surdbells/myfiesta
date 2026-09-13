import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Subject, debounceTime } from 'rxjs';
import { ToastStore, UiBadge, UiButton, UiEmpty, UiErrorState, UiIcon, UiPageHeader, UiPagination, UiSelect, UiSkeleton, type SelectOption } from '@myfiesta/ui';
import { Plus, Search, X } from 'lucide-angular';
import { Api } from '../../core/api';
import { EventOption, OrganizationCode, PageMeta } from '../../core/api.types';
import { shortEventTime } from '../../core/event-time';
import { formatMoney } from '../../core/money';
import { SessionStore } from '../../core/session';
import { EventCodes } from '../events/event-codes';

/** The filter value for codes made for every event rather than one. */
const ALL_EVENTS = 'all-events';

/**
 * Every discount and promoter code, across the organization's events.
 *
 * Codes were only reachable inside each event, so there was no one place to
 * see what is out there — and making a code meant finding the event first.
 * This lists them all, and makes new ones by asking which event it is for
 * and then using the same form as the event's own Codes tab, so the two
 * cannot drift apart.
 *
 * Changing a code still happens on its event's tab, where the tickets it can
 * cover and the links it makes are in front of you.
 */
@Component({
  selector: 'app-codes',
  imports: [FormsModule, RouterLink, UiPageHeader, UiButton, UiBadge, UiSelect, UiIcon, UiEmpty, UiErrorState, UiSkeleton, UiPagination, EventCodes],
  templateUrl: './codes.html',
})
export class Codes {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  readonly session = inject(SessionStore);

  protected readonly searchIcon = Search;
  protected readonly plusIcon = Plus;
  protected readonly closeIcon = X;

  readonly codes = signal<OrganizationCode[]>([]);
  readonly meta = signal<PageMeta | null>(null);
  readonly loading = signal(true);
  readonly failed = signal(false);

  readonly events = signal<EventOption[]>([]);

  readonly query = signal('');
  readonly eventFilter = signal<string | null>(null);
  readonly page = signal(1);

  /** Making a code: which event it is for, then the form. */
  readonly creating = signal(false);
  readonly createFor = signal<string | null>(null);

  readonly filtered = computed(() => this.query().trim() !== '' || this.eventFilter() !== null);

  readonly filterOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'All codes' },
    { value: ALL_EVENTS, label: 'Made for every event' },
    ...this.eventChoices(),
  ]);

  readonly eventChoices = computed<SelectOption[]>(() =>
    this.events().map((event) => ({
      value: event.id,
      label: event.title,
      hint: new Intl.DateTimeFormat('en-CA', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(event.starts_at)),
    })),
  );

  readonly createForTitle = computed(() => this.events().find((e) => e.id === this.createFor())?.title ?? null);

  private readonly typing = new Subject<void>();

  constructor() {
    this.api.eventOptions().subscribe({
      next: ({ data }) => this.events.set(data),
      error: () => undefined,
    });

    this.typing.pipe(debounceTime(250)).subscribe(() => {
      this.page.set(1);
      this.load();
    });

    this.load();
  }

  load(): void {
    this.loading.set(true);

    this.api
      .organizationCodes({ page: this.page(), eventId: this.eventFilter(), q: this.query() })
      .subscribe({
        next: ({ data, meta }) => {
          this.codes.set(data);
          this.meta.set(meta);
          this.loading.set(false);
          this.failed.set(false);
        },
        error: () => {
          this.loading.set(false);
          this.failed.set(true);
        },
      });
  }

  search(value: string): void {
    this.query.set(value);
    this.typing.next();
  }

  filterByEvent(value: string | null): void {
    this.eventFilter.set(value || null);
    this.page.set(1);
    this.load();
  }

  clear(): void {
    this.query.set('');
    this.eventFilter.set(null);
    this.page.set(1);
    this.load();
  }

  goToPage(page: number): void {
    this.page.set(page);
    this.load();
  }

  startCreating(): void {
    this.creating.set(true);
    // Straight to the form when the list is already narrowed to one event.
    const filter = this.eventFilter();
    this.createFor.set(filter && filter !== ALL_EVENTS ? filter : null);
  }

  stopCreating(): void {
    this.creating.set(false);
    this.createFor.set(null);
  }

  onCreated(): void {
    this.toasts.show(`Code created for ${this.createForTitle() ?? 'the event'}.`, 'success');
    this.stopCreating();
    this.page.set(1);
    this.load();
  }

  describeDiscount(code: OrganizationCode): string {
    if (code.discount_type === 'percentage' && code.discount_value !== null) {
      return `${Number((code.discount_value / 100).toFixed(2))}% off`;
    }

    if (code.discount_type === 'fixed' && code.discount_value !== null && code.discount_currency) {
      return `${formatMoney({ amount: code.discount_value, currency: code.discount_currency })} off`;
    }

    return code.ref_slug ? 'Tracking only' : 'Presale access';
  }

  /** One word for where the code stands, with a tone to scan by. */
  status(code: OrganizationCode): { label: string; tone: 'success' | 'warning' | 'neutral' } {
    if (!code.is_active) return { label: 'Off', tone: 'neutral' };
    if (code.max_redemptions !== null && code.redemption_count >= code.max_redemptions) return { label: 'Used up', tone: 'neutral' };
    if (code.ends_at && new Date(code.ends_at).getTime() < Date.now()) return { label: 'Ended', tone: 'neutral' };
    if (code.starts_at && new Date(code.starts_at).getTime() > Date.now()) return { label: 'Scheduled', tone: 'warning' };

    return { label: 'Active', tone: 'success' };
  }

  uses(code: OrganizationCode): string {
    return code.max_redemptions === null ? `${code.redemption_count}` : `${code.redemption_count} of ${code.max_redemptions}`;
  }

  ticketsSold(code: OrganizationCode): number {
    return code.sales.reduce((total, sale) => total + sale.tickets, 0);
  }

  eventDate(code: OrganizationCode): string {
    return code.event ? shortEventTime(code.event.starts_at, code.event.timezone) : '';
  }
}
