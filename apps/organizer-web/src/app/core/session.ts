import { Injectable, computed, inject, signal } from '@angular/core';
import { DOCUMENT } from '@angular/common';
import { Membership, Permission, Session } from './api.types';

const STORAGE_KEY = 'myfiesta.organizer.session';

/**
 * Who is signed in, and which organization they are working in.
 *
 * The token lives in localStorage rather than a cookie, because the console and
 * the API are separate hosts and the token travels in an Authorization header.
 * That means no cookie is sent cross-origin and CSRF has nothing to act on —
 * but it is readable by any script on this origin, so the console must not
 * render untrusted HTML. Angular's default escaping is what holds that line.
 *
 * A stored token is a claim, never a fact. It is presented to the API and the
 * API decides; nothing here grants access on the strength of what is in
 * storage. The platform this replaces treated a token as proof by itself.
 */
@Injectable({ providedIn: 'root' })
export class SessionStore {
  private readonly document = inject(DOCUMENT);

  private readonly state = signal<Session | null>(this.restore());

  readonly session = this.state.asReadonly();
  readonly signedIn = computed(() => this.state() !== null);
  readonly user = computed(() => this.state()?.user ?? null);
  readonly organizations = computed<Membership[]>(() => this.state()?.organizations ?? []);

  /**
   * The organization being worked in.
   *
   * Most people belong to one. Those who belong to several — an agency running
   * several venues — pick, and the choice is remembered.
   */
  private readonly selectedId = signal<string | null>(this.restoreSelection());

  readonly current = computed<Membership | null>(() => {
    const orgs = this.organizations();
    if (orgs.length === 0) return null;

    return orgs.find((o) => o.id === this.selectedId()) ?? orgs[0];
  });

  /**
   * What this member may do here, as decided by the server.
   *
   * This used to derive capability from the role, in parallel with the policies
   * on the API — and the two had drifted. The API granted sales figures to
   * Owner, Manager and Finance; this file granted them to owner and finance, so
   * a Manager saw no revenue on an event they were entitled to. Nothing tested
   * the two against each other, so it was silent.
   *
   * The server now sends the resolved permission list with the session. This
   * checks membership of that list and knows nothing about what a role implies.
   * There is no second copy left to drift.
   */
  readonly permissions = computed<string[]>(() => this.current()?.permissions ?? []);

  can(permission: Permission): boolean {
    return this.permissions().includes(permission);
  }

  /*
   * Named capabilities, for readability at the call site.
   *
   * Each is a lookup, never a rule. Adding one here without the server granting
   * it changes nothing, which is the property worth having.
   */
  readonly canEditEvents = computed(() => this.can('events.edit'));
  readonly canSeeMoney = computed(() => this.can('money.view'));
  readonly canMessage = computed(() => this.can('messages.send'));
  readonly canManageCodes = computed(() => this.can('codes.manage'));
  readonly canManageTickets = computed(() => this.can('tickets.manage'));
  readonly canViewAttendees = computed(() => this.can('attendees.view'));
  readonly canScan = computed(() => this.can('door.scan'));
  readonly canRefund = computed(() => this.can('refunds.process'));
  readonly canPublish = computed(() => this.can('events.publish'));
  readonly canCancel = computed(() => this.can('events.cancel'));
  readonly canManageTeam = computed(() => this.can('team.manage'));

  get token(): string | null {
    return this.state()?.token ?? null;
  }

  start(session: Session): void {
    this.state.set(session);
    this.persist(session);
  }

  /** The person's own details changed; the token and memberships did not. */
  updateUser(user: Session['user']): void {
    const session = this.state();
    if (!session) return;

    this.start({ ...session, user });
  }

  select(organizationId: string): void {
    this.selectedId.set(organizationId);
    this.storage?.setItem(`${STORAGE_KEY}.org`, organizationId);
  }

  /**
   * Forget everything.
   *
   * Called on sign-out and whenever the API answers 401 — a token the server
   * no longer accepts is not worth keeping, and holding it produces a console
   * that looks signed in and fails on every action.
   */
  clear(): void {
    this.state.set(null);
    this.selectedId.set(null);
    this.storage?.removeItem(STORAGE_KEY);
    this.storage?.removeItem(`${STORAGE_KEY}.org`);
  }

  private get storage(): Storage | null {
    // Private browsing can throw on access rather than return null.
    try {
      return this.document.defaultView?.localStorage ?? null;
    } catch {
      return null;
    }
  }

  private persist(session: Session): void {
    try {
      this.storage?.setItem(STORAGE_KEY, JSON.stringify(session));
    } catch {
      // Storage full or blocked. The session still works for this tab; it
      // just will not survive a reload, which is better than refusing to
      // sign in at all.
    }
  }

  private restore(): Session | null {
    try {
      const raw = this.storage?.getItem(STORAGE_KEY);

      if (!raw) return null;

      const session = JSON.parse(raw) as Session;

      /*
       * A session stored before the server started sending permissions has no
       * list to read, and an absent list resolves to "may do nothing" — a
       * console that looks signed in and offers no actions at all.
       *
       * Discarded rather than patched up. Signing in again is a small cost and
       * produces a session in the shape the rest of this file assumes; guessing
       * the permissions locally would reintroduce exactly the duplicated rule
       * this change removed.
       */
      const complete = session.organizations.every((o) => Array.isArray(o.permissions));

      return complete ? session : null;
    } catch {
      return null;
    }
  }

  private restoreSelection(): string | null {
    try {
      return this.storage?.getItem(`${STORAGE_KEY}.org`) ?? null;
    } catch {
      return null;
    }
  }
}
