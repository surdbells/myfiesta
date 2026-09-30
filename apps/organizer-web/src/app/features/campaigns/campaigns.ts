import { Component, DestroyRef, computed, effect, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import {
  ConfirmDialog,
  ToastStore,
  UiAlert,
  UiBadge,
  UiButton,
  UiConfirm,
  UiEmpty,
  UiErrorState,
  UiPageHeader,
  UiPagination,
  UiFilterBar,
  UiSelect,
  UiSkeleton,
  createListState,
  type FilterChip,
  type SelectOption,
} from '@myfiesta/ui';
import { Api } from '../../core/api';
import { Campaign, CampaignAudience, CampaignDraft, CampaignPage, CampaignStatus } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { loadList, searchBox } from '../../core/list-loader';
import { SavedViews } from '../../shared/saved-views';
import { formatMoney } from '../../core/money';

type When = CampaignDraft['send'];

/**
 * Writing to people who might come.
 *
 * An organizer picks a list, never an address. Who is on each list, and who
 * of them may be written to, is the server's decision. This screen's job is
 * to make that visible before the button is pressed — how many are on the
 * list, how many will get it — and afterwards, what it sold.
 */
@Component({
  selector: 'app-campaigns',
  imports: [
    FormsModule,
    SavedViews,
    UiPageHeader,
    UiButton,
    UiAlert,
    UiBadge,
    UiConfirm,
    UiEmpty,
    UiErrorState,
    UiPagination,
    UiFilterBar,
    UiSelect,
    UiSkeleton,
  ],
  templateUrl: './campaigns.html',
})
export class Campaigns {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  private readonly confirmDialog = inject(ConfirmDialog);

  readonly formatMoney = formatMoney;

  // --- narrowing the list ----------------------------------------------------
  //
  // An organization that writes weekly has two hundred of these inside a
  // year. The three questions asked of the list are always the same: what is
  // still to go out, what went out for this night, and where is the one that
  // started "We're back on the".
  readonly list = createListState({
    list: 'campaigns',
    filters: {
      q: { kind: 'text' },
      status: { kind: 'many' },
      audience: { kind: 'many' },
      event_id: { kind: 'many' },
    },
    sort: { column: 'created', direction: 'desc' },
  });

  readonly search = searchBox(this.list, 'q');

  private readonly loader = loadList(this.list.query, () => this.api.campaigns(this.list.query()));

  readonly page = computed<CampaignPage | null>(() => this.loader.result());
  readonly loading = this.loader.loading;
  readonly failed = this.loader.failed;
  readonly refused = this.loader.refused;

  /** A card list sorts from one control rather than column headings. */
  readonly sortOptions: SelectOption[] = [
    { value: 'created:desc', label: 'Newest first' },
    { value: 'created:asc', label: 'Oldest first' },
    { value: 'subject:asc', label: 'Subject, A to Z' },
  ];

  readonly sortValue = computed(() => {
    const sort = this.list.sort();
    return sort ? `${sort.column}:${sort.direction}` : 'created:desc';
  });

  sortBy(value: string | null): void {
    const [column, direction] = (value ?? 'created:desc').split(':');
    this.list.sortBy({ column, direction: direction === 'asc' ? 'asc' : 'desc' });
  }

  // The composer.
  readonly editing = signal<Campaign | null>(null);
  readonly audience = signal<CampaignAudience>('past_attendees');
  readonly eventId = signal<string | null>(null);
  readonly subject = signal('');
  readonly body = signal('');
  readonly when = signal<When>('now');
  readonly at = signal('');
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);

  readonly reach = signal<{ all: number; reachable: number } | null>(null);
  readonly reachFailed = signal(false);

  readonly cancelling = signal<Campaign | null>(null);
  readonly cancellingBusy = signal(false);

  /** Only the statuses this organization actually has, with their counts. */
  readonly statusOptions = computed<SelectOption[]>(() =>
    (this.page()?.statuses ?? [])
      .filter((row) => row.campaigns > 0)
      .map((row) => ({ value: row.value, label: this.statusLabel(row.value), hint: String(row.campaigns) })),
  );

  readonly audienceFilterOptions = computed<SelectOption[]>(() => this.audiences().map((a) => ({ value: a.value, label: a.label })));

  readonly aboutOptions = computed<SelectOption[]>(() =>
    (this.page()?.written_about ?? []).map((e) => ({
      value: e.id,
      label: e.title,
      hint: new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(e.starts_at)),
    })),
  );

  readonly anyFilter = computed(() => this.list.active() > 0);

  readonly chips = computed<FilterChip[]>(() => {
    const chips: FilterChip[] = [];
    const values = this.list.values();

    if (values.q) chips.push({ key: 'q', label: 'Subject', value: String(values.q) });

    const statuses = values.status as readonly string[];
    if (statuses.length > 0) {
      chips.push({ key: 'status', label: 'Status', value: statuses.map((s) => this.statusLabel(s as CampaignStatus)).join(' or ') });
    }

    const lists = values.audience as readonly string[];
    if (lists.length > 0) {
      chips.push({
        key: 'audience',
        label: lists.length === 1 ? 'List' : 'Lists',
        value: lists.map((value) => this.audiences().find((a) => a.value === value)?.label ?? value).join(' or '),
      });
    }

    const events = values.event_id as readonly string[];
    if (events.length > 0) {
      const names = events.map((id) => (this.page()?.written_about ?? []).find((e) => e.id === id)?.title ?? 'One event');
      chips.push({ key: 'event_id', label: 'About', value: names.join(' or ') });
    }

    return chips;
  });

  readonly summary = computed(() => {
    const meta = this.page()?.meta;
    if (!meta) return null;

    const noun = meta.total === 1 ? 'campaign' : 'campaigns';
    const shown = this.page()?.data.length ?? 0;

    return meta.total > shown
      ? `Showing ${shown} of ${meta.total.toLocaleString()} ${noun}`
      : `${meta.total.toLocaleString()} ${noun}`;
  });

  remove(key: string): void {
    this.list.clear(key as 'q');
  }

  readonly audiences = computed(() => this.page()?.audiences ?? []);

  readonly needsEvent = computed(() => this.audiences().find((a) => a.value === this.audience())?.needs_event ?? false);

  readonly eventOptions = computed<SelectOption[]>(() => [
    ...(this.needsEvent() ? [] : [{ value: '', label: 'No event — just news' }]),
    ...(this.page()?.events ?? []).map((e) => ({
      value: e.id,
      label: e.title,
      hint: new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(e.starts_at)),
    })),
  ]);

  readonly ready = computed(
    () =>
      this.subject().trim() !== '' &&
      this.body().trim() !== '' &&
      (!this.needsEvent() || this.eventId() !== null) &&
      (this.when() !== 'later' || this.at() !== ''),
  );

  readonly submitLabel = computed(() => {
    switch (this.when()) {
      case 'now': {
        const n = this.reach()?.reachable;
        return n === undefined ? 'Send now' : `Send to ${n} ${n === 1 ? 'person' : 'people'}`;
      }
      case 'later':
        return 'Schedule';
      default:
        return 'Save draft';
    }
  });

  private reachTimer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    // The number on screen follows the choice, a moment after it settles.
    effect(() => {
      const audience = this.audience();
      const eventId = this.eventId();
      if (!this.page()) return;

      if (this.reachTimer) clearTimeout(this.reachTimer);
      this.reachTimer = setTimeout(() => this.countReach(audience, eventId), 250);
    });

    inject(DestroyRef).onDestroy(() => {
      if (this.reachTimer) clearTimeout(this.reachTimer);
    });
  }

  /** After a failure: the same question again. */
  load(): void {
    this.loader.retry();
  }

  chooseAudience(value: CampaignAudience): void {
    this.audience.set(value);

    // An abandoned basket belongs to an event; "no event" is not a choice there.
    if (this.needsEvent() && this.eventId() === null) {
      this.eventId.set(this.page()?.events[0]?.id ?? null);
    }
  }

  chooseEvent(value: string | null): void {
    this.eventId.set(value || null);
  }

  async submit(): Promise<void> {
    if (!this.ready() || this.saving()) return;

    if (!(await this.confirmSend()) || this.saving()) return;

    this.saving.set(true);
    this.error.set(null);

    const draft: CampaignDraft = {
      audience: this.audience(),
      event_id: this.eventId(),
      subject: this.subject().trim(),
      body: this.body().trim(),
      send: this.when(),
      // The box is in the organizer's own time; the server wants an instant.
      scheduled_for: this.when() === 'later' ? new Date(this.at()).toISOString() : null,
    };

    this.api.saveCampaign(draft, this.editing()?.id ?? null).subscribe({
      next: ({ message }) => {
        this.saving.set(false);
        this.toasts.show(
          message ?? (draft.send === 'later' ? 'Scheduled. It will go to whoever is on the list at that moment.' : 'Saved as a draft.'),
          'success',
        );
        this.reset();
        this.list.goTo(1);
        this.loader.retry();
      },
      error: (response: HttpErrorResponse) => {
        this.saving.set(false);
        this.error.set(messageFor(response, 'That could not be saved.'));
        // An empty list is saved as a draft by the server; show it.
        if (response.status === 422 && response.error?.data) this.load();
      },
    });
  }

  /**
   * Said back before it goes: which list, how many of them it reaches, and
   * when. An email cannot be called back once it is in somebody's inbox, so
   * the count and the subject are in front of the organizer at the moment
   * they commit to it, not only above a form they have scrolled past.
   */
  private confirmSend(): Promise<boolean> {
    const subject = this.subject().trim();
    const list = this.audienceLabel(this.audience());
    const about = (this.page()?.events ?? []).find((e) => e.id === this.eventId())?.title;
    const reach = this.reach();
    const people = reach ? `${reach.reachable.toLocaleString()} ${reach.reachable === 1 ? 'person' : 'people'}` : 'everybody on the list who may be written to';
    const left = reach ? reach.all - reach.reachable : 0;
    const onList = [`The list: ${list}${about ? `, for ${about}` : ''}.`];

    if (left > 0) onList.push(`${left.toLocaleString()} on it may not be written to, and are left out.`);

    switch (this.when()) {
      case 'now':
        return this.confirmDialog.confirm({
          title: `Send “${subject}” now?`,
          body: `It goes to ${people} straight away, and cannot be called back.`,
          consequences: onList,
          confirmLabel: reach ? `Send to ${people}` : 'Send now',
          tone: 'default',
        });
      case 'later': {
        const at = new Date(this.at());
        const when = Number.isNaN(at.getTime())
          ? 'at the time you chose'
          : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(at);

        return this.confirmDialog.confirm({
          title: `Schedule “${subject}”?`,
          body: `It goes out ${when}, to whoever is on the list at that moment.`,
          consequences: [...onList, 'You can change it or cancel it until then.'],
          confirmLabel: 'Schedule it',
          tone: 'default',
        });
      }
      default:
        return this.confirmDialog.confirm({
          title: `Save “${subject}” as a draft?`,
          body: 'Nobody gets it yet. It waits here until you send or schedule it.',
          confirmLabel: 'Save draft',
          tone: 'default',
        });
    }
  }

  edit(campaign: Campaign): void {
    this.editing.set(campaign);
    this.audience.set(campaign.audience);
    this.eventId.set(campaign.event?.id ?? null);
    this.subject.set(campaign.subject);
    this.body.set(campaign.body);
    this.when.set(campaign.status === 'scheduled' ? 'later' : 'draft');
    this.at.set(campaign.scheduled_for ? toLocalInput(campaign.scheduled_for) : '');
    this.error.set(null);

    globalThis.scrollTo?.({ top: 0, behavior: 'smooth' });
  }

  reset(): void {
    this.editing.set(null);
    this.subject.set('');
    this.body.set('');
    this.when.set('now');
    this.at.set('');
    this.error.set(null);
  }

  confirmCancel(): void {
    const campaign = this.cancelling();
    if (!campaign) return;

    this.cancellingBusy.set(true);

    this.api.cancelCampaign(campaign.id).subscribe({
      next: () => {
        this.cancellingBusy.set(false);
        this.cancelling.set(null);
        if (this.editing()?.id === campaign.id) this.reset();
        this.toasts.show('Cancelled. Nobody will get it.', 'success');
        this.load();
      },
      error: (response: HttpErrorResponse) => {
        this.cancellingBusy.set(false);
        this.cancelling.set(null);
        this.toasts.show(messageFor(response, 'That could not be cancelled.'), 'danger');
        this.load();
      },
    });
  }

  /**
   * When it went, or goes, written as the rest of the console writes a time —
   * "Sep 28, 6:15 p.m." — rather than the day-first, 24-hour "28 Sep, 18:15"
   * of a date pipe. In the reader's own zone: a campaign is not one night's.
   */
  timeOf(iso: string | null, withWeekday = false): string {
    if (!iso) return '';

    return new Intl.DateTimeFormat('en-CA', {
      ...(withWeekday ? { weekday: 'short' as const } : {}),
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    }).format(new Date(iso));
  }

  /** "Sep 28": the day a draft was started. */
  dayOf(iso: string): string {
    return new Intl.DateTimeFormat('en-CA', { month: 'short', day: 'numeric' }).format(new Date(iso));
  }

  audienceLabel(value: CampaignAudience): string {
    return this.audiences().find((a) => a.value === value)?.label ?? value;
  }

  statusTone(status: CampaignStatus): 'success' | 'warning' | 'neutral' | 'danger' | 'brand' {
    return { sent: 'success', scheduled: 'brand', sending: 'warning', draft: 'neutral', cancelled: 'danger' }[status] as
      | 'success'
      | 'warning'
      | 'neutral'
      | 'danger'
      | 'brand';
  }

  statusLabel(status: CampaignStatus): string {
    return { sent: 'Sent', scheduled: 'Scheduled', sending: 'Sending', draft: 'Draft', cancelled: 'Cancelled' }[status];
  }

  /** The earliest a scheduled time can be, for the picker. */
  readonly minAt = toLocalInput(new Date(Date.now() + 5 * 60_000).toISOString());

  private countReach(audience: CampaignAudience, eventId: string | null): void {
    if (this.needsEvent() && !eventId) {
      this.reach.set(null);
      return;
    }

    this.reachFailed.set(false);

    this.api.campaignAudience(audience, eventId).subscribe({
      next: (reach) => {
        // Only if the choice has not moved on while this was asked.
        if (this.audience() === audience && this.eventId() === eventId) this.reach.set(reach);
      },
      error: () => this.reachFailed.set(true),
    });
  }
}

/** An instant as a datetime-local value in this browser's own time. */
function toLocalInput(iso: string): string {
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
