import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Share } from '@capacitor/share';
import { Browser } from '@capacitor/browser';
import {
  BarChart3,
  Bell,
  CalendarClock,
  ClipboardList,
  Copy,
  DoorOpen,
  ExternalLink,
  Eye,
  EyeOff,
  Hourglass,
  Image,
  Mail,
  MoreHorizontal,
  Pencil,
  Receipt,
  Repeat,
  ScanLine,
  Share2,
  ShoppingBag,
  Ticket,
  TicketPercent,
  Users,
  XCircle,
} from 'lucide-angular';
import type { EventSummary, OrganizerEventDetail, Series, SeriesOccurrence } from '@myfiesta/api-types';
import { isoToZonedWallClock, zonedWallClockToIso } from '@myfiesta/shared/zoned-time';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { Discover } from '../../core/discovery';
import { formatMoney } from '../../core/money';
import { messageOf } from '../../core/errors';
import { longEventTime, shortEventTime } from '../../core/event-time';
import { until } from '../../core/when';
import { EventContext } from './event-context';
import {
  Dialogs,
  MfBadge,
  MfButton,
  MfCard,
  MfEmpty,
  MfField,
  MfIcon,
  MfIconButton,
  MfList,
  MfRow,
  MfScreen,
  MfSheet,
  MfSkeleton,
  MfStat,
  ToastStore,
  type MenuAction,
} from '../../ui';

/**
 * One night, and everything done to it.
 *
 * Opens on the poster, then the four numbers that say how it is going, then
 * the things done most — scan tickets, share the link — as buttons, and the
 * rest of the night's tools as a list in the order they are needed: what it
 * sells, who is coming, and the night itself. Everything that changes the
 * event as a whole (publish, duplicate, repeat, cancel) is under the "…" in
 * the bar, where it cannot be pressed by accident while scrolling.
 */
