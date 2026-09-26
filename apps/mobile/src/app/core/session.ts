import { Injectable, computed, inject, signal } from '@angular/core';
import { Preferences } from '@capacitor/preferences';
import { Api } from './api';
import { HeldTicketStore } from './held-tickets';
import { Reminders } from './reminders';

const KEY = 'myfiesta.session';

export type Scope = 'attendee' | 'organizer' | 'door';

export interface Membership {
  id: string;
  name: string;
  role: string;
  permissions: string[];
}

export interface Session {
  scope: Scope;
  token: string;
  name: string;
  email: string | null;
  organizations: Membership[];

  /** Which of them the organizer screens are about. The first, until one is chosen. */
  activeOrganization?: string;

  /** Door sessions only: the one event this phone may scan, and until when. */
  eventId?: string;
  eventTitle?: string;
  doorLabel?: string;
  expiresAt?: string;
}

/**
 * Who is signed in, and what the server said they may do.
 *
 * One app carries attendee, organizer and door, so a door-staff phone has the
 * organizer screens inside it — hidden, but present. Hiding them is a
 * convenience for whoever is holding the phone; it is not the boundary. The
 * API is: a `door:{event_id}` token is refused everywhere except check-in for
 * its own event.
 *
 * The scope is read from the abilities the server granted, never from anything
 * the app asked for.
 */
@Injectable({ providedIn: 'root' })
export class SessionStore {
  private readonly api = inject(Api);
  private readonly reminders = inject(Reminders);
  private readonly held = inject(HeldTicketStore);

  private readonly state = signal<Session | null>(null);

  readonly session = this.state.asReadonly();
  readonly signedIn = computed(() => this.state() !== null);
  readonly scope = computed<Scope | null>(() => this.state()?.scope ?? null);

  /**
   * Door mode is a locked state, not a tab.
   *
   * Once a door pass is open there is no route to the rest of the app without
   * ending the shift. Venue staff are often handed a phone for one night; a
   * stray back-swipe must not put sales figures in front of them.
   */
  readonly locked = computed(() => this.scope() === 'door');
  readonly canSeeSales = computed(() => this.scope() === 'organizer');

  readonly organizations = computed(() => this.state()?.organizations ?? []);

  readonly organization = computed(() => {
    const session = this.state();

    if (!session) return null;

    return session.organizations.find((o) => o.id === session.activeOrganization) ?? session.organizations[0] ?? null;
  });

  /**
   * Whether this member may do this, in the organization being looked at.
   *
   * For hiding what would only be refused: a door-staff member of the team
   * does not need a Refund button that answers "not allowed". The API still
   * decides — this is courtesy, not the boundary.
   */
  can(permission: string): boolean {
    return this.organization()?.permissions.includes(permission) ?? false;
  }

  /** Look at another of the organizations this account belongs to. */
  async chooseOrganization(id: string): Promise<void> {
    const session = this.state();

    if (!session || !session.organizations.some((o) => o.id === id)) return;

    await this.save({ ...session, activeOrganization: id });
  }

  /**
   * Take in a fresh list of memberships — after accepting an invitation, or
   * when the phone has been away long enough for a role to change.
   */
  async refreshMemberships(organizations: Membership[]): Promise<void> {
    const session = this.state();

    if (!session) return;

    const active = organizations.some((o) => o.id === session.activeOrganization) ? session.activeOrganization : undefined;

    await this.save({ ...session, organizations, activeOrganization: active });
  }

  /**
   * Ask the server again what this account may do. After a role changes or an
   * organization is renamed, so the phone stops offering what would now be
   * refused — and starts offering what would not.
   */
  async sync(): Promise<void> {
    if (this.scope() !== 'organizer') return;

    try {
      const { organizations } = await this.api.me();
      await this.refreshMemberships(organizations);
    } catch {
      // Not worth interrupting anybody for: the next sign-in brings it anyway.
    }
  }

  async restore(): Promise<void> {
    try {
      const { value } = await Preferences.get({ key: KEY });

      if (!value) return;

      const session = JSON.parse(value) as Session;

      // A door pass expires with the night it was made for. Restoring one
      // afterwards is a scanner that refuses everything with no explanation.
      if (session.expiresAt && new Date(session.expiresAt).getTime() <= Date.now()) {
        await this.clear();

        return;
      }

      this.adopt(session);
    } catch {
      // Unreadable storage is a signed-out app, not a broken one.
    }
  }

  /** From a sign-in response: the scope is whatever the abilities say. */
  async startFromLogin(body: Record<string, unknown>): Promise<Session> {
    const abilities = (body['abilities'] as string[] | undefined) ?? [];
    const user = (body['user'] as { name?: string; email?: string } | undefined) ?? {};

    const session: Session = {
      // Organizer is the wider grant, so it wins when both are present.
      scope: abilities.includes('organizer') ? 'organizer' : 'attendee',
      token: String(body['token'] ?? ''),
      name: user.name ?? 'You',
      email: user.email ?? null,
      organizations: (body['organizations'] as Membership[] | undefined) ?? [],
    };

    await this.save(session);

    return session;
  }

  /** From a door link: one event, one phone, until the pass runs out. */
  async startFromDoorPass(pass: {
    token: string;
    label: string;
    expires_at: string;
    event: { id: string; title: string };
  }): Promise<Session> {
    const session: Session = {
      scope: 'door',
      token: pass.token,
      name: pass.label,
      email: null,
      organizations: [],
      eventId: pass.event.id,
      eventTitle: pass.event.title,
      doorLabel: pass.label,
      expiresAt: pass.expires_at,
    };

    await this.save(session);

    return session;
  }

  async signOut(): Promise<void> {
    await this.api.signOut();
    await this.clear();
  }

  /** Drop the session without telling the server — for a 401, where it already knows. */
  async clear(): Promise<void> {
    this.state.set(null);
    this.api.token = null;
    this.api.organization = null;
    // A phone handed back must not keep announcing somebody else's Saturday,
    // or hold a ticket that lets whoever has it now through a door.
    await this.reminders.clear();
    await this.held.forget();

    try {
      await Preferences.remove({ key: KEY });
    } catch {
      // Nothing to remove.
    }
  }

  private async save(session: Session): Promise<void> {
    this.adopt(session);

    try {
      await Preferences.set({ key: KEY, value: JSON.stringify(session) });
    } catch {
      // It holds for this run; the next start asks to sign in again.
    }
  }

  private adopt(session: Session): void {
    this.state.set(session);
    this.api.token = session.token;
    this.api.organization = this.organization()?.id ?? null;
  }
}
