import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import {
  UiButton,
  UiEmpty,
  UiErrorState,
  UiPageHeader,
  UiIcon,
  UiSkeleton,
} from '@myfiesta/ui';
import { PencilLine, Plus, Ticket, TrendingUp, Wallet } from 'lucide-angular';
import { Api } from '../../core/api';
import { Money, NextEvent, Overview } from '../../core/api.types';
import { formatMoney } from '../../core/money';
import { SessionStore } from '../../core/session';

/**
 * The screen the console opens on.
 *
 * It opened on a list of events, which answers "what have I got on" and none
 * of the questions somebody actually signs in with: am I owed anything, is the
 * next one selling, and have I forgotten something.
 *
 * So the order here is deliberate and is not the order of a report. What needs
 * doing comes first, then the money, then the next event. An organizer whose
 * event is published with nothing on sale is losing money every hour it stays
 * that way, and that belongs above the takings rather than below them.
 */
@Component({
  selector: 'app-dashboard',
  imports: [RouterLink, UiPageHeader, UiButton, UiSkeleton, UiEmpty, UiErrorState, UiIcon],
  templateUrl: './dashboard.html',
})
export class Dashboard {
  protected readonly addIcon = Plus;
  protected readonly walletIcon = Wallet;
  protected readonly soldIcon = TrendingUp;
  protected readonly ticketIcon = Ticket;
  protected readonly draftIcon = PencilLine;

  private readonly api = inject(Api);
  readonly session = inject(SessionStore);

  readonly overview = signal<Overview | null>(null);
  readonly loading = signal(true);
  readonly error = signal(false);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(false);

    this.api.overview().subscribe({
      next: (overview) => {
        this.overview.set(overview);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set(true);
      },
    });
  }

  /**
   * The time of day, in the greeting.
   *
   * Trivial, and it is the one thing on the page that says this was written
   * for a person rather than generated for a record. An organizer opens this
   * at 2am after a door and at 11am before a launch.
   */
  readonly greeting = computed(() => {
    const name = this.session.user()?.name?.split(' ')[0];
    const hour = new Date().getHours();

    const time = hour < 5 ? 'Still up' : hour < 12 ? 'Morning' : hour < 18 ? 'Afternoon' : 'Evening';

    return name ? `${time}, ${name}` : time;
  });

  cash(money: Money): string {
    return formatMoney(money);
  }

  when(event: NextEvent): string {
    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
      // The event's own zone. An organizer running Toronto and Lagos nights
      // needs each one to say the time it starts where it starts.
      timeZone: event.timezone,
    }).format(new Date(event.starts_at));
  }

  /**
   * Days until the doors.
   *
   * Rounded up, and never below zero. An event starting in six hours is "1
   * day", not "0" — zero reads as cancelled.
   */
  daysAway(event: NextEvent): number {
    const ms = new Date(event.starts_at).getTime() - Date.now();

    return Math.max(1, Math.ceil(ms / 86_400_000));
  }

  /** Capped at 100: comps and guest lists can push issued past capacity. */
  soldPercent(event: NextEvent): number {
    if (!event.capacity) return 0;

    return Math.min(100, Math.round((event.tickets_issued / event.capacity) * 100));
  }
}
