import { Component, computed, inject, signal } from '@angular/core';
import { UiIcon, UiToasts, type LucideIconData } from '@myfiesta/ui';
import { CalendarDays, LayoutDashboard, Menu, X } from 'lucide-angular';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { Api } from './core/api';
import { SessionStore } from './core/session';

/** One entry in the sidebar. */
interface NavItem {
  readonly label: string;
  readonly link: string;
  readonly glyph: LucideIconData;
  /** Only `/` needs exact matching; everything else owns its subtree. */
  readonly exact?: boolean;
}

interface NavGroup {
  readonly title: string;
  readonly items: NavItem[];
}

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, UiToasts, UiIcon],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  readonly session = inject(SessionStore);

  protected readonly menuIcon = Menu;
  protected readonly closeIcon = X;

  /** The drawer, on a small screen. Closed on every navigation. */
  readonly navOpen = signal(false);

  constructor() {
    this.router.events.subscribe((event) => {
      if (event instanceof NavigationEnd) this.navOpen.set(false);
    });
  }

  /**
   * The sidebar, filtered by what this person may actually do.
   *
   * Hidden rather than disabled. A disabled link tells door staff there is a
   * money screen they cannot reach, which is information they did not need and
   * an invitation to ask why — and the server refuses those requests anyway,
   * so the link would only ever produce a 403.
   */
  readonly navigation = computed<NavGroup[]>(() => {
    const groups: NavGroup[] = [
      {
        title: 'Overview',
        items: [{ label: 'Dashboard', link: '/', glyph: LayoutDashboard, exact: true }],
      },
      {
        title: 'Programme',
        items: [{ label: 'Events', link: '/events', glyph: CalendarDays }],
      },
    ];

    // No Money section yet, deliberately.
    //
    // The settlement screens are not built, and a nav item pointing at a route
    // that does not exist falls through the wildcard to the events list —
    // which is worse than an absent link, because it looks like the console
    // ignored the click. It goes in when the screen does.

    return groups;
  });

  switchOrganization(event: Event): void {
    const id = (event.target as HTMLSelectElement).value;

    this.session.select(id);

    // Back to the top. Every screen below this is scoped to an organization,
    // and staying on one event's attendee list while switching to a different
    // organization asks for a 404 at best.
    void this.router.navigate(['/']);
  }

  signOut(): void {
    // The local session is cleared either way. A network failure must not
    // leave somebody stuck signed in on a shared machine.
    this.api.signOut().subscribe({
      next: () => this.finishSignOut(),
      error: () => this.finishSignOut(),
    });
  }

  private finishSignOut(): void {
    this.session.clear();
    void this.router.navigate(['/sign-in']);
  }
}