@Component({
  selector: 'mf-event-hub',
  imports: [MfScreen, MfIconButton, MfIcon, MfBadge, MfButton, MfCard, MfEmpty, MfField, MfList, MfRow, MfSheet, MfSkeleton, MfStat],
  template: `
    <mf-screen
      [title]="event()?.title ?? 'Event'"
      back
      backTo="/manage/events"
      overlay
      [overlayDark]="!!event()?.poster_url"
      flush
      refreshable
      [busy]="loading() || working()"
      (refresh)="load()"
    >
      <button
        mfIconButton
        screenActions
        [tone]="event()?.poster_url ? 'over' : 'plain'"
        [icon]="moreIcon"
        label="Event actions"
        [disabled]="!event()"
        (click)="actions()"
      ></button>

      @if (event(); as ev) {
        <header class="hero" [class.with-poster]="!!ev.poster_url">
          @if (ev.poster_url) {
            <img class="poster" [src]="ev.poster_url" alt="" />
            <div class="shade" aria-hidden="true"></div>
          }
          <div class="hero-text">
            <div class="badges">
              @switch (ev.status) {
                @case ('published') {
                  <mf-badge tone="success">On sale</mf-badge>
                }
                @case ('draft') {
                  <mf-badge tone="warning">Draft</mf-badge>
                }
                @case ('cancelled') {
                  <mf-badge tone="danger">Cancelled</mf-badge>
                }
              }
              <span class="countdown">{{ countdown() }}</span>
            </div>
            <h1>{{ ev.title }}</h1>
            <p class="when">{{ when() }}</p>
            <p class="where">{{ ev.city }}</p>
          </div>
        </header>

        <div class="content">
          @if (ev.status === 'draft' && session.can('events.publish')) {
            <mf-card class="draft">
              <p class="draft-title">Not on sale yet</p>
              <p class="muted">Nobody can see this night until it is published.</p>
              <button mfButton block [loading]="working()" (click)="publish()">Put it on sale</button>
            </mf-card>
          }

          <div class="figures">
            <mf-stat
              label="Sold"
              [value]="ev.tickets_issued.toLocaleString()"
              [hint]="ev.capacity ? 'of ' + ev.capacity.toLocaleString() : 'No limit set'"
              [portion]="ev.capacity ? ev.tickets_issued / ev.capacity : null"
            />
            @if (summary(); as s) {
              <mf-stat label="Owed to you" [value]="cash(s.net)" [hint]="s.orders + (s.orders === 1 ? ' order' : ' orders')" />
            } @else {
              <mf-stat label="Orders" [value]="ev.orders.toLocaleString()" />
            }
            <mf-stat label="Looked" [value]="ev.views.toLocaleString()" hint="Page views" />
            <mf-stat label="Arrived" [value]="ev.checked_in.toLocaleString()" [hint]="ev.tickets_issued ? 'of ' + ev.tickets_issued : null" />
          </div>

          <div class="quick">
            @if (session.can('door.scan') && ev.status !== 'cancelled') {
              <button mfButton (click)="scan()"><mf-icon [icon]="scanIcon" size="sm" /> Scan tickets</button>
            }
            <button mfButton variant="secondary" (click)="share()"><mf-icon [icon]="shareIcon" size="sm" /> Share</button>
          </div>

          <mf-list class="block" heading="What it sells">
            @if (session.can('money.view')) {
              <mf-row label="Sales and insights" sub="Where buyers came from, day by day" [icon]="salesIcon" tone="brand" [link]="here('sales')" />
            }
            @if (session.can('tickets.manage')) {
              <mf-row label="Tickets" sub="Types, prices and how many" [icon]="ticketIcon" [link]="here('tickets')" />
              <mf-row label="Extras" sub="Tables, bottles, merch" [icon]="extrasIcon" [link]="here('extras')" />
              <mf-row label="Questions at checkout" [icon]="questionsIcon" [link]="here('questions')" />
            }
            @if (session.can('codes.manage')) {
              <mf-row label="Discount codes" sub="Codes, batches and promoters" [icon]="codesIcon" [link]="here('codes')" />
            }
          </mf-list>

          <mf-list class="block" heading="Who is coming">
            @if (session.can('attendees.view')) {
              <mf-row label="Guest list" [sub]="ev.checked_in + ' arrived of ' + ev.tickets_issued" [icon]="guestsIcon" tone="brand" [link]="here('guests')" />
            }
            @if (session.can('money.view')) {
              <mf-row label="Orders" sub="Refunds happen here" [icon]="ordersIcon" [link]="here('orders')" />
            }
            @if (session.can('messages.send')) {
              <mf-row label="Message ticket holders" [icon]="messageIcon" [link]="here('messages')" />
            }
            @if (session.can('attendees.view')) {
              <mf-row label="Waitlist" sub="People waiting for tickets to come back" [icon]="waitIcon" [link]="here('waitlist')" />
            }
          </mf-list>

          <mf-list class="block" heading="The night">
            @if (session.can('door.scan')) {
              <mf-row label="Door" sub="Door passes for staff, and the till" [icon]="doorIcon" [link]="here('door')" />
            }
            @if (session.can('events.edit')) {
              <mf-row label="Poster and gallery" [icon]="picturesIcon" [link]="here('pictures')" />
              <mf-row label="Details" sub="Name, time, place, description" [icon]="detailsIcon" [link]="here('edit')" />
              <mf-row label="Reminders" sub="Emails before the doors open" [icon]="reminderIcon" [link]="here('reminders')" />
            }
          </mf-list>

          @if (series(); as s) {
            @if (s.status === 'ended') {
              <mf-list class="block" heading="Repeats" footer="This night no longer repeats. Dates already made are kept." />
            } @else {
              <mf-list class="block" heading="Repeats" [footer]="seriesFooter(s)">
                @for (date of upcoming(); track date.id) {
                  <mf-row
                    [label]="occurrenceDate(date.starts_at)"
                    [sub]="date.id === id() ? 'This one' : date.moved ? 'Moved from its usual date' : null"
                    [icon]="dateIcon"
                    [action]="date.id !== id()"
                    [chevron]="date.id !== id()"
                    (pressed)="occurrence(date)"
                  >
                    @if (date.status !== 'published') {
                      <mf-badge>{{ date.status === 'draft' ? 'Draft' : date.status }}</mf-badge>
                    }
                  </mf-row>
                } @empty {
                  <mf-row label="No dates left in this series" [chevron]="false" />
                }
                @if (session.can('events.edit')) {
                  <mf-row label="Stop repeating" sub="Dates people have bought tickets for are kept" [icon]="stopIcon" danger action (pressed)="stopRepeating()" />
                }
              </mf-list>
            }
          }
        </div>
      } @else if (error(); as message) {
        <div class="content">
          <mf-empty title="Could not load this event" [hint]="message">
            <button mfButton variant="secondary" (click)="load()">Try again</button>
          </mf-empty>
        </div>
      } @else {
        <div class="skeleton-hero"><mf-skeleton height="100%" /></div>
        <div class="content">
          <div class="figures">
            @for (n of [0, 1, 2, 3]; track n) {
              <mf-card><mf-skeleton height="3rem" /></mf-card>
            }
          </div>
        </div>
      }
    </mf-screen>

    <mf-sheet [open]="duplicating()" heading="Duplicate this night" subheading="Tickets, extras and questions come across. Sales do not." (closed)="duplicating.set(false)">
      <div class="form">
        <mf-field label="Name">
          <input [value]="copyTitle()" (input)="copyTitle.set($any($event.target).value)" />
        </mf-field>
        <mf-field label="Starts" [hint]="'In ' + (event()?.timezone ?? '') + ' time, where the night is'">
          <input type="datetime-local" [value]="copyStarts()" (input)="copyStarts.set($any($event.target).value)" />
        </mf-field>
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="duplicating.set(false)">Cancel</button>
        <button mfButton [loading]="working()" [disabled]="!copyStarts()" (click)="duplicate()">Duplicate</button>
      </ng-container>
    </mf-sheet>
  `,
  styles: `
    .hero {
      position: relative;
      display: grid;
      align-items: end;
      min-height: calc(var(--mf-bar-h) + 180px);
      padding: calc(var(--mf-bar-h) + var(--space-4)) var(--space-5) var(--space-6);
      background:
        radial-gradient(120% 90% at 10% 0%, color-mix(in srgb, var(--primary) 18%, transparent), transparent 60%),
        var(--surface);
      border-bottom: 1px solid var(--border-subtle);
    }

    .hero.with-poster {
      min-height: 420px;
      color: var(--color-neutral-0);
      border-bottom: 0;
    }

    .poster {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .shade {
      position: absolute;
      inset: 0;
      background: linear-gradient(to top, rgb(5 10 7 / 0.92) 0%, rgb(5 10 7 / 0.35) 50%, rgb(5 10 7 / 0.55) 100%);
    }

    .hero-text {
      position: relative;
      display: grid;
      gap: var(--space-1);
    }

    .badges {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      margin-bottom: var(--space-1);
    }

    .countdown {
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-semibold);
      letter-spacing: 0.06em;
      text-transform: uppercase;
      opacity: 0.8;
    }

    h1 {
      font-size: var(--font-size-2xl);
      letter-spacing: var(--font-tracking-tight);
      line-height: 1.1;
    }

    .when {
      font-weight: var(--font-weight-medium);
    }

    .where {
      font-size: var(--font-size-sm);
      opacity: 0.8;
    }

    .skeleton-hero {
      height: 300px;
    }

    .content {
      padding: var(--space-5);
    }

    .draft {
      display: grid;
      gap: var(--space-2);
      margin-bottom: var(--space-4);
      background: color-mix(in srgb, var(--warning) 10%, var(--surface-raised));
    }

    .draft-title {
      font-family: var(--font-family-display);
      font-size: var(--font-size-lg);
      font-weight: var(--font-weight-semibold);
    }

    .figures {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-3);
    }

    .quick {
      display: flex;
      gap: var(--space-3);
      margin-top: var(--space-4);
    }

    .quick > * {
      flex: 1;
    }

    .block {
      display: block;
      margin-top: var(--space-6);
    }

    .form {
      display: grid;
      gap: var(--space-4);
    }
  `,
})
export class EventHub implements OnInit {
  /** From the route. */
  readonly id = input.required<string>();

