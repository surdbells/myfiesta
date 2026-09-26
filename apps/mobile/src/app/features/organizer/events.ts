import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Api, ApiError, OrganizerEvent } from '../../core/api';
import { SessionStore } from '../../core/session';
import { shortEventTime } from '../../core/event-time';
import { MfBadge, MfButton, MfCard, MfEmpty, MfScreen, MfSegmented, MfSkeleton, type MfSegment } from '../../ui';

/**
 * An organizer's nights, on the phone they carry to them.
 *
 * Tonight first. The web console sorts by date because somebody there is
 * planning; whoever opens this is usually standing in the venue, so the event
 * happening now is the one on top and the rest are history below it.
 */
@Component({
  selector: 'mf-events',
  imports: [MfScreen, MfCard, MfBadge, MfEmpty, MfSkeleton, MfSegmented, MfButton],
  template: `
    <mf-screen title="Your events" [subtitle]="session.organization()?.name ?? null" large>
      <button mfButton variant="ghost" size="sm" screenActions (click)="settings()">Settings</button>

      <mf-segmented class="tabs" [segments]="tabs" [(value)]="tab" ariaLabel="Which events" />

      @if (loading()) {
        <div class="stack">
          @for (n of [0, 1, 2]; track n) {
            <mf-card><mf-skeleton height="3.5rem" /></mf-card>
          }
        </div>
      } @else if (failed(); as message) {
        <mf-empty title="Could not load your events" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (shown().length === 0) {
        <mf-empty
          [title]="tab() === 'upcoming' ? 'Nothing coming up' : 'Nothing past yet'"
          hint="Events you create in the console appear here."
        />
      } @else {
        <ul class="stack">
          @for (event of shown(); track event.id) {
            <li>
              <mf-card tappable (click)="open(event)">
                <p class="when">{{ when(event) }}</p>
                <h2>{{ event.title }}</h2>
                <p class="muted where">{{ event.city }}</p>

                <div class="row">
                  <span class="count tabular">{{ event.checked_in }}/{{ event.tickets_issued }} in</span>
                  @if (rate(event); as percent) {
                    <span class="meter" role="img" [attr.aria-label]="percent + '% arrived'">
                      <span class="fill" [style.width.%]="percent"></span>
                    </span>
                  }
                  <mf-badge [tone]="event.status === 'published' ? 'success' : 'neutral'">
                    {{ event.status }}
                  </mf-badge>
                </div>
              </mf-card>
            </li>
          }
        </ul>
      }
    </mf-screen>
  `,
  styles: `
    .tabs {
      display: block;
      margin-bottom: var(--space-4);
    }

    .stack {
      display: grid;
      gap: var(--space-3);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .when {
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-semibold);
      color: var(--primary-text);
    }

    .where {
      font-size: var(--font-size-sm);
    }

    .row {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      margin-top: var(--space-3);
    }

    .count {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .meter {
      flex: 1;
      height: 6px;
      border-radius: var(--radius-full);
      background: var(--surface-inset);
      overflow: hidden;
    }

    .fill {
      display: block;
      height: 100%;
      border-radius: var(--radius-full);
      background: var(--primary);
    }
  `,
})
export class Events {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  readonly session = inject(SessionStore);

  readonly events = signal<OrganizerEvent[]>([]);
  readonly loading = signal(true);
  readonly failed = signal<string | null>(null);

  readonly tabs: MfSegment[] = [
    { value: 'upcoming', label: 'Upcoming' },
    { value: 'past', label: 'Past' },
  ];

  readonly tab = signal('upcoming');

  readonly shown = computed(() => {
    const now = Date.now();
    const upcoming = this.tab() === 'upcoming';

    return this.events()
      .filter((event) => (new Date(event.starts_at).getTime() >= now) === upcoming)
      .sort((a, b) => {
        const left = new Date(a.starts_at).getTime();
        const right = new Date(b.starts_at).getTime();

        return upcoming ? left - right : right - left;
      });
  });

  constructor() {
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.failed.set(null);

    try {
      this.events.set(await this.api.events(this.session.organization()?.id ?? null));
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        await this.session.clear();
        await this.router.navigate(['/sign-in'], { replaceUrl: true });

        return;
      }

      this.failed.set(error instanceof ApiError ? error.message : 'Something went wrong.');
    } finally {
      this.loading.set(false);
    }
  }

  when(event: OrganizerEvent): string {
    return shortEventTime(event.starts_at, event.timezone);
  }

  /** Null rather than a confident zero when nothing has sold. */
  rate(event: OrganizerEvent): number | null {
    if (event.tickets_issued === 0) return null;

    return Math.round((event.checked_in / event.tickets_issued) * 100);
  }

  open(event: OrganizerEvent): void {
    void this.router.navigate(['/events', event.id]);
  }

  settings(): void {
    void this.router.navigate(['/settings']);
  }
}
