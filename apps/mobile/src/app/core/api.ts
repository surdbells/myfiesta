import { DOCUMENT } from '@angular/common';
import { Injectable, inject } from '@angular/core';
import { Capacitor } from '@capacitor/core';
import type { DoorList, OfflineScan, ScanResult, SyncResult } from '@myfiesta/door';
import type { Money } from './money';

/** A refusal with a sentence worth showing, and the status it came with. */
export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    /** A 422's complaints, by field, so a form can put each under its own box. */
    readonly fields?: Record<string, string[]>,
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

/*
 * Defined once, in @myfiesta/door, and re-exported so existing imports keep
 * working. The console declared the same shapes and so did this app; two apps
 * scanning the same tickets against the same server must not be two ideas of
 * what a scan is.
 */
export type { ScanResult, DoorList, OfflineScan, SyncResult } from '@myfiesta/door';

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

/** What a door may sell tonight, and what is left of each. */
export interface Sellable {
  currency: string;
  methods: DoorPaymentMethod[];
  ticket_types: {
    id: string;
    name: string;
    price: Money;
    admits: number;
    /** Null where the tier has no limit. */
    remaining: number | null;
    sold_out: boolean;
  }[];
}

/** How somebody standing at a door actually pays. */
export type DoorPaymentMethod = 'cash' | 'card' | 'transfer';

export interface DoorSaleRequest {
  items: { ticket_type_id: string; quantity: number }[];
  method: DoorPaymentMethod;
  /** Both optional: a walk-up paying cash gives neither. */
  name?: string;
  email?: string;
}

export interface DoorSale {
  reference: string;
  total: Money;
  method: DoorPaymentMethod;
  /** Whether an address was given and the ticket sent to it. */
  emailed: boolean;
  /** The codes, because the phone that sold them usually scans them next. */
  tickets: { id: string; code: string; type: string | null; admits: number }[];
}

/** The till, as somebody counting it at 3am needs to read it. */
export interface Takings {
  currency: string;
  tickets: number;
  total: Money;
  by_method: { method: DoorPaymentMethod; orders: number; total: Money }[];
  by_till: { label: string; orders: number; total: Money }[];
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

  /**
   * Which organization organizer requests are about.
   *
   * Sent as X-Organization, the same header the console sends: somebody who
   * runs two promotions from one account is asking about one of them, and the
   * API authorises against the organization named rather than guessing.
   */
  organization: string | null = null;

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
    if (token && this.organization && path.startsWith('/api/organizer')) headers['X-Organization'] = this.organization;
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

    if (!response.ok) {
      throw new ApiError(this.messageFor(response.status, body), response.status, body['errors'] as Record<string, string[]> | undefined);
    }

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

  // --- the organizer's own requests ------------------------------------------

  /** A signed-in request, for the organizer client to build on. */
  request<T>(method: string, path: string, body?: unknown, query?: Record<string, string>): Promise<T> {
    return this.send<T>(method, path, { body, query });
  }

