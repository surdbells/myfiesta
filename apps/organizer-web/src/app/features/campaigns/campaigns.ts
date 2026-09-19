import { Component, DestroyRef, computed, effect, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import {
  ToastStore,
  UiAlert,
  UiBadge,
  UiButton,
  UiConfirm,
  UiEmpty,
  UiErrorState,
  UiPageHeader,
  UiPagination,
  UiSelect,
  UiSkeleton,
  type SelectOption,
} from '@myfiesta/ui';
import { Api } from '../../core/api';
import { Campaign, CampaignAudience, CampaignDraft, CampaignPage, CampaignStatus } from '../../core/api.types';
import { messageFor } from '../../core/errors';
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
    DatePipe,
    UiPageHeader,
    UiButton,
    UiAlert,
    UiBadge,
    UiConfirm,
    UiEmpty,
    UiErrorState,
    UiPagination,
    UiSelect,
    UiSkeleton,
  ],
  templateUrl: './campaigns.html',
})
export class Campaigns {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);

  readonly formatMoney = formatMoney;

  readonly page = signal<CampaignPage | null>(null);
  readonly pageNumber = signal(1);
  readonly loading = signal(true);
  readonly failed = signal(false);
  readonly refused = signal(false);

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
    this.load();

    // The number on screen follows the choice, a moment after it settles.
    effect(() => {
      const audience = this.audience();
      const eventId = this.eventId();
      if (!this.page()) return;

      if (this.reachTimer) clearTimeout(this.reachTimer);
      this.reachTimer = setTimeout(() => this.countReach(audience, eventId), 250);
    });

    inject(DestroyRef).onDestroy(() => this.reachTimer && clearTimeout(this.reachTimer));
  }

  load(): void {
    this.loading.set(true);
    this.failed.set(false);

    this.api.campaigns(this.pageNumber()).subscribe({
      next: (page) => {
        this.page.set(page);
        this.loading.set(false);
      },
      error: (response: HttpErrorResponse) => {
        response?.status === 403 ? this.refused.set(true) : this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  goTo(page: number): void {
    this.pageNumber.set(page);
    this.load();
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

  submit(): void {
    if (!this.ready() || this.saving()) return;

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
        this.pageNumber.set(1);
        this.load();
      },
      error: (response: HttpErrorResponse) => {
        this.saving.set(false);
        this.error.set(messageFor(response, 'That could not be saved.'));
        // An empty list is saved as a draft by the server; show it.
        if (response.status === 422 && response.error?.data) this.load();
      },
    });
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
