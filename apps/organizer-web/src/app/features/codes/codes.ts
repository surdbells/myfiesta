import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import {
  ConfirmDialog,
  Selection,
  ToastStore,
  UiBadge,
  UiBulkBar,
  UiButton,
  UiColumnMenu,
  UiEmpty,
  UiErrorState,
  UiFilterBar,
  UiIcon,
  UiPageHeader,
  UiPagination,
  UiScrollRegion,
  UiSelect,
  UiSortHeader,
  UiTable,
  createListState,
  type FilterChip,
  type SelectOption,
} from '@myfiesta/ui';
import { Pause, Play, Plus, X } from 'lucide-angular';
import { Api } from '../../core/api';
import { EventOption, OrganizationCode } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { shortEventTime } from '../../core/event-time';
import { loadList, searchBox } from '../../core/list-loader';
import { formatMoney } from '../../core/money';
import { SessionStore } from '../../core/session';
import { SavedViews } from '../../shared/saved-views';
import { EventCodes } from '../events/event-codes';

/** The filter value for codes made for every event rather than one. */
const ALL_EVENTS = 'all-events';

/** Where a code stands, as the list and the server both name it. */
const STATES: SelectOption[] = [
  { value: 'usable', label: 'Active' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'paused', label: 'Off' },
  { value: 'used_up', label: 'Used up' },
  { value: 'expired', label: 'Ended' },
];

const KINDS: SelectOption[] = [
  { value: 'discount', label: 'Money off' },
  { value: 'promoter', label: 'Promoter link' },
  { value: 'presale', label: 'Presale access' },
];

/**
 * Every discount, promoter and presale code, across the organization's events.
 *
 * Codes were only reachable inside each event, so there was no one place to
 * see what is out there — and making a code meant finding the event first.
 * This lists them all, filtered by where they stand, what they do and which
 * events they are for; makes new ones by asking which event it is for and
 * then using the same form as the event's own Codes tab, so the two cannot
 * drift apart; and turns several off or back on at once — a promoter leaving
 * takes their dozen codes with them.
 *
 * Changing one code still happens on its event's tab, where the tickets it can
 * cover and the links it makes are in front of you.
 */
@Component({
  selector: 'app-codes',
  imports: [
    FormsModule,
    RouterLink,
    EventCodes,
    SavedViews,
    UiBadge,
    UiBulkBar,
    UiButton,
    UiColumnMenu,
    UiEmpty,
    UiErrorState,
    UiFilterBar,
    UiIcon,
    UiPageHeader,
    UiPagination,
    UiScrollRegion,
    UiSelect,
    UiSortHeader,
    UiTable,
  ],
  templateUrl: './codes.html',
})
export class Codes {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  private readonly confirmDialog = inject(ConfirmDialog);
  readonly session = inject(SessionStore);

  protected readonly plusIcon = Plus;
  protected readonly closeIcon = X;
  protected readonly pauseIcon = Pause;
  protected readonly resumeIcon = Play;

  readonly stateOptions = STATES;
  readonly kindOptions = KINDS;

  readonly list = createListState({
    list: 'codes',
    filters: {
      q: { kind: 'text' },
      event_id: { kind: 'many' },
      state: { kind: 'many' },
      kind: { kind: 'many' },
    },
    sort: { column: 'created', direction: 'desc' },
    columns: [
      { id: 'code', label: 'Code', required: true },
      { id: 'event', label: 'Event' },
      { id: 'does', label: 'Does' },
      { id: 'window', label: 'Runs', hidden: true },
      { id: 'uses', label: 'Uses' },
      { id: 'sold', label: 'Tickets sold' },
      { id: 'status', label: 'Status' },
    ],
  });

  readonly search = searchBox(this.list, 'q');
  readonly selection = new Selection();

  readonly page = loadList(this.list.query, () => this.api.organizationCodes(this.list.query()));

