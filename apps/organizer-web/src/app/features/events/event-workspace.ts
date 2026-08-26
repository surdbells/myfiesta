import { Component, computed, inject, signal } from '@angular/core';
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
  styleUrl: './event-workspace.css',
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

  constructor() {
    this.api.event(this.eventId).subscribe({
      next: (event) => {
        this.event.set(event);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
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