  protected readonly session = inject(SessionStore);
  private readonly router = inject(Router);
  private readonly organizer = inject(Organizer);
  private readonly discover = inject(Discover);
  private readonly context = inject(EventContext);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  protected readonly event = signal<OrganizerEventDetail | null>(null);
  protected readonly summary = signal<EventSummary | null>(null);
  protected readonly series = signal<Series | null>(null);

  /** Dates still to come. A residency's past nights are not a schedule. */
  protected readonly upcoming = computed(() => (this.series()?.occurrences ?? []).filter((o) => new Date(o.starts_at).getTime() > Date.now()));
  protected readonly loading = signal(true);
  protected readonly working = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly duplicating = signal(false);
  protected readonly copyTitle = signal('');
  protected readonly copyStarts = signal('');

  protected readonly moreIcon = MoreHorizontal;
  protected readonly salesIcon = BarChart3;
  protected readonly ticketIcon = Ticket;
  protected readonly extrasIcon = ShoppingBag;
  protected readonly questionsIcon = ClipboardList;
  protected readonly codesIcon = TicketPercent;
  protected readonly guestsIcon = Users;
  protected readonly ordersIcon = Receipt;
  protected readonly messageIcon = Mail;
  protected readonly waitIcon = Hourglass;
  protected readonly doorIcon = DoorOpen;
  protected readonly picturesIcon = Image;
  protected readonly detailsIcon = Pencil;
  protected readonly reminderIcon = Bell;
  protected readonly scanIcon = ScanLine;
  protected readonly shareIcon = Share2;
  protected readonly dateIcon = CalendarClock;
  protected readonly stopIcon = XCircle;