  readonly codes = computed<OrganizationCode[]>(() => this.page.result()?.data ?? []);
  readonly meta = computed(() => this.page.result()?.meta ?? null);
  readonly loading = computed(() => this.page.loading() && this.page.result() === null);
  readonly refreshing = computed(() => this.page.loading() && this.page.result() !== null);
  readonly failed = this.page.failed;
  readonly rowIds = computed(() => this.codes().map((code) => code.id));
  readonly changing = signal(false);

  readonly events = signal<EventOption[]>([]);

  /** Making a code: which event it is for, then the form. */
  readonly creating = signal(false);
  readonly createFor = signal<string | null>(null);

  readonly filtered = computed(() => this.list.active() > 0);

  readonly eventChoices = computed<SelectOption[]>(() =>
    this.events().map((event) => ({
      value: event.id,
      label: event.title,
      hint: new Intl.DateTimeFormat('en-CA', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(event.starts_at)),
    })),
  );

  readonly filterOptions = computed<SelectOption[]>(() => [{ value: ALL_EVENTS, label: 'Made for every event' }, ...this.eventChoices()]);

  /** What is on, in the words it was chosen by. */
  readonly chips = computed<FilterChip[]>(() => {
    const chips: FilterChip[] = [];
    const values = this.list.values();

    if (values.q) chips.push({ key: 'q', label: 'Search', value: String(values.q) });

    const events = values.event_id as readonly string[];
    if (events.length > 0) {
      const names = events.map((id) => (id === ALL_EVENTS ? 'Every event' : (this.events().find((e) => e.id === id)?.title ?? 'One event')));
      chips.push({ key: 'event_id', label: events.length === 1 ? 'Event' : 'Events', value: listed(names) });
    }

    const states = values.state as readonly string[];
    if (states.length > 0) chips.push({ key: 'state', label: 'Status', value: listed(states.map((s) => labelOf(STATES, s))) });

    const kinds = values.kind as readonly string[];
    if (kinds.length > 0) chips.push({ key: 'kind', label: 'Does', value: listed(kinds.map((k) => labelOf(KINDS, k))) });

    return chips;
  });

  readonly summary = computed(() => {
    const meta = this.meta();
    if (!meta) return null;

    const noun = meta.total === 1 ? 'code' : 'codes';
    const shown = Math.min(meta.per_page, this.codes().length);

    return meta.total > shown ? `Showing ${shown} of ${meta.total.toLocaleString()} ${noun}` : `${meta.total.toLocaleString()} ${noun}`;
  });

  readonly createForTitle = computed(() => this.events().find((e) => e.id === this.createFor())?.title ?? null);

  constructor() {
    this.api.eventOptions().subscribe({
      next: ({ data }) => this.events.set(data),
      error: () => undefined,
    });

    // A different set of rows makes the ticks meaningless.
    effect(() => {
      this.list.criteria();
      untracked(() => this.selection.clear());
    });

    effect(() => {
      const ids = this.rowIds();
      untracked(() => this.selection.keep(ids));
    });
  }

  load(): void {
    this.page.retry();
  }

  remove(key: string): void {
    this.list.clear(key as 'q');
  }

  startCreating(): void {
    this.creating.set(true);
    // Straight to the form when the list is already narrowed to one event.
    const events = this.list.get('event_id') as readonly string[];
    this.createFor.set(events.length === 1 && events[0] !== ALL_EVENTS ? events[0] : null);
  }

  stopCreating(): void {
    this.creating.set(false);
    this.createFor.set(null);
  }

  onCreated(): void {
    this.toasts.show(`Code created for ${this.createForTitle() ?? 'the event'}.`, 'success');
    this.stopCreating();
    this.list.goTo(1);
    this.page.retry();
  }

  /** The ticked codes on this page. */
  readonly ticked = computed(() => this.codes().filter((code) => this.selection.has(code.id)));

  /** Whether Turn off, and Turn on, would change any of them. */
  readonly anyOn = computed(() => this.ticked().some((code) => code.is_active));
  readonly anyOff = computed(() => this.ticked().some((code) => !code.is_active));

