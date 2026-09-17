import { Component, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Discover } from '../../core/discovery';
import { MfBadge, MfButton, MfCard, MfEmpty, MfScreen, MfSkeleton, ToastStore } from '../../ui';

interface Followed {
  slug: string;
  name: string;
  is_verified?: boolean;
}

/**
 * Organizers somebody follows.
 *
 * Until now the only way to stop was the link at the bottom of an
 * announcement email, which means digging through a mailbox to undo something
 * done in the app. The list belongs where the following was decided.
 *
 * Leaving is answered on the phone first and put back if the server disagrees,
 * like every other one-tap decision in here.
 */
@Component({
  selector: 'mf-following',
  imports: [MfScreen, MfCard, MfButton, MfBadge, MfEmpty, MfSkeleton],
  template: `
    <mf-screen title="Following" back (backed)="back()">
      @if (loading()) {
        <mf-skeleton height="4rem" />
        <mf-skeleton class="mt" height="4rem" />
      } @else if (failed(); as message) {
        <mf-empty title="Could not load who you follow" [hint]="message" />
      } @else if (organizers().length === 0) {
        <mf-empty
          title="Not following anyone yet"
          hint="Follow an organizer on their event and you will hear when they announce a night."
        />
      } @else {
        <ul class="stack">
          @for (organizer of organizers(); track organizer.slug) {
            <li>
              <!-- A name in this list used to be a name and nothing else.
                   Tapping it opens their page; the button is still the way
                   out. -->
              <mf-card quiet tappable (click)="open(organizer)">
                <div class="row">
                  <span class="who">
                    {{ organizer.name }}
                    @if (organizer.is_verified) {
                      <mf-badge tone="success">Verified</mf-badge>
                    }
                  </span>

                  <button
                    mfButton
                    size="sm"
                    variant="secondary"
                    [disabled]="leaving() === organizer.slug"
                    (click)="$event.stopPropagation(); stop(organizer)"
                  >
                    Following
                  </button>
                </div>
              </mf-card>
            </li>
          }
        </ul>
      }
    </mf-screen>
  `,
  styles: `
    .stack {
      display: grid;
      gap: var(--space-3);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .stack li {
      min-width: 0;
    }

    .row {
      display: flex;
      align-items: center;
      gap: var(--space-4);
    }

    .who {
      flex: 1;
      min-width: 0;
      display: flex;
      align-items: center;
      gap: var(--space-2);
      font-size: var(--font-size-lg);
      font-weight: var(--font-weight-semibold);
      color: var(--text);
    }

    .mt {
      margin-top: var(--space-3);
    }
  `,
})
export class Following {
  private readonly discover = inject(Discover);
  private readonly toasts = inject(ToastStore);
  private readonly router = inject(Router);

  readonly organizers = signal<Followed[]>([]);
  readonly loading = signal(true);
  readonly failed = signal<string | null>(null);

  /** The one being let go of, so its button can stop taking taps. */
  readonly leaving = signal<string | null>(null);

  constructor() {
    queueMicrotask(() => void this.load());
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.failed.set(null);

    try {
      const { data } = await this.discover.following();

      this.organizers.set(data);
    } catch (error) {
      this.failed.set(error instanceof Error ? error.message : 'Something went wrong.');
    } finally {
      this.loading.set(false);
    }
  }

  async stop(organizer: Followed): Promise<void> {
    if (this.leaving()) return;

    const before = this.organizers();
    this.leaving.set(organizer.slug);
    this.organizers.set(before.filter((row) => row.slug !== organizer.slug));

    try {
      await this.discover.follow(organizer.slug, false);
      this.toasts.show(`You no longer follow ${organizer.name}.`);
    } catch {
      this.organizers.set(before);
      this.toasts.show('Could not do that. Try again.', 'danger');
    } finally {
      this.leaving.set(null);
    }
  }

  open(organizer: Followed): void {
    void this.router.navigate(['/o', organizer.slug]);
  }

  back(): void {
    void this.router.navigate(['/settings']);
  }
}
