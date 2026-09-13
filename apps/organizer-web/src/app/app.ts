import { Component, computed, inject, signal } from '@angular/core';
import { UiIcon, UiSelect, UiToasts, type LucideIconData, type SelectOption } from '@myfiesta/ui';
import { CalendarDays, LayoutDashboard, Menu, ReceiptText, Wallet, X } from 'lucide-angular';
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
  imports: [RouterOutlet, RouterLink, RouterLinkActive, UiToasts, UiIcon, UiSelect],
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

    // Money is a permission, and this is the one section where lacking it
    // means the screen is refused outright rather than served quieter — so
    // showing the link to somebody who cannot open it would be an invitation
    // to a 403.
    if (this.session.canSeeMoney()) {
      groups.push({
        title: 'Money',
        items: [
          { label: 'Orders', link: '/orders', glyph: ReceiptText },
          { label: 'Payouts', link: '/payouts', glyph: Wallet },
        ],
      });
    }

    return groups;
  });

  readonly organizationOptions = computed<SelectOption[]>(() =>
    this.session.organizations().map((org) => ({ value: org.id, label: org.name, hint: org.role })),
  );

  switchOrganization(id: string | null): void {
    if (!id || id === this.session.current()?.id) return;

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