  /**
   * A file, sent with progress.
   *
   * fetch cannot report how much of an upload has gone, and a poster from a
   * phone camera is several megabytes over venue wifi — a spinner with no
   * number on it is the moment somebody cancels and tries again. So this one
   * request is made the older way, which can.
   */
  upload<T>(
    path: string,
    fields: Record<string, string | Blob>,
    onProgress?: (percent: number | null) => void,
  ): Promise<T> {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      const body = new FormData();

      for (const [key, value] of Object.entries(fields)) body.append(key, value);

      xhr.open('POST', this.base + path);
      xhr.setRequestHeader('Accept', 'application/json');
      if (this.token) xhr.setRequestHeader('Authorization', `Bearer ${this.token}`);
      if (this.organization) xhr.setRequestHeader('X-Organization', this.organization);

      xhr.upload.onprogress = (event) =>
        onProgress?.(event.lengthComputable ? Math.round((event.loaded / event.total) * 100) : null);

      xhr.onerror = () => reject(new ApiError('No connection. Check signal and try again.', 0));
      xhr.ontimeout = () => reject(new ApiError('That took too long. Try again on a better connection.', 0));
      xhr.timeout = 120_000;

      xhr.onload = () => {
        let parsed: Record<string, unknown> = {};

        try {
          parsed = xhr.responseText ? (JSON.parse(xhr.responseText) as Record<string, unknown>) : {};
        } catch {
          // A non-JSON body is only ever an error page.
        }

        if (xhr.status >= 200 && xhr.status < 300) resolve(parsed as T);
        else reject(new ApiError(this.messageFor(xhr.status, parsed), xhr.status, parsed['errors'] as Record<string, string[]> | undefined));
      };

      xhr.send(body);
    });
  }

  /** A CSV export, as text, for handing to the phone's share sheet. */
  async download(path: string, query?: Record<string, string>): Promise<string> {
    const url = new URL(this.base + path);

    for (const [key, value] of Object.entries(query ?? {})) {
      if (value !== '') url.searchParams.set(key, value);
    }

    const headers: Record<string, string> = { Accept: 'text/csv' };
    if (this.token) headers['Authorization'] = `Bearer ${this.token}`;
    if (this.organization) headers['X-Organization'] = this.organization;

    let response: Response;

    try {
      response = await fetch(url, { headers, signal: AbortSignal.timeout(60_000) });
    } catch {
      throw new ApiError('No connection. Check signal and try again.', 0);
    }

    if (!response.ok) throw new ApiError(this.messageFor(response.status, {}), response.status);

    return response.text();
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

  /**
   * Making an account from the phone, as somebody who is going out.
   *
   * No organization name asked for. Putting on an event is done in the
   * console, where there is a screen wide enough to build one; this is for
   * somebody who bought as a guest and wants their tickets to follow them.
   */
  /** With an organization name, an organizer account and its events page; without one, an attendee. */
  register(name: string, email: string, password: string, organization: string | null = null): Promise<Record<string, unknown>> {
    return this.send('POST', '/api/auth/register', {
      body: {
        name,
        email,
        password,
        password_confirmation: password,
        ...(organization ? { organization } : { attendee: true }),
        device: 'mobile',
      },
      anonymous: true,
    });
  }

  /**
   * Ask for a reset link.
   *
   * The answer is the same whether or not the address is known, and this
   * repeats it rather than improving on it — anything friendlier would turn
   * the screen into a way to test who holds an account here.
   */
  async forgotPassword(email: string): Promise<string> {
    const body = await this.send<{ message: string }>('POST', '/api/auth/forgot-password', {
      body: { email },
      anonymous: true,
    });

    return body.message;
  }

  async signOut(): Promise<void> {
    try {
      await this.send('POST', '/api/auth/logout');
    } catch {
      // The local session goes either way. A network failure must not leave
      // somebody signed in on a phone they are handing back.
    }
  }

  /**
   * Who is signed in, and the organizations they belong to — with what they
   * may do in each, as the server resolves it rather than as the phone guesses.
   */
  me(): Promise<{ name: string; email: string; organizations: { id: string; name: string; role: string; permissions: string[] }[] }> {
    return this.send('GET', '/api/auth/me');
  }

  // --- attendee -------------------------------------------------------------

  async tickets(): Promise<Ticket[]> {
    const body = await this.send<{ data: Ticket[] }>('GET', '/api/me/tickets');

    return body.data;
  }

  transfer(ticketId: string, email: string, name: string): Promise<unknown> {
    return this.send('POST', `/api/tickets/${ticketId}/transfer`, { body: { email, name } });
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

  /**
   * The list this phone decides from when the signal goes.
   *
   * Hashes, never codes: enough to recognise a ticket somebody shows and never
   * enough to mint one, because a door phone is lent out for a night.
   */
  doorList(eventId: string, token?: string): Promise<DoorList> {
    return this.send('GET', `/api/events/${eventId}/door-list`, { token });
  }

  /** The scans made while offline, sent back once there is signal for them. */
  syncScans(eventId: string, scans: Omit<OfflineScan, 'event_id'>[], token?: string): Promise<SyncResult> {
    return this.send('POST', `/api/events/${eventId}/scans/sync`, { body: { scans }, token });
  }

  // --- selling to somebody standing there ---------------------------------

  /** What this door may sell, with what is genuinely left of each. */
  sellable(eventId: string, token?: string): Promise<Sellable> {
    return this.send('GET', `/api/events/${eventId}/sellable`, { token });
  }

  /**
   * Take the money and mint the tickets.
   *
   * Online only, deliberately. A sale is money changing hands and stock
   * leaving the room, and neither can be decided by a phone with no signal —
   * the offline list exists to admit people who already hold a ticket, not to
   * invent ones nobody has paid for.
   */
  sellAtDoor(eventId: string, body: DoorSaleRequest, token?: string): Promise<DoorSale> {
    return this.send('POST', `/api/events/${eventId}/door-sales`, { body, token });
  }

  /**
   * What to say out loud before any money changes hands.
   *
   * Priced by the server like every other figure here. A phone adding up the
   * tiers itself lands a cent out on the tax often enough, and a cent is
   * somebody holding coins while a screen disagrees with them.
   */
  doorQuote(
    eventId: string,
    items: { ticket_type_id: string; quantity: number }[],
    token?: string,
  ): Promise<{ total: Money; tax: Money; tax_label: string | null }> {
    return this.send('POST', `/api/events/${eventId}/door-quote`, { body: { items }, token });
  }

  /** The till, for whoever is counting it. */
  takings(eventId: string, token?: string): Promise<Takings> {
    return this.send('GET', `/api/events/${eventId}/takings`, { token });
  }
}