  protected readonly cash = formatMoney;

  protected readonly when = computed(() => {
    const ev = this.event();
    return ev ? longEventTime(ev.starts_at, ev.timezone) : '';
  });

  protected readonly countdown = computed(() => {
    const ev = this.event();
    return ev ? until(ev.starts_at) : '';
  });

  ngOnInit(): void {
    void this.load();
  }

  protected here(section: string): string[] {
    return ['/manage/events', this.id(), section];
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      const [event, summary] = await Promise.all([
        this.context.get(this.id(), true),
        this.session.can('money.view') ? this.organizer.summary(this.id()).catch(() => null) : Promise.resolve(null),
      ]);

      this.event.set(event);
      this.summary.set(summary);
      void this.loadSeries();
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  private async loadSeries(): Promise<void> {
    try {
      this.series.set((await this.organizer.series(this.id())).series);
    } catch {
      this.series.set(null);
    }
  }

  /** A date in the event's own zone, which is the venue's. */
  protected occurrenceDate(iso: string): string {
    return shortEventTime(iso, this.event()?.timezone ?? 'UTC');
  }

  protected seriesFooter(s: Series): string {
    const skipped = s.skipped.length;
    const made = `${this.upcoming().length} to come${s.past_count ? `, ${s.past_count} already happened` : ''}.`;

    return skipped ? `${made} ${skipped} skipped — they will not come back.` : made;
  }

  protected async occurrence(date: SeriesOccurrence): Promise<void> {
    const chosen = await this.dialogs.menu({
      title: this.occurrenceDate(date.starts_at),
      subtitle: date.title,
      actions: [
        { key: 'open', label: 'Open this date', icon: CalendarClock },
        ...(this.session.can('events.edit') ? [{ key: 'skip', label: 'Skip this date', icon: XCircle, danger: true, hint: 'It is taken out and will not come back' }] : []),
      ],
    });

    if (chosen === 'open') {
      void this.router.navigate(['/manage/events', date.id]);
      return;
    }

    if (chosen !== 'skip') return;

    const reason = await this.dialogs.prompt({
      title: 'Skip this date?',
      message: 'Anybody holding a ticket for it is told. Give them a reason if there is one.',
      label: 'Reason',
      placeholder: 'The venue is closed that week',
      confirm: 'Skip it',
    });

    if (reason === null) return;

    try {
      const { message } = await this.organizer.skipOccurrence(this.id(), date.id, reason.trim() || undefined);
      this.toasts.show(message, 'success');
      await this.loadSeries();
    } catch (error) {
      this.toasts.show(messageOf(error, 'That date could not be taken out.'), 'danger');
    }
  }

  protected async stopRepeating(): Promise<void> {
    const sure = await this.dialogs.confirm({
      title: 'Stop repeating?',
      message: 'No more dates are made. Dates people have already bought tickets for are kept.',
      confirm: 'Stop repeating',
      danger: true,
    });

    if (!sure) return;

    try {
      const { message } = await this.organizer.stopRepeating(this.id());
      this.toasts.show(message, 'success');
      await this.loadSeries();
    } catch (error) {
      this.toasts.show(messageOf(error, 'That series could not be stopped.'), 'danger');
    }
  }

  protected scan(): void {
    const ev = this.event();
    if (ev) void this.router.navigate(['/door'], { queryParams: { event: ev.id, title: ev.title } });
  }

  protected async share(): Promise<void> {
    const ev = this.event();
    if (!ev) return;

    try {
      await Share.share({ title: ev.title, text: `${ev.title} — ${this.when()}`, url: this.publicUrl(ev) });
    } catch {
      // Dismissed. Nothing to undo.
    }
  }

  private publicUrl(ev: OrganizerEventDetail): string {
    return `${this.discover.siteBase()}/${ev.slug}`;
  }

  // --- the "…" menu -------------------------------------------------------------

  protected async actions(): Promise<void> {
    const ev = this.event();
    if (!ev) return;

    const cancelled = ev.status === 'cancelled';
    const items: MenuAction[] = [];

    if (this.session.can('events.edit') && !cancelled) items.push({ key: 'edit', label: 'Edit details', icon: Pencil });
    if (this.session.can('events.publish') && ev.status === 'draft') items.push({ key: 'publish', label: 'Put it on sale', icon: Eye });
    if (this.session.can('events.publish') && ev.status === 'published') {
      items.push({ key: 'unpublish', label: 'Take it off sale', icon: EyeOff, hint: 'Hidden until you publish it again' });
    }
    items.push({ key: 'share', label: 'Share the link', icon: Share2 });
    if (ev.status === 'published') items.push({ key: 'view', label: 'See the public page', icon: ExternalLink });
    if (this.session.can('events.create')) {
      items.push({ key: 'duplicate', label: 'Duplicate', icon: Copy, hint: 'A new night with the same tickets' });
      // Once it repeats, its dates are managed under Repeats rather than started again.
      if (!cancelled && !this.series()) items.push({ key: 'repeat', label: 'Repeat', icon: Repeat, hint: 'Weekly, fortnightly or monthly' });
    }
    if (this.session.can('door.scan') && !cancelled) items.push({ key: 'scan', label: 'Scan tickets', icon: ScanLine });
    if (this.session.can('events.cancel') && !cancelled) {
      items.push({ key: 'cancel', label: 'Cancel the event', icon: XCircle, danger: true, hint: 'Tells every ticket holder, and can refund them' });
    }

    const chosen = await this.dialogs.menu({ title: ev.title, subtitle: this.when(), actions: items });

    switch (chosen) {
      case 'edit':
        void this.router.navigate(this.here('edit'));
        break;
      case 'publish':
        await this.publish();
        break;
      case 'unpublish':
        await this.unpublish();
        break;
      case 'share':
        await this.share();
        break;
      case 'view':
        await Browser.open({ url: this.publicUrl(ev) });
        break;
      case 'duplicate':
        this.startDuplicate();
        break;
      case 'repeat':
        await this.repeat();
        break;
      case 'scan':
        this.scan();
        break;
      case 'cancel':
        await this.cancel();
        break;
    }
  }

  protected async publish(): Promise<void> {
    const ev = this.event();
    if (!ev) return;

    const sure = await this.dialogs.confirm({
      title: 'Put it on sale?',
      message: 'Anybody with the link can see it and buy, and it appears in What’s on. Your followers hear about it.',
      confirm: 'Publish',
    });

    if (!sure) return;

    await this.run(async () => {
      const result = await this.organizer.publish(ev.id, 'published');
      const told = result.followers_told ? ` ${result.followers_told} followers told.` : '';
      this.toasts.show(`On sale.${told}`, 'success');
    });
  }

  private async unpublish(): Promise<void> {
    const ev = this.event();
    if (!ev) return;

    const sure = await this.dialogs.confirm({
      title: 'Take it off sale?',
      message: 'The page stops showing and nobody new can buy. Tickets already sold still work at the door.',
      confirm: 'Take it off sale',
      danger: true,
    });

    if (!sure) return;

    await this.run(async () => {
      await this.organizer.publish(ev.id, 'draft');
      this.toasts.show('Taken off sale.', 'success');
    });
  }

  private startDuplicate(): void {
    const ev = this.event();
    if (!ev) return;

    // A week later, same time, as the likeliest next date.
    const nextWeek = new Date(new Date(ev.starts_at).getTime() + 7 * 24 * 3600_000).toISOString();

    this.copyTitle.set(ev.title);
    this.copyStarts.set(isoToZonedWallClock(nextWeek, ev.timezone) ?? '');
    this.duplicating.set(true);
  }

  protected async duplicate(): Promise<void> {
    const ev = this.event();
    const starts = zonedWallClockToIso(this.copyStarts(), ev?.timezone ?? 'UTC');

    if (!ev || !starts) return;

    await this.run(async () => {
      const copy = (await this.organizer.duplicate(ev.id, starts, this.copyTitle().trim() || undefined)) as {
        id?: string;
        data?: { id?: string };
      };
      const id = copy.data?.id ?? copy.id;

      this.duplicating.set(false);
      this.toasts.show('Duplicated as a draft.', 'success');

      if (id) void this.router.navigate(['/manage/events', id]);
    }, false);
  }

  private async repeat(): Promise<void> {
    const ev = this.event();
    if (!ev) return;

    const frequency = await this.dialogs.menu({
      title: 'Repeat this night',
      subtitle: 'Each one is its own event with its own tickets, made as drafts.',
      actions: [
        { key: 'weekly', label: 'Every week', icon: CalendarClock },
        { key: 'fortnightly', label: 'Every two weeks', icon: CalendarClock },
        { key: 'monthly', label: 'Every month', icon: CalendarClock },
      ],
    });

    if (!frequency) return;

    const how = await this.dialogs.prompt({
      title: 'How many?',
      message: 'Leave it as it is to keep making them a few months ahead.',
      label: 'Nights to make',
      value: '6',
      inputmode: 'numeric',
      confirm: 'Make them',
    });

    if (how === null) return;

    await this.run(async () => {
      const count = Number.parseInt(how, 10);
      const result = await this.organizer.repeat(ev.id, frequency as 'weekly' | 'fortnightly' | 'monthly', Number.isFinite(count) && count > 0 ? count : undefined);
      this.toasts.show(`${result.created} ${result.created === 1 ? 'night' : 'nights'} made as drafts.`, 'success');
      await this.loadSeries();
    }, false);
  }

  private async cancel(): Promise<void> {
    const ev = this.event();
    if (!ev) return;

    this.working.set(true);
    let preview;

    try {
      preview = await this.organizer.cancellationPreview(ev.id);
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
      return;
    } finally {
      this.working.set(false);
    }

    const refund = preview.orders_to_refund > 0;
    const sure = await this.dialogs.confirm({
      title: 'Cancel this event?',
      message:
        `${preview.ticket_holders} ${preview.ticket_holders === 1 ? 'person holds' : 'people hold'} a ticket and will be told.` +
        (refund ? ` ${preview.orders_to_refund} ${preview.orders_to_refund === 1 ? 'order' : 'orders'} will be refunded, ${formatMoney(preview.refund_total)} in all.` : '') +
        ' This cannot be undone.',
      confirm: 'Cancel the event',
      cancel: 'Keep it',
      danger: true,
    });

    if (!sure) return;

    const reason = await this.dialogs.prompt({
      title: 'Why is it cancelled?',
      message: 'Ticket holders read this in the email that tells them.',
      label: 'Reason',
      placeholder: 'The venue has had to close',
      confirm: 'Cancel and tell them',
      multiline: true,
      required: true,
    });

    if (!reason) return;

    await this.run(async () => {
      const result = await this.organizer.cancel(ev.id, reason, refund);
      const failed = result.failed ? ` ${result.failed} refunds need a person — you will get an email.` : '';
      this.toasts.show(`Cancelled. ${result.notified} told, ${result.refunded} refunded.${failed}`, result.failed ? 'danger' : 'success');
    });
  }

  /** Do something to the event, then show it as it now is. */
  private async run(task: () => Promise<void>, reload = true): Promise<void> {
    this.working.set(true);

    try {
      await task();
      if (reload) await this.load();
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
    } finally {
      this.working.set(false);
    }
  }
}
