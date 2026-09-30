import { Component, computed, inject, signal } from '@angular/core';
// The shell takes the kit a file at a time, never through @myfiesta/ui:
// whatever it imports is in the first download, and the index names the
// whole kit (packages/ui/package.json).
import { ConfirmDialog } from '@myfiesta/ui/confirm';
import { UiIcon, type LucideIconData } from '@myfiesta/ui/icon';
import { UiThemeToggle } from '@myfiesta/ui/theme';
import { UiToasts } from '@myfiesta/ui/toast';
// Used only inside the switcher's @defer block, so it loads after the shell:
// the dropdown brings all of @angular/forms with it, and most people belong to
// one organization and never see it. Name UiSelect anywhere else in this file
// and the compiler loads it up front again.
import { UiSelect, type SelectOption } from '@myfiesta/ui/select';
import { CalendarDays, LayoutDashboard, LogOut, Mail, Menu, Plug, PanelLeftClose, PanelLeftOpen, ReceiptText, Store, TicketPercent, Users, Wallet, X } from 'lucide-angular';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { Api } from './core/api';
import { SessionStore } from './core/session';
import { StaffBanner } from './features/impersonation/staff-banner';
import { SuspensionBanner } from './features/suspension/suspension-banner';
import { VerifyEmail } from './features/account/verify-email';
import { TermsPrompt } from './features/account/terms-prompt';

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
  imports: [RouterOutlet, RouterLink, RouterLinkActive, UiToasts, UiIcon, UiSelect, UiThemeToggle, StaffBanner, SuspensionBanner, VerifyEmail, TermsPrompt],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly confirmDialog = inject(ConfirmDialog);
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
    // A staff link lands bare too: this browser's own sign-in, if it has
    // one, must not draw for a moment before the staff session replaces it.
    return /^\/(scan|door-pass)\//.test(url) || /^\/impersonate(\/|$|[?#])/.test(url);
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

    if (this.session.canManageCodes()) {
      items.push({ label: 'Discount codes', link: '/codes', glyph: TicketPercent });
    }

    if (this.session.canMessage()) {
      items.push({ label: 'Campaigns', link: '/campaigns', glyph: Mail });
    }

    if (this.session.canSeeMoney()) {
      items.push({ label: 'Orders', link: '/orders', glyph: ReceiptText });
    }

    // Separately from orders: a support member of staff acting as the
    // organization sees its sales, not where its money goes.
    if (this.session.canSeePayouts()) {
      items.push({ label: 'Payouts', link: '/payouts', glyph: Wallet });
    }

    // Owners only: a link that could only 403 is not shown.
    if (this.session.canManageTeam()) {
      items.push({ label: 'Team', link: '/team', glyph: Users });
    }

    // Owners only, for the same reason — and because the list names where
    // buyers' details are being sent.
    if (this.session.canManageIntegrations()) {
      items.push({ label: 'Integrations', link: '/integrations', glyph: Plug });
    }

    // Everybody's, because everybody can read it — the screen says who may
    // change it.
    items.push({ label: 'How you appear', link: '/brand', glyph: Store });

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

  readonly signOutLabel = computed(() => (this.session.impersonation() ? 'End staff session' : 'Sign out'));

  async signOut(): Promise<void> {
    // A staff session is ended, not signed out of: the server revokes it and
    // records who ended it, and this tab goes to the page that says so
    // rather than to a sign-in form nobody here should use.
    const staff = this.session.impersonation() !== null;
    const organization = this.session.impersonation()?.organization.name ?? 'the organization';

    // Asked first, because the button sits at the bottom of the sidebar
    // where a missed click lands, and a staff session cannot be picked up
    // again without going back to the admin for a new one.
    const sure = await this.confirmDialog.confirm(
      staff
        ? {
            title: 'End the staff session?',
            body: `This tab stops acting as ${organization}, and the session is closed for good.`,
            consequences: ['Opening the console again takes a new session from the admin, with a new reason.'],
            confirmLabel: 'End staff session',
            tone: 'default',
          }
        : {
            title: 'Sign out?',
            body: 'You are signed out of the console in this browser, and need your email and password to get back in.',
            confirmLabel: 'Sign out',
            tone: 'default',
          },
    );

    if (!sure) return;

    const request = staff ? this.api.endImpersonation() : this.api.signOut();

    // The local session is cleared either way. A network failure must not
    // leave somebody stuck signed in on a shared machine.
    request.subscribe({
      next: () => this.finishSignOut(staff),
      error: () => this.finishSignOut(staff),
    });
  }

  private finishSignOut(staff = false): void {
    this.session.clear();
    void this.router.navigate(staff ? ['/impersonate/ended'] : ['/sign-in']);
  }
}
