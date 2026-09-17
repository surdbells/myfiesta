import { Injectable, inject, signal } from '@angular/core';
import { Capacitor } from '@capacitor/core';
import { LocalNotifications } from '@capacitor/local-notifications';
import { Preferences } from '@capacitor/preferences';
import { Ticket } from './api';
import { shortEventTime } from './event-time';

const KEY = 'myfiesta.reminders';

/** How long before doors the phone speaks up. */
const LEAD_HOURS = 3;

/**
 * Reminding somebody about a night they already paid for.
 *
 * Scheduled on the phone, not sent from a server. A local notification needs
 * no push certificates, no device token to keep in sync, and — the part that
 * matters at a door — no signal at the moment it fires. Somebody on a bus with
 * one bar still gets told.
 *
 * Off until asked for. Permission is requested when the switch is turned on,
 * where the reason is on screen, rather than at first launch where it is a
 * dialog about nothing.
 *
 * The schedule is rebuilt from the tickets on every reconcile rather than
 * patched: a transferred ticket, a cancelled night and a changed start time
 * all have to be able to remove one, and rebuilding is the only version of
 * that with no stale-notification bug in it.
 */
@Injectable({ providedIn: 'root' })
export class Reminders {
  private readonly platform = Capacitor.isNativePlatform();

  /** Whether reminders are on. Null until read back from the phone. */
  readonly on = signal<boolean | null>(null);

  /** Set when the phone refused: the switch goes back and says why. */
  readonly refused = signal(false);

  /** Notifications only exist on a device; a browser tab has nowhere to put one. */
  readonly available = this.platform;

  async restore(): Promise<void> {
    if (!this.available) {
      this.on.set(false);

      return;
    }

    const { value } = await Preferences.get({ key: KEY });

    this.on.set(value === '1');
  }

  /**
   * Turn them on or off.
   *
   * Returns what the switch should now read: asking for permission can end in
   * a no, and a switch that stays on after the phone said no is a lie.
   */
  async set(on: boolean, tickets: Ticket[]): Promise<boolean> {
    this.refused.set(false);

    if (!this.available) return false;

    if (!on) {
      await Preferences.set({ key: KEY, value: '0' });
      this.on.set(false);
      await this.clear();

      return false;
    }

    if (!(await this.permission())) {
      this.refused.set(true);
      this.on.set(false);
      await Preferences.set({ key: KEY, value: '0' });

      return false;
    }

    await Preferences.set({ key: KEY, value: '1' });
    this.on.set(true);
    await this.reconcile(tickets);

    return true;
  }

  /**
   * Make the phone's schedule match the tickets it holds.
   *
   * Cheap enough to call whenever the tickets are loaded, which is what keeps
   * a reminder from outliving a ticket somebody handed to a friend.
   */
  async reconcile(tickets: Ticket[]): Promise<void> {
    if (!this.available || this.on() !== true) return;

    await this.clear();

    const now = Date.now();
    const due = tickets
      .filter((ticket) => ticket.status === 'valid')
      // One reminder per night, not one per ticket: somebody who bought four
      // does not want to be told four times.
      .filter((ticket, index, all) => all.findIndex((t) => t.event.slug === ticket.event.slug) === index)
      .map((ticket) => ({ ticket, at: new Date(ticket.event.starts_at).getTime() - LEAD_HOURS * 3_600_000 }))
      // A reminder for a time that has passed fires immediately on some
      // Androids, which is a notification about last Saturday.
      .filter((row) => row.at > now)
      .slice(0, 32);

    if (due.length === 0) return;

    try {
      await this.schedule(due);
    } catch {
      // A phone that will not schedule — permission withdrawn in settings, a
      // manufacturer's own battery rules — is a phone without reminders, not
      // a broken tickets screen. Callers fire this and walk away.
    }
  }

  private async schedule(due: { ticket: Ticket; at: number }[]): Promise<void> {
    await LocalNotifications.schedule({
      notifications: due.map((row, index) => ({
        id: index + 1,
        title: row.ticket.event.title,
        body: `Doors in ${LEAD_HOURS} hours — ${shortEventTime(row.ticket.event.starts_at, row.ticket.event.timezone)}. Your ticket is in the app.`,
        /*
         * Not an exact alarm.
         *
         * Asking Android to fire at a precise moment regardless of Doze needs
         * SCHEDULE_EXACT_ALARM, which Google Play restricts to apps where
         * exact alarms are the point — clocks and calendars. This is "you are
         * going out tonight", three hours ahead: a reminder that arrives a few
         * minutes either side is the same reminder, and it is not worth a
         * restricted permission or a prompt asking for one.
         */
        schedule: { at: new Date(row.at) },
        extra: { slug: row.ticket.event.slug, ticketId: row.ticket.id },
      })),
    });
  }

  /** Everything this app scheduled, gone. Used when switching off or signing out. */
  async clear(): Promise<void> {
    if (!this.available) return;

    try {
      const { notifications } = await LocalNotifications.getPending();

      if (notifications.length > 0) await LocalNotifications.cancel({ notifications });
    } catch {
      // Nothing pending, or the plugin is gone with the page.
    }
  }

  private async permission(): Promise<boolean> {
    try {
      const { display } = await LocalNotifications.checkPermissions();

      if (display === 'granted') return true;

      const asked = await LocalNotifications.requestPermissions();

      return asked.display === 'granted';
    } catch {
      return false;
    }
  }
}
