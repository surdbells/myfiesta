import { Component, DestroyRef, OnInit, computed, effect, inject, signal } from '@angular/core';
import { PenLine } from 'lucide-angular';
import type { Campaign, CampaignAudience, CampaignDraft, CampaignPage, CampaignStatus } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { formatMoney } from '../../core/money';
import { messageOf } from '../../core/errors';
import { ago } from '../../core/when';
import {
  Dialogs,
  MfBadge,
  MfButton,
  MfCard,
  MfChips,
  MfChoices,
  MfEmpty,
  MfField,
  MfIconButton,
  MfScreen,
  MfSearch,
  MfSegmented,
  MfSelect,
  MfSheet,
  MfSkeleton,
  ToastStore,
  type MfChip,
  type MfChoice,
  type MfOption,
  type MfSegment,
} from '../../ui';

const STATUS_LABEL: Record<CampaignStatus, string> = {
  draft: 'Draft',
  scheduled: 'Scheduled',
  sending: 'Sending',
  sent: 'Sent',
  cancelled: 'Cancelled',
};

const AUDIENCE_HINT: Record<CampaignAudience, string> = {
  followers: 'People who follow you.',
  past_attendees: 'Everybody who has come to one of your nights.',
  abandoned: 'People who started buying for a night and stopped.',
};

