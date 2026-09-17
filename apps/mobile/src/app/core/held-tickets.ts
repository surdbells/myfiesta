import { Injectable, inject } from '@angular/core';
import { Preferences } from '@capacitor/preferences';
import { Api, ApiError, Ticket } from './api';

const KEY = 'myfiesta.tickets';

export interface HeldTickets {
  tickets: Ticket[];
  /** True when the server could not be reached and this came off the phone. */
  stale: boolean;
  /** When the list was last confirmed with the server. */
  checkedAt: Date | null;
}

/**
 * The tickets somebody holds, kept on the phone.
 *
 * The app tells people their tickets work with no signal, and until this
 * existed that was not true: every screen fetched the list, so a guest in a
 * basement venue — which is most venues — opened the app at the door and got
 * "no connection" where their QR should have been. The code is drawn locally;
 * it was the list around it that needed a mobile signal.
 *
 * So the last good answer is written down, and used when the server cannot be
 * reached. Only then: a reachable server is always the truth, because a ticket
 * transferred away has to stop working on the phone that sent it.
 *
 * A network failure falls back. A refusal does not — a 401 means this phone is
 * signed out and the cached list is somebody else's, and a 500 means the server
 * is there and unhappy, which is not a reason to show a stale ticket as though
 * it were current.
 *
 * What is stored is the holder's own tickets on the holder's own device. That
 * is the one place a ticket code belongs: the door's offline list carries no
 * codes at all, because a door phone is lent out and a list of codes is a book
 * of tickets.
 */
@Injectable({ providedIn: 'root' })
export class HeldTicketStore {
  private readonly api = inject(Api);

  async list(): Promise<HeldTickets> {
    try {
      const tickets = await this.api.tickets();

      await this.remember(tickets);

      return { tickets, stale: false, checkedAt: new Date() };
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 0) throw error;

      const cached = await this.cached();

      // Nothing saved yet: a first run with no signal has nothing to show, and
      // saying so is better than an empty list that looks like no tickets.
      if (cached === null) throw error;

      return { ...cached, stale: true };
    }
  }

  /** Forget everything. Signing out must not leave a ticket on the phone. */
  async forget(): Promise<void> {
    try {
      await Preferences.remove({ key: KEY });
    } catch {
      // Nothing to remove.
    }
  }

  private async remember(tickets: Ticket[]): Promise<void> {
    try {
      await Preferences.set({
        key: KEY,
        value: JSON.stringify({ tickets, checkedAt: new Date().toISOString() }),
      });
    } catch {
      // A phone that will not write is a phone without a fallback, not a
      // phone that cannot show the tickets it just fetched.
    }
  }

  private async cached(): Promise<{ tickets: Ticket[]; checkedAt: Date | null } | null> {
    try {
      const { value } = await Preferences.get({ key: KEY });

      if (!value) return null;

      const saved = JSON.parse(value) as { tickets: Ticket[]; checkedAt?: string };

      if (!Array.isArray(saved.tickets)) return null;

      return {
        tickets: saved.tickets,
        checkedAt: saved.checkedAt ? new Date(saved.checkedAt) : null,
      };
    } catch {
      // Unreadable storage is no fallback, not a broken app.
      return null;
    }
  }
}
