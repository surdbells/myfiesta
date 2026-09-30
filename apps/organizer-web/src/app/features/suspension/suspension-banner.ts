import { Component, DestroyRef, computed, effect, inject, signal, untracked } from '@angular/core';
import { NavigationEnd, Router } from '@angular/router';
// Drawn by the shell: the kit a file at a time, as app.ts explains.
import { UiAlert } from '@myfiesta/ui/alert';
import { Api } from '../../core/api';
import type { OrganizationStanding } from '../../core/api.types';
import { SessionStore } from '../../core/session';

/** How stale the answer may get while somebody moves between screens. */
const REFRESH_MS = 60_000;

/**
 * "This organization is suspended", on every screen while it is.
 *
 * Mounted once in the shell, above whatever the page is. It says what has
 * stopped and, as plainly, what has not — the people who already bought still
 * get in, and refunds still work — because an organizer who finds their
 * publish button refused on the afternoon of a show needs to know tonight's
 * door is fine before anything else. The reason is shown only when myFiesta
 * chose to share it; how to reach support always is.
 *
 * Asked of the server rather than read from the sign-in, because a suspension
 * lands while people are signed in: when the organization being worked in
 * changes, and again on moving between screens once the last answer is a
 * minute old. Door staff and staff sessions see it too — it is the
 * organization's state, not the person's.
 */
@Component({
  selector: 'app-suspension-banner',
  imports: [UiAlert],
  template: `
    @if (suspension(); as suspended) {
      <div class="suspension-banner mb-6">
        <ui-alert tone="danger" [title]="suspended.name + ' is suspended'">
          <p class="text-pretty">
            myFiesta has suspended this organization{{ since() ? ' since ' + since() : '' }}. Its events are off sale,
            nothing can be sold or published, and payouts are paused — a payout already asked for is held, not
            rejected. Everybody who already has a ticket keeps it, your door still lets them in, and refunds can still
            be made.
          </p>
          @if (suspended.reason) {
            <p class="suspension-banner__reason mt-2 text-pretty">
              <span class="font-medium">Why:</span> {{ suspended.reason }}
            </p>
          }
          <p class="suspension-banner__contact mt-2 text-pretty">
            @if (suspended.support_email; as email) {
              To have it looked at, write to
              <a class="font-medium underline" [href]="'mailto:' + email">{{ email }}</a>
              or reply to the email we sent your owners.
            } @else {
              To have it looked at, reply to the email we sent your owners.
            }
          </p>
        </ui-alert>
      </div>
    }
  `,
})
export class SuspensionBanner {
  private readonly api = inject(Api);
  private readonly session = inject(SessionStore);

  readonly standing = signal<OrganizationStanding | null>(null);

  /** Which organization the answer was asked for, and when. */
  private askedFor: string | null = null;
  private askedAt = 0;

  /** Only for the organization on screen: an answer about the last one is not about this one. */
  readonly suspension = computed(() => {
    const standing = this.standing();
    const current = this.session.current();

    if (!standing?.suspended || !standing.suspension || !current || standing.organization.id !== current.id) {
      return null;
    }

    return { name: standing.organization.name, ...standing.suspension };
  });

  readonly since = computed(() => {
    const since = this.suspension()?.since;
    const date = since ? new Date(since) : null;

    return date && !Number.isNaN(date.getTime())
      ? date.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' })
      : null;
  });

  constructor() {
    effect(() => {
      const id = this.session.signedIn() ? (this.session.current()?.id ?? null) : null;

      untracked(() => this.ask(id, true));
    });

    const navigation = inject(Router).events.subscribe((event) => {
      if (event instanceof NavigationEnd) this.ask(this.session.current()?.id ?? null, false);
    });

    inject(DestroyRef).onDestroy(() => navigation.unsubscribe());
  }

  private ask(organizationId: string | null, force: boolean): void {
    if (organizationId === null) {
      this.standing.set(null);
      this.askedFor = null;
      return;
    }

    if (!force && organizationId === this.askedFor && Date.now() - this.askedAt < REFRESH_MS) return;

    this.askedFor = organizationId;
    this.askedAt = Date.now();

    this.api.standing().subscribe({
      next: (standing) => this.standing.set(standing),
      // Kept as it was. A banner that vanishes because one request failed
      // tells somebody the suspension is over when it is not.
      error: () => undefined,
    });
  }
}
