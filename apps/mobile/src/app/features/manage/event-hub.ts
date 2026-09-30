import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Share } from '@capacitor/share';
import { Browser } from '@capacitor/browser';
import {
  BarChart3,
  Bell,
  CalendarClock,
  ClipboardList,
  Code,
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
  Send,
  Undo2,
  ScanLine,
  Share2,
  ShoppingBag,
  Ticket,
  TicketPercent,
  Users,
  XCircle,
} from 'lucide-angular';
import type { EventReviewStep, EventSummary, OrganizerEventDetail, Series, SeriesOccurrence } from '@myfiesta/api-types';
import { isoToZonedWallClock, zonedWallClockToIso } from '@myfiesta/shared/zoned-time';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { Discover } from '../../core/discovery';
import { formatMoney } from '../../core/money';
import { isEmailUnverified, messageOf } from '../../core/errors';
import { longEventTime, shortEventTime } from '../../core/event-time';
import { until } from '../../core/when';
import { EventContext } from './event-context';
import { eventStatusLabel, reviewStepLabel } from './event-review';
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
  MfSegmented,
  MfSheet,
  MfSkeleton,
  MfStat,
  ToastStore,
  type MenuAction,
  type MfSegment,
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
  imports: [MfScreen, MfIconButton, MfIcon, MfBadge, MfButton, MfCard, MfEmpty, MfField, MfList, MfRow, MfSegmented, MfSheet, MfSkeleton, MfStat],
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
                  @if (ev.off_sale_by_suspension) {
                    <mf-badge tone="danger">Off sale</mf-badge>
                  } @else {
                    <mf-badge tone="warning">Draft</mf-badge>
                  }
                }
                @case ('in_review') {
                  <mf-badge>In review</mf-badge>
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
          <!--
            Nothing goes on sale until somebody at myFiesta has looked at it.
            A draft is sent for review — or put straight back on sale when
            nothing has changed since it was approved, which the server says
            and the button repeats — and the reason it was last sent back
            stays at the top until it is sent again.
          -->
          @if (ev.status === 'draft' ? ev.review.rejection : null; as rejection) {
            <mf-card class="rejected" role="status">
              <p class="draft-title">myFiesta sent this back</p>
              <p class="reason">{{ rejection.reason }}</p>
              <p class="muted">Change what it says, then submit it for review again.</p>
            </mf-card>
          }

          <!--
            While the organization is suspended nothing of its goes on sale
            or into the queue, and the API refuses both. The screen used to
            offer "Put back on sale" all the same — on a night the suspension
            had taken off sale, with tickets sold — and the press was refused.
          -->
          @if (ev.status === 'draft' && ev.review.suspended) {
            <mf-card class="draft suspended" role="status">
              @if (ev.off_sale_by_suspension) {
                <p class="draft-title">Off sale while you are suspended</p>
                <p class="muted">
                  myFiesta took it off sale when it suspended this organization. It goes back on sale by itself when
                  the suspension is lifted, as long as nothing a buyer sees has changed. Tickets already sold still
                  get in.
                </p>
              } @else {
                <p class="draft-title">Not on sale yet</p>
                <p class="muted">Nothing can be sent for review while this organization is suspended.</p>
              }
            </mf-card>
          } @else if (ev.status === 'draft' && session.can('events.publish')) {
            <mf-card class="draft">
              <p class="draft-title">Not on sale yet</p>
              @if (ev.review.not_ready.length > 0) {
                <p class="muted">Before it can be sent for review:</p>
                <ul class="reasons">
                  @for (reason of ev.review.not_ready; track reason) {
                    <li>{{ reason }}</li>
                  }
                </ul>
              } @else if (ev.review.on_submit === 'publish') {
                <p class="muted">Nothing a buyer sees has changed since it was approved, so it goes straight back on sale.</p>
              } @else {
                <p class="muted">myFiesta looks at every event before it goes on sale, usually within a working day.</p>
              }
              <button mfButton block [loading]="working()" [disabled]="ev.review.not_ready.length > 0" (click)="submit()">
                {{ ev.review.on_submit === 'publish' ? 'Put back on sale' : 'Submit for review' }}
              </button>
            </mf-card>
          }

          @if (ev.status === 'in_review') {
            <mf-card class="review" role="status">
              <p class="draft-title">Waiting for review</p>
              <p class="muted">
                myFiesta is looking at it, usually within a working day, and emails you either way.
                It cannot be changed while it waits.
              </p>
              @if (session.can('events.publish')) {
                <button mfButton variant="secondary" block [loading]="working()" (click)="withdraw()">Withdraw from review</button>
              }
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
            <!-- Only once there is a page to share: a draft's or a held night's
                 link opens a 404, whatever the share sheet says about it. -->
            @if (ev.status === 'published') {
              <button mfButton variant="secondary" (click)="share()"><mf-icon [icon]="shareIcon" size="sm" /> Share</button>
            }
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
            <mf-row label="On your own website" sub="Two lines to paste, and people buy there" [icon]="embedIcon" action (pressed)="embedOpen.set(true)" />
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
                      <mf-badge>{{ statusLabel(date.status) }}</mf-badge>
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

          @if (ev.review.history.length > 0) {
            <mf-list class="block" heading="Review">
              @for (step of ev.review.history; track $index) {
                <mf-row [label]="stepLabel(step)" [sub]="stepSub(step)" [chevron]="false" />
              }
            </mf-list>
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

    <mf-sheet [open]="embedOpen()" heading="On your own website" [subheading]="event()?.status === 'published' ? 'Paste it where your site lets you. Payment opens in its own tab; everything else happens on your page.' : 'It starts working once the event is on sale.'" closable (closed)="embedOpen.set(false)">
      <mf-segmented ariaLabel="How it appears" [segments]="embedStyles" [(value)]="embedStyle" />
      <pre class="snippet">{{ snippet() }}</pre>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="copySnippet()"><mf-icon [icon]="copyIcon" size="sm" /> Copy</button>
        <button mfButton (click)="sendSnippet()"><mf-icon [icon]="shareIcon" size="sm" /> Send it</button>
      </ng-container>
    </mf-sheet>

    <mf-sheet [open]="duplicating()" heading="Duplicate this night" subheading="Tickets, prices and the banner come across. Extras, questions and sales do not." (closed)="duplicating.set(false)">
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
    .snippet {
      margin: var(--space-4) 0 0;
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-md);
      background: var(--surface-inset);
      font-family: var(--font-family-mono);
      font-size: var(--font-size-xs);
      line-height: 1.6;
      white-space: pre-wrap;
      overflow-wrap: anywhere;
      user-select: all;
    }

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

    .rejected {
      display: grid;
      gap: var(--space-2);
      margin-bottom: var(--space-4);
      border-left: 3px solid var(--warning);
      background: color-mix(in srgb, var(--warning) 12%, var(--surface-raised));
    }

    .rejected .reason {
      white-space: pre-line;
    }

    .review {
      display: grid;
      gap: var(--space-2);
      margin-bottom: var(--space-4);
      border-left: 3px solid var(--primary);
      background: color-mix(in srgb, var(--primary) 10%, var(--surface-raised));
    }

    .reasons {
      margin: 0;
      padding-left: var(--space-5);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
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
  protected readonly embedIcon = Code;
  protected readonly copyIcon = Copy;

  protected readonly embedOpen = signal(false);
  protected readonly embedStyle = signal<'button' | 'inline'>('button');
  protected readonly embedStyles: MfSegment[] = [
    { value: 'button', label: 'A button' },
    { value: 'inline', label: 'In the page' },
  ];

  /**
   * Two lines for the organizer's own site. A button that opens the tickets
   * over their page is the default: it fits every layout, and is still an
   * ordinary link if the script never loads.
   */
  protected readonly snippet = computed(() => {
    const slug = this.event()?.slug ?? '';
    const site = this.discover.siteBase();
    const script = `<script src="${site}/embed.js" async></script>`;

    return this.embedStyle() === 'button'
      ? `<a href="${site}/${slug}" data-myfiesta-event="${slug}" data-myfiesta-mode="button">Buy tickets</a>\n${script}`
      : `<div data-myfiesta-event="${slug}"></div>\n${script}`;
  });
  protected readonly stopIcon = XCircle;

  protected readonly cash = formatMoney;

  protected readonly statusLabel = eventStatusLabel;
  protected readonly stepLabel = reviewStepLabel;

  /** When, in the event's zone, and the reviewer's words when there are some. */
  protected stepSub(step: EventReviewStep): string {
    const at = shortEventTime(step.at, this.event()?.timezone ?? 'UTC');
    const by = step.by && step.action !== 'approved' && step.action !== 'rejected' ? ` · ${step.by}` : '';

    return step.reason ? `${at}${by} — “${step.reason}”` : `${at}${by}`;
  }

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

  protected async copySnippet(): Promise<void> {
    try {
      await navigator.clipboard.writeText(this.snippet());
      this.toasts.show('Copied. Paste it into your site.', 'success');
    } catch {
      this.toasts.show('Copying is blocked here. Send it instead.', 'danger');
    }
  }

  /** Usually to whoever runs the website. */
  protected async sendSnippet(): Promise<void> {
    try {
      await Share.share({ title: `Tickets for ${this.event()?.title ?? 'our event'} on our site`, text: this.snippet() });
    } catch {
      // Dismissed. The code is still on screen.
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

    const { confirmed, reason } = await this.dialogs.decide({
      title: `Skip ${this.occurrenceDate(date.starts_at)}?`,
      body: 'That date is taken out of the series and its page is deleted. It will not come back.',
      consequences: ['A date somebody already holds a ticket for cannot be skipped: refund them first.'],
      confirmLabel: 'Skip this date',
      tone: 'danger',
      reason: { label: 'Why, for the record', required: false, maxLength: 160, placeholder: 'The venue is closed that week' },
    });

    if (!confirmed) return;

    try {
      const { message } = await this.organizer.skipOccurrence(this.id(), date.id, reason || undefined);
      this.toasts.show(message, 'success');
      await this.loadSeries();
    } catch (error) {
      this.toasts.show(messageOf(error, 'That date could not be taken out.'), 'danger');
    }
  }

  protected async stopRepeating(): Promise<void> {
    const sure = await this.dialogs.confirm({
      title: `Stop repeating ${this.event()?.title ?? 'this night'}?`,
      body: 'No more dates are made, and future dates nobody has bought a ticket for are deleted.',
      consequences: ['Dates people have already bought tickets for are kept, exactly as they are.'],
      confirmLabel: 'Stop repeating',
      tone: 'danger',
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

    // The public page exists only while it is on sale. Handing out the link
    // to a draft sends everybody who taps it to "Event not found".
    if (ev.status !== 'published') {
      this.toasts.show('It is not public yet. The link works once it is on sale.', 'danger');
      return;
    }

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
    if (this.session.can('events.publish') && ev.status === 'draft' && !ev.review.suspended && ev.review.not_ready.length === 0) {
      items.push(
        ev.review.on_submit === 'publish'
          ? { key: 'publish', label: 'Put back on sale', icon: Eye, hint: 'Unchanged since it was approved' }
          : { key: 'publish', label: 'Submit for review', icon: Send, hint: 'myFiesta looks at it before it goes on sale' },
      );
    }
    if (this.session.can('events.publish') && ev.status === 'in_review') {
      items.push({ key: 'withdraw', label: 'Withdraw from review', icon: Undo2, hint: 'Back to a draft you can change' });
    }
    if (this.session.can('events.publish') && ev.status === 'published') {
      items.push({
        key: 'unpublish',
        label: 'Take it off sale',
        icon: EyeOff,
        hint: ev.review.unchanged_since_approval ? 'You can put it straight back' : 'Putting it back needs another review',
      });
    }
    // Both only while there is a public page to go to.
    if (ev.status === 'published') {
      items.push({ key: 'share', label: 'Share the link', icon: Share2 });
      items.push({ key: 'view', label: 'See the public page', icon: ExternalLink });
    }
    if (this.session.can('events.create')) {
      items.push({ key: 'duplicate', label: 'Duplicate', icon: Copy, hint: 'A new night with the same tickets' });
      // Once it repeats, its dates are managed under Repeats rather than started again.
      if (!cancelled && ev.status !== 'in_review' && !this.series()) items.push({ key: 'repeat', label: 'Repeat', icon: Repeat, hint: 'Weekly, fortnightly or monthly' });
    }
    if (this.session.can('door.scan') && !cancelled) items.push({ key: 'scan', label: 'Scan tickets', icon: ScanLine });
    if (this.session.can('events.cancel') && !cancelled) {
      // As the question after it says it: a draft, or a night in review,
      // usually has nobody holding a ticket, and then nobody to tell.
      //
      // It says nothing about refunds then. The count only takes tickets that
      // are valid or in, so a paid order whose one ticket is up for resale or
      // was voided is still refunded, and only the question after it, asked of
      // the orders themselves, can say so.
      const hint = ev.tickets_issued > 0 ? 'Tells every ticket holder, and can refund them' : 'Nobody holds a ticket yet, so nobody to tell';
      items.push({ key: 'cancel', label: 'Cancel the event', icon: XCircle, danger: true, hint });
    }

    const chosen = await this.dialogs.menu({ title: ev.title, subtitle: this.when(), actions: items });

    switch (chosen) {
      case 'edit':
        void this.router.navigate(this.here('edit'));
        break;
      case 'publish':
        await this.submit();
        break;
      case 'withdraw':
        await this.withdraw();
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

  /**
   * Send it to myFiesta, or straight back on sale.
   *
   * Which one is known before the sheet opens (`review.on_submit`), and the
   * sheet says it: an organizer who expects a review and finds the night on
   * sale, or the other way round, has been told something untrue.
   */
  protected async submit(): Promise<void> {
    const ev = this.event();
    if (!ev) return;

    const straightBack = ev.review.on_submit === 'publish';
    let message = '';

    const done = await this.dialogs.confirm(
      straightBack
        ? {
            title: `Put ${ev.title} back on sale?`,
            body: 'Nothing a buyer sees has changed since myFiesta approved it, so it goes back on sale straight away, without another review.',
            confirmLabel: 'Put back on sale',
            busyLabel: 'Putting it back…',
            tone: 'default',
            run: async () => (message = (await this.organizer.submitForReview(ev.id)).message),
          }
        : {
            title: `Send ${ev.title} for review?`,
            body: 'Somebody at myFiesta looks at every event before it goes on sale, usually within a working day. We email you when it is approved or if something needs changing.',
            consequences: [
              'While it is being reviewed you cannot change it: its details, tickets, extras, questions, pictures and codes are locked.',
              'Once it is approved it goes on sale straight away, and the people who follow you are told.',
              'You can withdraw it from review at any time to make a change.',
            ],
            confirmLabel: 'Submit for review',
            busyLabel: 'Sending…',
            tone: 'default',
            run: async () => (message = (await this.organizer.submitForReview(ev.id)).message),
          },
    );

    if (done) await this.afterReviewStep(message);
  }

  /** Take it back from review, to change something. */
  protected async withdraw(): Promise<void> {
    const ev = this.event();
    if (!ev) return;

    let message = '';

    const done = await this.dialogs.confirm({
      title: `Withdraw ${ev.title} from review?`,
      body: 'It goes back to a draft so you can change it. myFiesta stops looking at it until you send it again.',
      consequences: ['When you send it again, it waits for review from the start.'],
      confirmLabel: 'Withdraw from review',
      busyLabel: 'Withdrawing…',
      tone: 'default',
      run: async () => (message = (await this.organizer.withdrawFromReview(ev.id)).message),
    });

    if (done) await this.afterReviewStep(message);
  }

  /**
   * Take it off sale, saying first what putting it back would take.
   *
   * Edits made while it is on sale need no review, but they do mean it is no
   * longer what was approved — so the sheet says whether it could go straight
   * back, rather than leaving that to be found out later.
   */
  private async unpublish(): Promise<void> {
    const ev = this.event();
    if (!ev) return;

    let message = '';

    const done = await this.dialogs.confirm({
      title: `Take ${ev.title} off sale?`,
      body: 'The page stops showing and nobody new can buy. Tickets already sold still work at the door.',
      consequences: [
        ev.review.unchanged_since_approval
          ? 'Nothing a buyer sees has changed since myFiesta approved it, so you can put it straight back on sale — as long as that stays true.'
          : 'It has changed since myFiesta approved it, so putting it back on sale will need another review.',
      ],
      confirmLabel: 'Take it off sale',
      busyLabel: 'Taking it off sale…',
      tone: 'danger',
      run: async () => (message = (await this.organizer.publish(ev.id, 'draft')).message),
    });

    if (done) await this.afterReviewStep(message);
  }

  /** Say what happened, and show the event as it now is. */
  private async afterReviewStep(message: string): Promise<void> {
    if (message) this.toasts.show(message, 'success');
    this.context.forget(this.id());
    await this.load();
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

    // Only what EventDuplicator copies. An organizer told the extras came
    // across sends the copy for review without looking, and it goes on sale
    // with no tables to buy and no question asked.
    const title = this.copyTitle().trim() || ev.title;
    const sure = await this.dialogs.confirm({
      title: `Copy ${ev.title} to ${this.occurrenceDate(starts)}?`,
      body: `A new draft, ${title}, is made with the same details, tickets, prices, capacity, banner and reminders. Nobody can buy it until it is approved and on sale.`,
      consequences: [
        'Extras, questions, codes and the gallery are not copied. Add the extras and questions again if the new night needs them.',
        'Sales, orders and guests stay with this night.',
      ],
      confirmLabel: 'Make the copy',
      tone: 'default',
    });

    if (!sure || this.working()) return;

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

    // The count the server takes is every date in the series, this one
    // included (SeriesController: 2 to 104). Asked and said back that way, so
    // "6" is six nights on the calendar and five new events — not six new
    // ones the toast then calls five.
    const how = await this.dialogs.prompt({
      title: 'How many dates?',
      message: 'Counting this one. Leave it empty to keep adding dates six months ahead until you stop.',
      label: 'Dates in all',
      value: '6',
      inputmode: 'numeric',
      confirm: 'Next',
    });

    if (how === null) return;

    const count = how.trim() === '' ? null : Number(how.trim());

    if (count !== null && (!Number.isInteger(count) || count < 2 || count > 104)) {
      this.toasts.show('Repeat it for 2 to 104 dates, counting this one.', 'danger');
      return;
    }

    const every = { weekly: 'every week', fortnightly: 'every two weeks', monthly: 'every month' }[frequency] ?? frequency;
    const more = count === null ? 0 : count - 1;
    const last = new Date(ev.starts_at).getTime() + more * ({ weekly: 7, fortnightly: 14, monthly: 31 }[frequency] ?? 7) * 86_400_000;
    const sure = await this.dialogs.confirm({
      title: `Repeat ${ev.title} ${every}?`,
      body:
        count === null
          ? `New dates are added ${every}, six months ahead at a time, until you stop repeating it. Each is its own event with its own tickets and door.`
          : `${count} dates in all, counting this one: ${more} more ${more === 1 ? 'is' : 'are'} added, ${every}, each its own event with its own tickets and door.`,
      consequences: [
        // SeriesGenerator makes six months ahead at a time; the rest follow on schedule.
        ...(last - Date.now() > 180 * 86_400_000 ? ['Dates more than six months away are added as they come closer.'] : []),
        'Each new date is a draft until you submit it. A date that is this approved night, unchanged, goes straight on sale.',
      ],
      confirmLabel: count === null ? 'Repeat it' : `Add ${more} more ${more === 1 ? 'date' : 'dates'}`,
      tone: 'default',
    });

    if (!sure) return;

    await this.run(async () => {
      const result = await this.organizer.repeat(ev.id, frequency as 'weekly' | 'fortnightly' | 'monthly', count ?? undefined);
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
    let result = null as Awaited<ReturnType<Organizer['cancel']>> | null;

    // One question with everything in it: who is told, what is refunded, and
    // the words they are told in — for something that cannot be undone.
    //
    // The reason is held to what the server takes (10 to 500 characters), and
    // the cancel is sent from the sheet: a refusal is read in it with the
    // reason still written, rather than in a toast after it has closed.
    //
    // With nobody holding a ticket there is nobody to tell, and it says so
    // rather than "0 people hold a ticket and will be told".
    const nobody = preview.ticket_holders === 0;
    const { confirmed } = await this.dialogs.decide({
      title: `Cancel ${ev.title}?`,
      body: nobody
        ? 'Nobody holds a ticket yet, so there is nobody to tell.'
        : `${preview.ticket_holders} ${preview.ticket_holders === 1 ? 'person holds' : 'people hold'} a ticket and will be told.`,
      consequences: [
        ...(refund
          ? [`${preview.orders_to_refund} ${preview.orders_to_refund === 1 ? 'order is' : 'orders are'} refunded, ${formatMoney(preview.refund_total)} in all.`]
          : []),
        'This cannot be undone.',
      ],
      confirmLabel: nobody ? 'Cancel the event' : 'Cancel and tell them',
      cancelLabel: 'Keep it',
      tone: 'danger',
      reason: {
        label: 'Why is it cancelled?',
        required: true,
        minLength: 10,
        maxLength: 500,
        hint: nobody ? 'At least 10 characters.' : 'Ticket holders read this in the email that tells them. At least 10 characters.',
        placeholder: 'The venue has had to close',
      },
      busyLabel: 'Cancelling…',
      run: async (reason) => (result = await this.organizer.cancel(ev.id, reason ?? '', refund)),
    });

    if (!confirmed || !result) return;

    const failed = result.failed ? ` ${result.failed} refunds need a person — you will get an email.` : '';
    const told = nobody ? '' : ` ${result.notified} told, ${result.refunded} refunded.`;
    this.toasts.show(`Cancelled.${told}${failed}`, result.failed ? 'danger' : 'success');
    await this.load();
  }

  /** Do something to the event, then show it as it now is. */
  private async run(task: () => Promise<void>, reload = true): Promise<void> {
    this.working.set(true);

    try {
      await task();
      if (reload) await this.load();
    } catch (error) {
      // An unproved address is answered by the shell's prompt, which can
      // send the link again; a toast of the same words would only cover it.
      if (!isEmailUnverified(error)) this.toasts.show(messageOf(error), 'danger');
    } finally {
      this.working.set(false);
    }
  }
}
