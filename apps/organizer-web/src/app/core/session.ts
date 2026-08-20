import { Injectable, computed, inject, signal } from '@angular/core';
import { DOCUMENT } from '@angular/common';
import { Membership, Session } from './api.types';

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

  /** What this member may do here. Mirrors the policies the API enforces. */
  readonly canEditEvents = computed(() => this.hasRole('owner', 'manager'));
  readonly canSeeMoney = computed(() => this.hasRole('owner', 'finance'));
  // Reaching attendees, and the codes that bring them in. Marketing is here and
  // nowhere else — writing a promo code is their job, minting tickets is not.
  readonly canMessage = computed(() => this.hasRole('owner', 'manager', 'marketing'));

  private hasRole(...roles: Membership['role'][]): boolean {
    const role = this.current()?.role;

    return role !== undefined && roles.includes(role);
  }

  get token(): string | null {
    return this.state()?.token ?? null;
  }

  start(session: Session): void {
    this.state.set(session);
    this.persist(session);
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

      return raw ? (JSON.parse(raw) as Session) : null;
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
