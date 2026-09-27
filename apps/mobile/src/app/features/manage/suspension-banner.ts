import { Component, DestroyRef, computed, effect, inject, output, signal, untracked } from '@angular/core';
import { NavigationEnd, Router } from '@angular/router';
import { ChevronDown, OctagonAlert } from 'lucide-angular';
import type { OrganizationStanding } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { MfIcon } from '../../ui';

/** How stale the answer may get while somebody moves between screens. */
const REFRESH_MS = 60_000;

/**
 * "This organization is suspended", along the top of every organizer screen
 * while it is — the console's banner, on a phone.
 *
 * The same words, in two lengths. Folded, it says the one thing somebody
 * finding their publish button refused on the afternoon of a show needs
 * before anything else: nothing new can be sold, and everybody who already
 * has a ticket still gets in. Unfolded, it is the console's message whole —
 * payouts held rather than rejected, refunds still made, the reason when
 * myFiesta chose to share it, and always how to reach support. The folded
 * form is what stays on screen, because a phone has no room for a paragraph
 * above every list.
 *
 * Asked of the server rather than read from the sign-in, because a
 * suspension lands while people are signed in: when the organization being
 * looked at changes, and again on moving between screens once the last
 * answer is a minute old. It is the organization's state, not the person's,
 * so every member sees it.
 */
@Component({
  selector: 'mf-suspension-banner',
  imports: [MfIcon],
  template: `
    @if (suspension(); as suspended) {
      <section class="strip" role="status" aria-label="Suspended">
        <button type="button" class="head" [attr.aria-expanded]="open()" (click)="open.set(!open())">
          <mf-icon class="mark" [icon]="markIcon" />
          <span class="summary">
            <strong>{{ suspended.name }} is suspended</strong>
            <span>Nothing new can be sold, and everybody with a ticket still gets in.</span>
          </span>
          <mf-icon class="chevron" [class.turned]="open()" [icon]="chevronIcon" />
        </button>

        @if (open()) {
          <div class="more">
            <p>
              myFiesta has suspended this organization{{ since() ? ' since ' + since() : '' }}. Its events are off sale,
              nothing can be sold or published, and payouts are paused — a payout already asked for is held, not
              rejected. Everybody who already has a ticket keeps it, your door still lets them in, and refunds can
              still be made.
            </p>
            @if (suspended.reason) {
              <p class="reason"><strong>Why:</strong> {{ suspended.reason }}</p>
            }
            <p class="contact">
              @if (suspended.support_email; as email) {
                To have it looked at, write to <a [href]="'mailto:' + email">{{ email }}</a> or reply to the email we
                sent your owners.
              } @else {
                To have it looked at, reply to the email we sent your owners.
              }
            </p>
          </div>
        }
      </section>
    }
  `,
  styles: `
    :host {
      display: block;
    }

    /* Under the status bar, since it is the top of the screen now. */
    .strip {
      padding-top: var(--mf-safe-top);
      background: color-mix(in srgb, var(--danger) 12%, var(--surface-sunken));
      box-shadow: inset 0 -1px 0 color-mix(in srgb, var(--danger) 35%, transparent);
      color: var(--text);
    }

    .head {
      display: flex;
      align-items: flex-start;
      gap: var(--space-3);
      width: 100%;
      min-height: var(--mf-tap);
      padding: var(--space-3) var(--space-4);
      border: 0;
      background: transparent;
      color: inherit;
      font: inherit;
      text-align: left;
      cursor: pointer;
    }

    .mark {
      margin-top: 2px;
      color: var(--danger-text);
    }

    .summary {
      display: grid;
      flex: 1;
      gap: 2px;
      min-width: 0;
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .summary strong {
      color: var(--text);
      font-size: var(--font-size-base);
    }

    .chevron {
      margin-top: 2px;
      color: var(--text-subtle);
      transition: transform 180ms ease;
    }

    .chevron.turned {
      transform: rotate(180deg);
    }

    /* Long enough to need a scroll on a small phone, never long enough to hide the screen. */
    .more {
      display: grid;
      gap: var(--space-2);
      max-height: 45dvh;
      overflow-y: auto;
      padding: 0 var(--space-4) var(--space-4) calc(var(--space-4) + 20px + var(--space-3));
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      overflow-wrap: anywhere;
    }

    .more p {
      margin: 0;
    }

    .more strong,
    .more a {
      color: var(--text);
      font-weight: var(--font-weight-medium);
    }
  `,
})
export class MfSuspensionBanner {
  private readonly organizer = inject(Organizer);
  private readonly session = inject(SessionStore);

  readonly standing = signal<OrganizationStanding | null>(null);
  readonly open = signal(false);

  protected readonly markIcon = OctagonAlert;
  protected readonly chevronIcon = ChevronDown;

  /** Which organization the answer was asked for, and when. */
  private askedFor: string | null = null;
  private askedAt = 0;

  /** Only for the organization on screen: an answer about the last one is not about this one. */
  readonly suspension = computed(() => {
    const standing = this.standing();
    const current = this.session.organization();

    if (!standing?.suspended || !standing.suspension || !current || standing.organization.id !== current.id) {
      return null;
    }

    return { name: standing.organization.name, ...standing.suspension };
  });

  /** Whether the strip is drawn, for the shell to make room for it. */
  readonly showing = output<boolean>();

  readonly since = computed(() => {
    const since = this.suspension()?.since;
    const date = since ? new Date(since) : null;

    return date && !Number.isNaN(date.getTime())
      ? date.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' })
      : null;
  });

  constructor() {
    effect(() => {
      const id = this.session.canSeeSales() ? (this.session.organization()?.id ?? null) : null;

      untracked(() => void this.ask(id, true));
    });

    effect(() => {
      const showing = this.suspension() !== null;

      untracked(() => this.showing.emit(showing));
    });

    const navigation = inject(Router).events.subscribe((event) => {
      if (event instanceof NavigationEnd) void this.ask(this.session.organization()?.id ?? null, false);
    });

    inject(DestroyRef).onDestroy(() => navigation.unsubscribe());
  }

  private async ask(organizationId: string | null, force: boolean): Promise<void> {
    if (organizationId === null) {
      this.standing.set(null);
      this.askedFor = null;
      return;
    }

    if (!force && organizationId === this.askedFor && Date.now() - this.askedAt < REFRESH_MS) return;

    // Folded again for somebody who has moved to another organization.
    if (organizationId !== this.askedFor) this.open.set(false);

    this.askedFor = organizationId;
    this.askedAt = Date.now();

    try {
      const standing = await this.organizer.standing();

      // An answer that arrives after somebody switched is about the last
      // organization, and must not replace the one about this one.
      if (standing.organization.id === this.askedFor) this.standing.set(standing);
    } catch {
      // Kept as it was. A strip that vanishes because one request failed
      // tells somebody the suspension is over when it is not.
    }
  }
}
