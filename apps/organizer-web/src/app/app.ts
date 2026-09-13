import { Component, computed, inject, signal } from '@angular/core';
import { UiIcon, UiSelect, UiToasts, type LucideIconData, type SelectOption } from '@myfiesta/ui';
import { CalendarDays, LayoutDashboard, LogOut, Menu, PanelLeftClose, PanelLeftOpen, ReceiptText, Users, Wallet, X } from 'lucide-angular';
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

const COLLAPSED_KEY = 'myfiesta.console.sidebar-collapsed';

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

  protected readonly collapseIcon = PanelLeftClose;
  protected readonly expandIcon = PanelLeftOpen;
  protected readonly signOutIcon = LogOut;

  /** The drawer, on a small screen. Closed on every navigation. */
  readonly navOpen = signal(false);

  /**
   * The sidebar folded down to its icons, on a wide screen.
   *
   * For the screens that want the width — the orders table, the door, a long
   * attendee list. Remembered on this browser, because somebody who folds it
   * away wants it to stay folded. Labels stay in the page for screen readers
   * and appear as tooltips; a small screen keeps its drawer either way.
   */
  readonly collapsed = signal(App.restoreCollapsed());

  toggleCollapsed(): void {
    const next = !this.collapsed();
    this.collapsed.set(next);

    try {
      localStorage.setItem(COLLAPSED_KEY, next ? '1' : '0');
    } catch {
      // Private browsing: it folds for this visit and forgets.
    }
  }

  private static restoreCollapsed(): boolean {
    try {
      return typeof localStorage !== 'undefined' && localStorage.getItem(COLLAPSED_KEY) === '1';
    } catch {
      return false;
    }
  }

  /**
   * Screens that stand alone even for somebody signed in.
   *
   * A door pass works on anybody's phone, including one signed in to the
   * console — and the scanner it opens must look the same either way, with no
   * sidebar suggesting the pass reaches anything else.
   */
  // Read from the address at start too, so a reload does not draw the shell
  // for a moment and then rebuild the scanner without it.
  readonly bare = signal(App.isBare(typeof location === 'undefined' ? '/' : location.pathname));

  private static isBare(url: string): boolean {
    return /^\/(scan|door-pass)\//.test(url);
  }

  constructor() {
    this.router.events.subscribe((event) => {
      if (event instanceof NavigationEnd) {
        this.navOpen.set(false);
        this.bare.set(App.isBare(event.urlAfterRedirects));
      }
    });
  }

  /**
   * The sidebar, filtered by what this person may actually do.
   *
   * One list, no section headings: with six entries at most, "Overview",
   * "Programme" and "Money" were more words than there were things to find.
   *
   * Hidden rather than disabled. A disabled link tells door staff there is a
   * money screen they cannot reach, which is information they did not need and
   * an invitation to ask why — and the server refuses those requests anyway,
   * so the link would only ever produce a 403.
   */
  readonly navigation = computed<NavItem[]>(() => {
    const items: NavItem[] = [
      { label: 'Dashboard', link: '/', glyph: LayoutDashboard, exact: true },
      { label: 'Events', link: '/events', glyph: CalendarDays },
    ];

    if (this.session.canSeeMoney()) {
      items.push({ label: 'Orders', link: '/orders', glyph: ReceiptText }, { label: 'Payouts', link: '/payouts', glyph: Wallet });
    }

    // Owners only: a link that could only 403 is not shown.
    if (this.session.canManageTeam()) {
      items.push({ label: 'Team', link: '/team', glyph: Users });
    }

    return items;
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

  /** Two letters for the account button, which is all a folded sidebar has room for. */
  initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    const letters = (parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '');

    return letters.toUpperCase() || '?';
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
