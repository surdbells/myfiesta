import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { ActivatedRoute, RouterLink, RouterOutlet } from '@angular/router';
import { UiBadge, UiBreadcrumb, UiIcon, UiTabs, type Crumb, type TabLink } from '@myfiesta/ui';
import { CalendarDays, MapPin } from 'lucide-angular';
import { Api } from '../../core/api';
import { OrganizerEventDetail } from '../../core/api.types';
import { longEventTime } from '../../core/event-time';
import { SessionStore } from '../../core/session';
import { SITE_URL } from '../../core/site-url';

/**
 * The frame every screen about one event sits inside.
 *
 * There was not one. `events/:id` was a 455-line page that showed some totals
 * and then a list of links to eight other pages, each of which loaded with no
 * indication of which event it belonged to, no way back except the browser,
 * and its own idea of what a heading looks like. Moving between the orders and
 * the guest list meant returning to a menu in between.
 *
 * So the event is loaded once, here, and stays on screen: its name, when it
 * is, whether it is live. The tabs are real router links rather than state, so
 * every one of them is a URL somebody can bookmark, reload, or send to the
 * person working the door — which is the actual reason a door screen exists.
 *
 * Tabs are filtered by permission for the same reason the sidebar is: door
 * staff being shown an Orders tab they cannot open is a question they have to
 * ask somebody rather than information they can use.
 */
@Component({
  selector: 'app-event-workspace',
  imports: [RouterOutlet, RouterLink, UiTabs, UiBadge, UiBreadcrumb, UiIcon],
  templateUrl: './event-workspace.html',
})
export class EventWorkspace {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  readonly session = inject(SessionStore);
  private readonly siteUrl = inject(SITE_URL);

  protected readonly whenIcon = CalendarDays;
  protected readonly whereIcon = MapPin;

  readonly eventId = this.route.snapshot.paramMap.get('id')!;

  readonly event = signal<OrganizerEventDetail | null>(null);
  readonly loading = signal(true);

  /**
   * The server could not be reached, as distinct from the event not existing.
   *
   * These were one state, and the difference matters most at a door: a phone
   * reloaded with no signal told whoever was working it that their event did
   * not exist, while the door beneath the header carried on scanning from its
   * saved list.
   */
  readonly unreachable = signal(false);

  /** Showing the copy this phone saved last time, because the server is out of reach. */
  readonly fromCache = signal(false);

  private retry: ReturnType<typeof setInterval> | null = null;
  private readonly destroyRef = inject(DestroyRef);

  constructor() {
    const saved = this.readCache();

    if (saved) {
      // Shown straight away and replaced when the server answers, so the
      // header is right even before the network has had its say.
      this.event.set(saved);
      this.loading.set(false);
      this.fromCache.set(true);
    }

    this.load();

    const onOnline = () => this.load();
    window.addEventListener('online', onOnline);

    this.destroyRef.onDestroy(() => {
      window.removeEventListener('online', onOnline);
      if (this.retry) clearInterval(this.retry);
    });
  }

  private load(): void {
    this.api.event(this.eventId).subscribe({
      next: (event) => {
        this.event.set(event);
        this.loading.set(false);
        this.unreachable.set(false);
        this.fromCache.set(false);
        this.writeCache(event);

        if (this.retry) {
          clearInterval(this.retry);
          this.retry = null;
        }
      },
      error: (error: HttpErrorResponse) => {
        this.loading.set(false);

        // 404 and 403 are the server answering. Anything else is the network,
        // and the event may well be fine.
        if (error.status === 404 || error.status === 403) {
          this.event.set(null);
          this.unreachable.set(false);

          return;
        }

        this.unreachable.set(true);
        this.retry ??= setInterval(() => this.load(), 20_000);
      },
    });
  }

  /**
   * The event's header, kept on this phone.
   *
   * localStorage rather than IndexedDB: one small object, read synchronously
   * so the header renders on the first frame instead of flashing "Loading…"
   * in a basement. Best-effort on both sides — private browsing, a full
   * store, or a browser blocking storage all simply mean no saved copy.
   */
  private readCache(): OrganizerEventDetail | null {
    try {
      const raw = localStorage.getItem(`myfiesta.event.${this.eventId}`);

      return raw ? (JSON.parse(raw) as OrganizerEventDetail) : null;
    } catch {
      return null;
    }
  }

  private writeCache(event: OrganizerEventDetail): void {
    try {
      localStorage.setItem(`myfiesta.event.${this.eventId}`, JSON.stringify(event));
    } catch {
      // No saved copy next time; nothing else depends on it.
    }
  }

  readonly crumbs = computed<Crumb[]>(() => [
    { label: 'Events', link: '/events' },
    { label: this.event()?.title ?? 'Event' },
  ]);

  /**
   * The tabs, in the order the work happens.
   *
   * Overview, then the two things that exist before the night (tickets and who
   * is coming), then the night itself, then the things that happen around it.
   * Settings last, because it is the one nobody opens twice.
   */
  readonly tabs = computed<TabLink[]>(() => {
    const base = ['/events', this.eventId];
    const tabs: TabLink[] = [{ label: 'Overview', route: base, exact: true }];

    if (this.session.canManageTickets()) {
      tabs.push({ label: 'Tickets', route: [...base, 'tickets'] });
    }

    if (this.session.canManageTickets()) {
      // Beside Tickets, because what is asked at checkout is part of what
      // is being sold.
      tabs.push({ label: 'Extras', route: [...base, 'extras'] });
      tabs.push({ label: 'Questions', route: [...base, 'questions'] });
    }

    if (this.session.canViewAttendees()) {
      tabs.push({ label: 'Attendees', route: [...base, 'guests'] });
    }

    if (this.session.canScan()) {
      tabs.push({ label: 'Door', route: [...base, 'door'] });
    }

    if (this.session.canSeeMoney()) {
      tabs.push({ label: 'Orders', route: [...base, 'orders'] });
    }

    if (this.session.canMessage()) {
      tabs.push({ label: 'Messages', route: [...base, 'messages'] });
    }

    if (this.session.canManageCodes()) {
      tabs.push({ label: 'Codes', route: [...base, 'codes'] });
    }

    if (this.session.canEditEvents()) {
      tabs.push({ label: 'Flyer & gallery', route: [...base, 'pictures'] });
      tabs.push({ label: 'Settings', route: [...base, 'edit'] });
    }

    return tabs;
  });

  readonly statusTone = computed(() => {
    const status = this.event()?.status;

    return status === 'draft' ? 'warning' : status === 'cancelled' ? 'danger' : 'success';
  });

  /** The page a buyer sees. Slug, not id — the id is not in a public URL. */
  publicUrl(event: OrganizerEventDetail): string {
    return `${this.siteUrl}/${event.slug}`;
  }

  when(event: OrganizerEventDetail): string {
    return longEventTime(event.starts_at, event.timezone);
  }
}
