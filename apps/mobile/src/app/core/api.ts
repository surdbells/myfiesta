import { DOCUMENT } from '@angular/common';
import { Injectable, inject } from '@angular/core';
import { Capacitor } from '@capacitor/core';
import type { Money } from './money';

/** A refusal with a sentence worth showing, and the status it came with. */
export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message);
  }
}

export interface Ticket {
  id: string;
  code: string;
  status: 'valid' | 'checked_in' | string;
  type: string | null;
  holder_name: string | null;
  event: { slug: string; title: string; starts_at: string; timezone: string; city: string };
}

export interface OrganizerEvent {
  id: string;
  title: string;
  status: string;
  starts_at: string;
  timezone: string;
  city: string;
  tickets_issued: number;
  checked_in: number;
}

export interface EventTotals {
  currency: string;
  gross: Money;
  net: Money;
  orders: number;
  tickets_issued: number;
  checked_in: number;
}

export interface Guest {
  name: string | null;
  email: string;
  ticket_type: string | null;
  checked_in: boolean;
}

export interface ScanResult {
  result: string;
  accepted: boolean;
  admitted: number;
  remaining: number;
  message: string;
  ticket: { holder_name: string | null; type: string | null; admits: number; admitted_count: number } | null;
}

export interface DoorPass {
  token: string;
  label: string;
  expires_at: string;
  event: {
    id: string;
    title: string;
    starts_at: string;
    timezone: string;
    venue: string | null;
    city: string | null;
    min_age: number | null;
    id_required: boolean;
  };
}

/**
 * Everything the app knows about the server.
 *
 * The base URL is read at runtime from a meta tag rather than compiled in, the
 * same as the two web apps: one build then serves staging and production, and
 * an artifact cannot be promoted with the wrong host baked inside it.
 *
 * On a device there is no page to read it from, so the native default is used —
 * a debug build points at the development machine, a release build at the
 * production API through the environment written at package time.
 */
@Injectable({ providedIn: 'root' })
export class Api {
  private readonly document = inject(DOCUMENT);

  /** The token, when there is one. Set on sign-in, cleared on 401. */
  token: string | null = null;

  readonly base = this.resolveBase();

  private resolveBase(): string {
    const meta = this.document.querySelector<HTMLMetaElement>('meta[name="api-base"]');

    if (meta?.content) return meta.content.replace(/\/+$/, '');

    // A device cannot reach the developer machine on localhost: Android's
    // emulator maps it to 10.0.2.2, and a real phone needs the LAN address,
    // which is what the packaged meta tag carries.
    if (Capacitor.getPlatform() === 'android') return 'http://10.0.2.2:8000';

    return 'http://127.0.0.1:8000';
  }

  private async send<T>(
    method: string,
    path: string,
    options: { body?: unknown; query?: Record<string, string>; token?: string; anonymous?: boolean } = {},
  ): Promise<T> {
    const url = new URL(this.base + path);

    for (const [key, value] of Object.entries(options.query ?? {})) {
      if (value !== '') url.searchParams.set(key, value);
    }

    const headers: Record<string, string> = { Accept: 'application/json' };
    const token = options.anonymous ? null : (options.token ?? this.token);

    if (token) headers['Authorization'] = `Bearer ${token}`;
    if (options.body !== undefined) headers['Content-Type'] = 'application/json';

    let response: Response;

    try {
      response = await fetch(url, {
        method,
        headers,
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
        // A door has bad wifi more often than it has good wifi. Twenty seconds
        // then an honest failure beats a spinner nobody can interrupt.
        signal: AbortSignal.timeout(20_000),
      });
    } catch {
      throw new ApiError('No connection. Check signal and try again.', 0);
    }

    const text = await response.text();
    const body = text === '' ? {} : (JSON.parse(text) as Record<string, unknown>);

    if (!response.ok) throw new ApiError(this.messageFor(response.status, body), response.status);

    return body as T;
  }