  /**
   * Several codes off, or back on, after saying which and what it does.
   *
   * Only the codes it would change are counted, named and sent: "Turn 2 codes
   * back on?" over two codes already on was a question read and wrong. Those
   * already that way are said to stay as they are.
   */
  async setActive(active: boolean): Promise<void> {
    const chosen = this.ticked();
    const changing = chosen.filter((code) => code.is_active !== active);
    if (changing.length === 0 || this.changing()) return;

    const names = changing.map((code) => code.code);
    const one = changing.length === 1;
    const some = one ? names[0] : `${changing.length} codes`;
    const them = one ? 'it' : 'them';
    const already = chosen.length - changing.length;

    const sure = await this.confirmDialog.confirm({
      title: active ? `Turn ${some} back on?` : `Turn ${some} off?`,
      body: active
        ? `Buyers can use ${them} again straight away, within ${one ? 'its' : 'their'} own dates and limits.`
        : `Buyers who try ${them} at checkout are told the code is not valid. Nothing already sold changes.`,
      consequences: [
        ...(one ? [] : [listed(names, 6)]),
        ...(already > 0
          ? [`${already === 1 ? 'The other one ticked is' : `The other ${already} ticked are`} already ${active ? 'on' : 'off'}, and ${already === 1 ? 'stays' : 'stay'} as ${already === 1 ? 'it is' : 'they are'}.`]
          : []),
      ],
      confirmLabel: active ? `Turn ${them} on` : `Turn ${them} off`,
      tone: active ? 'default' : 'danger',
    });

    if (!sure) return;

    this.changing.set(true);

    try {
      const { changed, skipped } = await firstValueFrom(this.api.setCodesActive(changing.map((code) => code.id), active));

      const done = changed === 1 ? '1 code' : `${changed} codes`;
      this.toasts.show(active ? `${done} turned back on.` : `${done} turned off.`, 'success');

      if (skipped.length > 0) {
        this.toasts.show(`${listed(skipped.map((s) => s.code), 4)} left as ${skipped.length === 1 ? 'it was' : 'they were'}: the event is waiting for review.`, 'info');
      }

      this.selection.clear();
      this.page.retry();
    } catch (error) {
      this.toasts.show(messageFor(error, 'The codes could not be changed.'), 'danger');
    } finally {
      this.changing.set(false);
    }
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

  /** How far through its limit a code is, for the bar under the count. */
  usedShare(code: OrganizationCode): number | null {
    return code.max_redemptions ? Math.min(100, Math.round((code.redemption_count / code.max_redemptions) * 100)) : null;
  }

  ticketsSold(code: OrganizationCode): number {
    return code.sales.reduce((total, sale) => total + sale.tickets, 0);
  }

  eventDate(code: OrganizationCode): string {
    return code.event ? shortEventTime(code.event.starts_at, code.event.timezone) : '';
  }

  /** When it works, in the event's own time where there is one. */
  window(code: OrganizationCode): string {
    const zone = code.event?.timezone;
    const day = (iso: string) =>
      new Intl.DateTimeFormat('en-CA', { day: 'numeric', month: 'short', ...(zone ? { timeZone: zone } : {}) }).format(new Date(iso));

    if (code.starts_at && code.ends_at) return `${day(code.starts_at)} – ${day(code.ends_at)}`;
    if (code.starts_at) return `From ${day(code.starts_at)}`;
    if (code.ends_at) return `Until ${day(code.ends_at)}`;
    return 'Any time';
  }
}

function labelOf(options: readonly SelectOption[], value: string): string {
  return options.find((option) => option.value === value)?.label ?? value;
}

/** "A", "A and B", "A and 3 more" — or up to `max` by name. */
function listed(names: readonly string[], max = 1): string {
  if (names.length <= 1) return names[0] ?? '';
  if (names.length === 2) return `${names[0]} and ${names[1]}`;
  if (names.length <= max) return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
  return `${names.slice(0, Math.max(1, max - 1)).join(', ')} and ${names.length - Math.max(1, max - 1)} more`;
}