/** A datetime-local value, in the phone's own time. */
function localInput(iso: string): string {
  const at = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}T${pad(at.getHours())}:${pad(at.getMinutes())}`;
}

/**
 * Writing to people who came before: followers, past guests, people who
 * started buying and stopped.
 *
 * How many it reaches follows the choices as they are made, both halves of
 * the number — who gets it, and who on the list is left out and why — because
 * it is the number most often misread. Campaigns that went out show what came
 * back through them.
 */
@Component({
  selector: 'mf-org-campaigns',
  imports: [
    MfScreen,
    MfSearch,
    MfChips,
    MfChoices,
    MfSegmented,
    MfSelect,
    MfIconButton,
    MfCard,
    MfBadge,
    MfButton,
    MfEmpty,
    MfSkeleton,
    MfSheet,
    MfField,
  ],
  template: `
    <mf-screen title="Campaigns" back backTo="/manage" refreshable [busy]="loading()" (refresh)="load()">
      <button mfIconButton screenActions tone="tonal" [icon]="writeIcon" label="Write a campaign" (click)="compose()"></button>
      <mf-search screenBar placeholder="Subject" [(value)]="query" (searched)="load()" />

      @if (page(); as p) {
        <mf-chips class="filters" ariaLabel="Status" [options]="statusChips()" [value]="status()" (valueChange)="status.set($event); load()" />
      }

      @if (error(); as message) {
        <mf-empty title="Could not load the campaigns" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (page(); as p) {
        @if (p.data.length === 0) {
          <mf-empty [title]="status() || query() ? 'Nothing matches' : 'Nothing sent yet'" hint="A new night, early access for people who came last time, a nudge to someone who nearly bought.">
            <button mfButton (click)="compose()">Write one</button>
          </mf-empty>
        } @else {
          <ul class="items">
            @for (c of p.data; track c.id) {
              <li>
                <mf-card tappable (click)="open(c)">
                  <div class="top">
                    <h3>{{ c.subject }}</h3>
                    <mf-badge [tone]="tone(c.status)">{{ statusLabel(c.status) }}</mf-badge>
                  </div>
                  <p class="sub">{{ audienceLabel(c.audience) }}@if (c.event) { · {{ c.event.title }} }</p>
                  <p class="meta">
                    @switch (c.status) {
                      @case ('sent') {
                        Sent {{ since(c.sent_at) }} to {{ c.recipients ?? 0 }}
                        @if (c.results; as r) {
                          · {{ r.orders }} {{ r.orders === 1 ? 'order' : 'orders' }}, {{ cash(r.revenue) }}
                        }
                      }
                      @case ('scheduled') {
                        Goes {{ when(c.scheduled_for) }}
                      }
                      @default {
                        Started {{ since(c.created_at) }}
                      }
                    }
                  </p>
                </mf-card>
              </li>
            }
          </ul>

          @if (p.meta.current_page < p.meta.last_page) {
            <button mfButton class="more" variant="secondary" block [loading]="loading()" (click)="more()">Show older</button>
          }
        }
      } @else {
        <mf-card><mf-skeleton height="4rem" /></mf-card>
      }
    </mf-screen>

    <mf-sheet [open]="!!reading()" [heading]="reading()?.subject ?? ''" [subheading]="reading() ? audienceLabel(reading()!.audience) : null" closable (closed)="reading.set(null)">
      @if (reading(); as c) {
        @if (c.results; as r) {
          <dl class="facts">
            <div><dt>Reached</dt><dd>{{ c.recipients ?? 0 }}</dd></div>
            <div><dt>Orders</dt><dd>{{ r.orders }}</dd></div>
            <div><dt>Sold</dt><dd>{{ cash(r.revenue) }}</dd></div>
          </dl>
        }
        @if (c.suppressed) {
          <p class="note">{{ c.suppressed }} on the list were left out: they turned these emails off, already had a ticket, or heard from you that week.</p>
        }
        <p class="body">{{ c.body }}</p>
      }
    </mf-sheet>

    <mf-sheet [open]="writing()" [heading]="editing() ? 'Edit campaign' : 'New campaign'" subheading="Signed with your organization’s name." closable (closed)="writing.set(false)">
      <div class="form">
        @if (formError(); as message) {
          <p class="form-error" role="alert">{{ message }}</p>
        }
        <mf-choices legend="Who it goes to" [options]="audienceChoices()" [value]="audience()" (valueChange)="chooseAudience($any($event))" />

        @if (eventChoices().length > 0) {
          <mf-select [heading]="needsEvent() ? 'The event they were buying for' : 'The event it is selling'" subheading="People who already have a ticket to it are left out, and the email gets a button to buy." [options]="eventChoices()" [value]="eventId() ?? ''" (valueChange)="eventId.set($event || null)" />
        } @else {
          <p class="note">Nothing is on sale right now, so this can be news without a ticket link.</p>
        }

        <div class="reach" aria-live="polite">
          @if (reach(); as r) {
            <p><strong class="figure">{{ r.reachable }}</strong> {{ r.reachable === 1 ? 'person' : 'people' }} would get this now.</p>
            @if (r.all > r.reachable) {
              <p class="muted">{{ r.all - r.reachable }} more on the list are left out: emails turned off, already have a ticket, or heard from you this week.</p>
            }
          } @else {
            <p class="muted">Counting…</p>
          }
        </div>

        <mf-field label="Subject" [limit]="150" [count]="subject().length">
          <input [value]="subject()" (input)="subject.set($any($event.target).value)" maxlength="150" placeholder="We're back on the 14th" />
        </mf-field>
        <mf-field label="Message" [limit]="4000" [count]="body().length" hint="When it is selling an event, the date, place and a ticket button are added.">
          <textarea class="tall" [value]="body()" (input)="body.set($any($event.target).value)" maxlength="4000"></textarea>
        </mf-field>

        <mf-segmented ariaLabel="When it goes" [segments]="whens" [value]="send()" (valueChange)="send.set($any($event))" />
        @if (send() === 'later') {
          <mf-field label="Send at" hint="Your time. It goes to whoever is on the list at that moment.">
            <input type="datetime-local" [min]="minAt" [value]="at()" (input)="at.set($any($event.target).value)" />
          </mf-field>
        }
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="writing.set(false)">Not now</button>
        <button mfButton [loading]="saving()" [disabled]="!ready()" (click)="save()">{{ submitLabel() }}</button>
      </ng-container>
    </mf-sheet>
  `,
  styles: `
    .filters {
      display: block;
      margin-bottom: var(--space-4);
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

    h3 {
      flex: 1;
      font-size: var(--font-size-base);
    }

    .sub {
      margin-top: var(--space-1);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .meta {
      margin-top: var(--space-2);
      font-size: var(--font-size-xs);
      color: var(--text-subtle);
    }

    .more {
      margin-top: var(--space-4);
    }

    .facts {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: var(--space-3);
      margin: 0 0 var(--space-4);
      padding: var(--space-4);
      border-radius: var(--radius-lg);
      background: var(--surface-inset);
    }

    .facts div {
      display: grid;
      gap: 2px;
    }

    dt {
      font-size: var(--font-size-xs);
      color: var(--text-subtle);
    }

    dd {
      margin: 0;
      font-family: var(--font-family-display);
      font-weight: var(--font-weight-semibold);
      font-variant-numeric: tabular-nums;
    }

    .note {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .body {
      margin-top: var(--space-4);
      white-space: pre-wrap;
      line-height: 1.55;
    }

    .form {
      display: grid;
      gap: var(--space-4);
    }

    .reach {
      display: grid;
      gap: var(--space-1);
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-lg);
      background: var(--surface-inset);
      font-size: var(--font-size-sm);
    }

    .reach .figure {
      font-size: var(--font-size-lg);
    }

    .muted {
      color: var(--text-muted);
    }

    .tall {
      min-height: 9rem;
    }

    .form-error {
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-lg);
      background: color-mix(in srgb, var(--danger) 10%, transparent);
      color: var(--danger-text);
      font-size: var(--font-size-sm);
    }
  `,
})
export class OrgCampaigns implements OnInit {
  private readonly organizer = inject(Organizer);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  protected readonly page = signal<CampaignPage | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly query = signal('');
  protected readonly status = signal('');

  protected readonly reading = signal<Campaign | null>(null);
  protected readonly writing = signal(false);
  protected readonly editing = signal<Campaign | null>(null);
  protected readonly audience = signal<CampaignAudience>('followers');
  protected readonly eventId = signal<string | null>(null);
  protected readonly subject = signal('');
  protected readonly body = signal('');
  protected readonly send = signal<CampaignDraft['send']>('now');
  protected readonly at = signal('');
  protected readonly reach = signal<{ all: number; reachable: number } | null>(null);
  protected readonly saving = signal(false);
  protected readonly formError = signal<string | null>(null);

  protected readonly writeIcon = PenLine;
  protected readonly cash = formatMoney;
  protected readonly since = ago;
  protected readonly minAt = localInput(new Date(Date.now() + 5 * 60_000).toISOString());

  protected readonly whens: MfSegment[] = [
    { value: 'now', label: 'Now' },
    { value: 'later', label: 'Later' },
    { value: 'draft', label: 'Draft' },
  ];

  protected readonly statusChips = computed<MfChip[]>(() => {
    const counts = new Map((this.page()?.statuses ?? []).map((s) => [s.value, s.campaigns]));
    const total = [...counts.values()].reduce((a, b) => a + b, 0);

    return [
      { value: '', label: 'All', count: total },
      ...(['sent', 'scheduled', 'draft', 'cancelled'] as CampaignStatus[])
        .filter((s) => (counts.get(s) ?? 0) > 0)
        .map((s) => ({ value: s, label: STATUS_LABEL[s], count: counts.get(s) })),
    ];
  });

  protected readonly audienceChoices = computed<MfChoice[]>(() =>
    (this.page()?.audiences ?? []).map((a) => ({ value: a.value, label: a.label, hint: AUDIENCE_HINT[a.value] })),
  );

  protected readonly needsEvent = computed(() => this.page()?.audiences.find((a) => a.value === this.audience())?.needs_event ?? false);

  protected readonly eventChoices = computed<MfOption[]>(() => {
    const events = this.page()?.events ?? [];
    if (events.length === 0) return [];

    return [
      ...(this.needsEvent() ? [] : [{ value: '', label: 'No event — just news' }]),
      ...events.map((e) => ({ value: e.id, label: e.title, hint: new Date(e.starts_at).toLocaleDateString(undefined, { dateStyle: 'medium' }) })),
    ];
  });

  protected readonly ready = computed(
    () =>
      !this.saving() &&
      this.subject().trim() !== '' &&
      this.body().trim() !== '' &&
      (!this.needsEvent() || this.eventId() !== null) &&
      (this.send() !== 'later' || this.at() !== '') &&
      (this.send() !== 'now' || (this.reach()?.reachable ?? 0) > 0),
  );

  protected readonly submitLabel = computed(() => {
    if (this.send() === 'later') return 'Schedule';
    if (this.send() === 'draft') return 'Save draft';

    const n = this.reach()?.reachable;
    return n === undefined ? 'Send now' : `Send to ${n}`;
  });

  constructor() {
    let timer: ReturnType<typeof setTimeout> | null = null;

    // The number follows the choice, a moment after it settles.
    effect(() => {
      const audience = this.audience();
      const eventId = this.eventId();
      if (!this.writing()) return;

      this.reach.set(null);
      if (timer) clearTimeout(timer);
      timer = setTimeout(() => {
        this.organizer
          .campaignAudience(audience, eventId)
          .then((r) => this.reach.set(r))
          .catch(() => this.reach.set({ all: 0, reachable: 0 }));
      }, 250);
    });

    inject(DestroyRef).onDestroy(() => timer && clearTimeout(timer));
  }

  ngOnInit(): void {
    void this.load();
  }

  async load(page = 1): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      const next = await this.organizer.campaigns(page, { status: this.status() || undefined, q: this.query().trim() || undefined });
      this.page.set(page === 1 ? next : { ...next, data: [...(this.page()?.data ?? []), ...next.data] });
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected more(): void {
    void this.load((this.page()?.meta.current_page ?? 1) + 1);
  }

  protected tone(status: CampaignStatus): 'success' | 'warning' | 'danger' | 'neutral' {
    return status === 'sent' ? 'success' : status === 'scheduled' || status === 'sending' ? 'warning' : status === 'cancelled' ? 'danger' : 'neutral';
  }

  protected statusLabel(status: CampaignStatus): string {
    return STATUS_LABEL[status];
  }

  protected audienceLabel(audience: CampaignAudience): string {
    return this.page()?.audiences.find((a) => a.value === audience)?.label ?? audience;
  }

  protected when(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : '';
  }

  protected chooseAudience(value: CampaignAudience): void {
    this.audience.set(value);

    // An abandoned basket belongs to an event; "no event" is not a choice there.
    if (this.needsEvent() && this.eventId() === null) this.eventId.set(this.page()?.events[0]?.id ?? null);
  }

  protected compose(): void {
    this.editing.set(null);
    this.audience.set(this.page()?.audiences[0]?.value ?? 'followers');
    this.eventId.set(null);
    this.subject.set('');
    this.body.set('');
    this.send.set('now');
    this.at.set('');
    this.formError.set(null);
    this.writing.set(true);
  }

  protected async open(c: Campaign): Promise<void> {
    if (c.status === 'draft' || c.status === 'scheduled') {
      const chosen = await this.dialogs.menu({
        title: c.subject,
        subtitle: STATUS_LABEL[c.status],
        actions: [
          { key: 'edit', label: c.status === 'draft' ? 'Finish it' : 'Change it' },
          { key: 'cancel', label: c.status === 'draft' ? 'Throw it away' : 'Do not send it', danger: true },
        ],
      });

      if (chosen === 'edit') this.edit(c);
      if (chosen === 'cancel') await this.cancel(c);
      return;
    }

    this.reading.set(c);
  }

  private edit(c: Campaign): void {
    this.editing.set(c);
    this.audience.set(c.audience);
    this.eventId.set(c.event?.id ?? null);
    this.subject.set(c.subject);
    this.body.set(c.body);
    this.send.set(c.status === 'scheduled' ? 'later' : 'draft');
    this.at.set(c.scheduled_for ? localInput(c.scheduled_for) : '');
    this.formError.set(null);
    this.writing.set(true);
  }

  private async cancel(c: Campaign): Promise<void> {
    const sure = await this.dialogs.confirm({
      title: c.status === 'draft' ? `Throw away “${c.subject}”?` : `Stop “${c.subject}” from sending?`,
      body:
        c.status === 'scheduled'
          ? `It was going ${this.when(c.scheduled_for)}. Nobody will get it.`
          : 'The draft is gone, and nobody gets it.',
      confirmLabel: c.status === 'draft' ? 'Throw the draft away' : 'Do not send it',
      tone: 'danger',
    });

    if (!sure) return;

    try {
      await this.organizer.cancelCampaign(c.id);
      this.toasts.show(c.status === 'draft' ? 'Thrown away.' : 'It will not be sent.', 'success');
      await this.load();
    } catch (error) {
      this.toasts.show(messageOf(error, 'That could not be cancelled.'), 'danger');
    }
  }

  protected async save(): Promise<void> {
    if (!this.ready()) return;

    const draft: CampaignDraft = {
      audience: this.audience(),
      event_id: this.eventId(),
      subject: this.subject().trim(),
      body: this.body().trim(),
      send: this.send(),
      // The box is in the organizer's own time; the server wants an instant.
      scheduled_for: this.send() === 'later' ? new Date(this.at()).toISOString() : null,
    };

    // Said back before it goes, whichever way it goes: an email cannot be
    // called back once it is in somebody's inbox, and a scheduled one goes
    // while nobody is looking.
    const n = this.reach()?.reachable ?? 0;
    const people = `${n.toLocaleString()} ${n === 1 ? 'person' : 'people'}`;
    const sure = await this.dialogs.confirm(
      draft.send === 'now'
        ? {
            title: `Send “${draft.subject}” to ${people} now?`,
            body: 'It goes straight away and cannot be called back.',
            confirmLabel: `Send to ${people}`,
            tone: 'default',
          }
        : draft.send === 'later'
          ? {
              title: `Schedule “${draft.subject}”?`,
              body: `It goes out ${this.when(draft.scheduled_for)}, to whoever is on the list at that moment.`,
              consequences: ['You can change it or stop it until then.'],
              confirmLabel: 'Schedule it',
              tone: 'default',
            }
          : {
              title: `Save “${draft.subject}” as a draft?`,
              body: 'Nobody gets it yet. It waits here until you send or schedule it.',
              confirmLabel: 'Save the draft',
              tone: 'default',
            },
    );

    if (!sure || this.saving()) return;

    this.saving.set(true);
    this.formError.set(null);

    try {
      const { message } = await this.organizer.saveCampaign(draft, this.editing()?.id ?? null);
      this.writing.set(false);
      this.toasts.show(message ?? (draft.send === 'later' ? 'Scheduled.' : 'Saved as a draft.'), 'success');
      this.status.set('');
      await this.load();
    } catch (error) {
      this.formError.set(messageOf(error, 'That could not be saved.'));
    } finally {
      this.saving.set(false);
    }
  }
}
