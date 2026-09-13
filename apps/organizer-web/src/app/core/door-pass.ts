import { Injectable, computed, inject, signal } from '@angular/core';
import { DOCUMENT } from '@angular/common';
import { DoorPassSession } from './api.types';

const STORAGE_KEY = 'myfiesta.door.pass';

/**
 * A door pass opened on this phone.
 *
 * Kept apart from the organizer session on purpose. The phone at the door may
 * be somebody's own, already signed in to the console — and the two must not
 * blur: the pass is used for its event's door and nothing else, and a pass
 * that stops working must not sign the owner of the phone out of their own
 * account on the way.
 */
@Injectable({ providedIn: 'root' })
export class DoorPassStore {
  private readonly document = inject(DOCUMENT);

  private readonly state = signal<DoorPassSession | null>(this.restore());

  readonly pass = this.state.asReadonly();
  readonly eventId = computed(() => this.state()?.event.id ?? null);

  /** Why the last pass stopped, for the screen that replaces the scanner. */
  readonly ended = signal<string | null>(null);

  start(pass: DoorPassSession): void {
    this.ended.set(null);
    this.state.set(pass);
    this.storage?.setItem(STORAGE_KEY, JSON.stringify(pass));
  }

  /** The pass for this event, if this phone holds one that is still in date. */
  for(eventId: string): DoorPassSession | null {
    const pass = this.state();

    return pass && pass.event.id === eventId && !this.expired(pass) ? pass : null;
  }

  /**
   * The token to send, if this request is one the pass exists for.
   *
   * Only the three door endpoints of its own event. The API refuses a door
   * token anywhere else — this just avoids asking.
   */
  tokenFor(url: string, base: string): string | null {
    const pass = this.state();
    if (!pass || this.expired(pass)) return null;

    const root = `${base.replace(/\/+$/, '')}/api/events/${pass.event.id}/`;
    if (!url.startsWith(root)) return null;

    const rest = url.slice(root.length).split('?')[0];

    return ['scan', 'door-list', 'scans/sync'].includes(rest) ? pass.token : null;
  }

  end(reason: string | null = null): void {
    this.ended.set(reason);
    this.state.set(null);
    this.storage?.removeItem(STORAGE_KEY);
  }

  private expired(pass: DoorPassSession): boolean {
    return new Date(pass.expires_at).getTime() <= Date.now();
  }

  private get storage(): Storage | null {
    try {
      return this.document.defaultView?.localStorage ?? null;
    } catch {
      return null;
    }
  }

  private restore(): DoorPassSession | null {
    try {
      const raw = this.storage?.getItem(STORAGE_KEY);
      if (!raw) return null;

      const pass = JSON.parse(raw) as DoorPassSession;

      if (!pass?.token || !pass.event?.id || this.expired(pass)) {
        this.storage?.removeItem(STORAGE_KEY);
        return null;
      }

      return pass;
    } catch {
      return null;
    }
  }
}
