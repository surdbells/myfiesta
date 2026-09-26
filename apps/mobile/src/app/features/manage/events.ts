import { Component, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Plus } from 'lucide-angular';
import type { OrganizerEvent, PageMeta } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { messageOf } from '../../core/errors';
import { MfButton, MfCard, MfEmpty, MfIconButton, MfScreen, MfSegmented, MfSkeleton, type MfSegment } from '../../ui';
import { MfEventRow } from './event-row';

/**
 * Every night, upcoming or past, each paged on its own.
 *
 * Upcoming soonest first, past most recent first — the two orders somebody
 * actually looks in. More loads at the bottom rather than behind page
 * numbers, which are a desk thing.
 */
@Component({
  selector: 'mf-manage-events',
  imports: [MfScreen, MfIconButton, MfSegmented, MfEventRow, MfButton, MfCard, MfEmpty, MfSkeleton],
  template: `
    <mf-screen title="Events" [subtitle]="session.organization()?.name ?? null" back backTo="/manage" refreshable [busy]="loading()" (refresh)="reload()">
      @if (session.can('events.create')) {
        <button mfIconButton screenActions tone="tonal" [icon]="plusIcon" label="New event" (click)="router.navigate(['/manage/events/new'])"></button>
      }

      <mf-segmented screenBar ariaLabel="Which events" [segments]="segments" [value]="when()" (valueChange)="choose($any($event))" />

      @if (error(); as message) {
        <mf-empty title="Could not load your events" [hint]="message">
          <button mfButton variant="secondary" (click)="reload()">Try again</button>
        </mf-empty>
      } @else if (events().length === 0 && loading()) {
        <div class="stack">
          @for (n of [0, 1, 2]; track n) {
            <mf-card quiet><mf-skeleton height="4.5rem" /></mf-card>
          }
        </div>
      } @else if (events().length === 0) {
        <mf-empty
          [title]="when() === 'upcoming' ? 'Nothing coming up' : 'Nothing yet'"
          [hint]="when() === 'upcoming' ? 'A night you create shows up here, draft or on sale.' : 'Nights that have happened are kept here with what they took.'"
        >
          @if (when() === 'upcoming' && session.can('events.create')) {
            <button mfButton (click)="router.navigate(['/manage/events/new'])">Put on an event</button>
          }
        </mf-empty>
      } @else {
        <div class="stack">
          @for (event of events(); track event.id) {
            <mf-event-row [event]="event" />
          }
        </div>

        @if (hasMore()) {
          <button mfButton class="more" variant="secondary" block [loading]="loading()" (click)="more()">Show more</button>
        }
      }
    </mf-screen>
  `,
  styles: `
    .stack {
      display: grid;
      gap: var(--space-3);
    }

    .more {
      margin-top: var(--space-5);
    }
  `,
})
export class ManageEvents {
  protected readonly session = inject(SessionStore);
  protected readonly router = inject(Router);
  private readonly organizer = inject(Organizer);

  protected readonly plusIcon = Plus;

  protected readonly segments: MfSegment[] = [
    { value: 'upcoming', label: 'Upcoming' },
    { value: 'past', label: 'Past' },
  ];

  protected readonly when = signal<'upcoming' | 'past'>('upcoming');
  protected readonly events = signal<OrganizerEvent[]>([]);
  protected readonly meta = signal<PageMeta | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly hasMore = () => {
    const meta = this.meta();

    return !!meta && meta.current_page < meta.last_page;
  };

  constructor() {
    void this.reload();
  }

  protected choose(when: 'upcoming' | 'past'): void {
    this.when.set(when);
    void this.reload();
  }

  async reload(): Promise<void> {
    this.events.set([]);
    await this.fetch(1);
  }

  protected more(): void {
    void this.fetch((this.meta()?.current_page ?? 1) + 1);
  }

  private async fetch(page: number): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      const result = await this.organizer.events(this.when(), page);

      this.events.update((rows) => (page === 1 ? result.data : [...rows, ...result.data]));
      this.meta.set(result.meta);
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }
}