  /**
   * The same rule the web clients use, drawn at 500.
   *
   * A 4xx message is written for whoever made the request. A 5xx message is
   * written for us, and passing it through is how a database error or a
   * payment provider's complaint about our API key ends up on a phone held by
   * somebody standing at a door.
   */
  private messageFor(status: number, body: Record<string, unknown>): string {
    if (status >= 500) return 'Something went wrong at our end. Nothing you did caused it.';

    const message = body['message'];

    if (typeof message === 'string' && message.trim() !== '') return message;

    const errors = body['errors'] as Record<string, string[]> | undefined;
    const first = errors ? Object.values(errors)[0]?.[0] : null;

    return first ?? 'That did not work.';
  }

  // --- browsing, which needs no account --------------------------------------

  /**
   * A public GET, deliberately without the token.
   *
   * Discovery is the same for everybody, and sending a door pass or an
   * attendee token with it would have the API answer a question nobody asked.
   */
  public<T>(path: string, query?: Record<string, string>): Promise<T> {
    return this.send<T>('GET', path, { query, anonymous: true });
  }

  publicPost<T>(path: string, body: unknown): Promise<T> {
    return this.send<T>('POST', path, { body, anonymous: true });
  }

  /**
   * A public GET that carries a token when the caller hands one over.
   *
   * For the pages that are the same for everybody except for one thing about
   * the reader — whether they saved this night, whether they follow whoever is
   * putting it on. Without a token it is the anonymous request above; the
   * caller decides, so a door pass is never sent to a discovery endpoint.
   */
  asReader<T>(path: string, query?: Record<string, string>, token?: string | null): Promise<T> {
    return this.send<T>('GET', path, { query, anonymous: !token, token: token ?? undefined });
  }

  /** The reader's own lists: saved nights, organizers followed. */
  mine<T>(path: string): Promise<T> {
    return this.send<T>('GET', path);
  }

  put<T>(path: string): Promise<T> {
    return this.send<T>('PUT', path, {});
  }

  remove<T>(path: string): Promise<T> {
    return this.send<T>('DELETE', path, {});
  }

  // --- auth -----------------------------------------------------------------

  signIn(email: string, password: string): Promise<Record<string, unknown>> {
    return this.send('POST', '/api/auth/login', {
      body: { email, password, device: 'mobile' },
    });
  }

  async signOut(): Promise<void> {
    try {
      await this.send('POST', '/api/auth/logout');
    } catch {
      // The local session goes either way. A network failure must not leave
      // somebody signed in on a phone they are handing back.
    }
  }

  // --- attendee -------------------------------------------------------------

  async tickets(): Promise<Ticket[]> {
    const body = await this.send<{ data: Ticket[] }>('GET', '/api/me/tickets');

    return body.data;
  }

  transfer(ticketId: string, email: string, name: string): Promise<unknown> {
    return this.send('POST', `/api/tickets/${ticketId}/transfer`, { body: { email, name } });
  }

  // --- organizer ------------------------------------------------------------

  async events(organizationId?: string | null): Promise<OrganizerEvent[]> {
    const body = await this.send<{ data: OrganizerEvent[] }>('GET', '/api/organizer/events', {
      query: organizationId ? { organization: organizationId } : undefined,
    });

    return body.data;
  }

  summary(eventId: string): Promise<EventTotals> {
    return this.send('GET', `/api/organizer/events/${eventId}/summary`);
  }

  async guests(eventId: string, search = ''): Promise<Guest[]> {
    const body = await this.send<{ data: Guest[] }>('GET', `/api/organizer/events/${eventId}/guests`, {
      query: search.trim() === '' ? undefined : { q: search.trim() },
    });

    return body.data;
  }

  // --- the door -------------------------------------------------------------

  /**
   * What a door link opens, before opening it.
   *
   * Separate from claiming, because claiming works once: showing the event
   * first lets whoever is holding the phone see they are about to open the
   * right night.
   */
  doorPass(secret: string): Promise<Omit<DoorPass, 'token'> & { state: string }> {
    return this.send('GET', `/api/door-passes/${encodeURIComponent(secret)}`);
  }

  claimDoorPass(secret: string): Promise<DoorPass> {
    return this.send('POST', `/api/door-passes/${encodeURIComponent(secret)}/claim`);
  }

  scan(eventId: string, code: string, token?: string): Promise<ScanResult> {
    return this.send('POST', `/api/events/${eventId}/scan`, { body: { code }, token });
  }
}
