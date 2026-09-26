import { Injectable, inject } from '@angular/core';
import type { OrganizerEventDetail } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';

/**
 * The event every screen under /manage/events/:id is about.
 *
 * Each of those screens needs the night's title for its bar and its currency
 * and zone for its forms. Fetching it again on every one is a spinner before
 * each screen for data the screen before already had, so the last one fetched
 * is kept for a minute — long enough to move between an event's tools, short
 * enough that a stale title is never shown for long.
 */
@Injectable({ providedIn: 'root' })
export class EventContext {
  private readonly organizer = inject(Organizer);
  private held: { event: OrganizerEventDetail; at: number } | null = null;

  /** What is known about this event already, without asking. */
  peek(id: string): OrganizerEventDetail | null {
    return this.held?.event.id === id ? this.held.event : null;
  }

  remember(event: OrganizerEventDetail): void {
    this.held = { event, at: Date.now() };
  }

  async get(id: string, fresh = false): Promise<OrganizerEventDetail> {
    if (!fresh && this.held?.event.id === id && Date.now() - this.held.at < 60_000) return this.held.event;

    const event = await this.organizer.event(id);
    this.remember(event);

    return event;
  }

  /** Something changed it: the next screen asks again. */
  forget(id: string): void {
    if (this.held?.event.id === id) this.held = null;
  }
}
