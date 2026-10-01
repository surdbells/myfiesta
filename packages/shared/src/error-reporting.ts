/**
 * Error reports from the site, the console and the phone app: whether to send
 * them, where, and what comes out of them first.
 *
 * Framework-free and shared because all three have to agree on the part that
 * matters — nothing personal leaves. The addresses these apps run at are
 * credentials more often than not: a ticket page's token is the ticket, an
 * unsubscribe link is the subscription, a door-pass link is a door, and a
 * staff session arrives in the console as a code in the fragment. An error
 * report carries the address it happened at, every address visited before it,
 * and whatever the error said. So every one of those is read here and cut
 * down before anything is sent.
 *
 * Each app reads its settings out of its own page (meta tags stamped at run
 * time, like the API address), and sends nothing at all when there is no DSN.
 */

/** Where reports go, and what they are labelled with. */
export interface ErrorReportingConfig {
  dsn: string;
  environment: string;
  release: string | undefined;
}

const REDACTED = '[redacted]';

/**
 * Paths whose next segment is a credential: the API's spellings, and each
 * app's own routes to the same links — the console's `/join/:token` and
 * `/door-pass/:secret`, the phone app's `/door-pass/:secret`. Each app's spec
 * walks its routes and fails on one that takes a credential this misses.
 */
const SECRET_AFTER = new Set([
  'tickets',
  'order',
  'invitations',
  'invite',
  'join',
  'door-passes',
  'door-pass',
  'door',
  'unsubscribe',
  'waitlist',
  'follows',
  'requests',
  'verify-email',
  'sign-up',
  'reset-password',
  'impersonate',
  // A survey's link: the site's /tickets/feedback/:token and the API's
  // /surveys/:token answer to whoever holds the token.
  'feedback',
  'surveys',
]);

/** Names whose values are never sent, matched as parts of the name. */
const SENSITIVE = [
  'password',
  'secret',
  'token',
  'authorization',
  'cookie',
  'session',
  'xsrf',
  'csrf',
  'account',
  'transit',
  'bank',
  'card',
  'cvv',
  'iban',
  'email',
  'phone',
  'holder',
  'buyer',
  'address',
  'otp',
  'signature',
  'dsn',
];

/**
 * Names that are sensitive only as the whole name — as parts of one they
 * would take `status_code` out of every request breadcrumb. A ticket's `code`
 * is what gets somebody through a door.
 */
const SENSITIVE_EXACTLY = new Set(['code', 'pin', 'key', 'name', 'hash']);

/**
 * The page's settings, or null when nothing should be sent.
 *
 * A DSN that is not an https address is treated as none: a typo must not turn
 * reporting on towards somewhere nobody meant.
 */
export function readErrorReportingConfig(doc: Pick<Document, 'querySelector'>): ErrorReportingConfig | null {
  const meta = (name: string) =>
    doc.querySelector<HTMLMetaElement>(`meta[name="${name}"]`)?.getAttribute('content')?.trim() ?? '';

  const dsn = meta('sentry-dsn');

  if (errorReportingOrigin(dsn) === null) {
    return null;
  }

  return {
    dsn,
    environment: meta('sentry-environment') || 'production',
    release: meta('sentry-release') || undefined,
  };
}

/**
 * Where a DSN sends its reports, as an origin for a Content-Security-Policy:
 * scheme, host and port — never the key in front of the host.
 */
export function errorReportingOrigin(dsn: string | null | undefined): string | null {
  if (!dsn) return null;

  try {
    const url = new URL(dsn);

    return url.protocol === 'https:' && url.host !== '' ? `${url.protocol}//${url.host}` : null;
  } catch {
    return null;
  }
}

/** An address without the parts of it that are credentials. */
export function scrubUrl(address: string): string {
  let url: URL;

  try {
    url = new URL(address, 'https://relative.invalid');
  } catch {
    return REDACTED;
  }

  const segments = url.pathname.split('/');
  const path = segments
    .map((segment, i) => (i > 0 && segment !== '' && SECRET_AFTER.has(segments[i - 1].toLowerCase()) ? REDACTED : segment))
    .join('/');

  const query = [...url.searchParams.keys()].length
    ? '?' +
      [...url.searchParams.entries()]
        .map(([key, value]) => `${encodeURIComponent(key)}=${isSensitive(key) ? REDACTED : encodeURIComponent(scrubText(value))}`)
        .join('&')
    : '';

  // The fragment is where the console receives a staff session's handoff
  // code, and nothing else useful ever lives there.
  const fragment = url.hash ? `#${REDACTED}` : '';

  return `${originOf(url)}${path}${query}${fragment}`;
}

function originOf(url: URL): string {
  if (url.origin === 'https://relative.invalid') return '';
  if (url.origin !== 'null') return url.origin;

  // The phone app on iOS runs at capacitor://localhost, whose origin a URL
  // reports as "null": it is spelled out from its parts instead.
  return url.host ? `${url.protocol}//${url.host}` : url.protocol;
}

/** Email addresses, long runs of digits and card-shaped numbers, out of free text. */
export function scrubText(text: string): string {
  return text
    .replace(/https?:\/\/[^\s"'<>()]+/gi, (address) => scrubUrl(address))
    .replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, REDACTED)
    .replace(/(?<![\w.-])\d{7,}(?![\w.-])/g, REDACTED)
    .replace(/\+\d[\d -]{6,}\d/g, REDACTED)
    .replace(/\b(?:\d{4}[ -]){3}\d{1,4}\b/g, REDACTED);
}

function isSensitive(key: string): boolean {
  const lower = key.toLowerCase();

  return SENSITIVE_EXACTLY.has(lower) || SENSITIVE.some((needle) => lower.includes(needle));
}

/** Values under sensitive names removed, the rest read for giveaways. */
export function scrubData<T>(value: T, depth = 0): T {
  if (depth > 8) return REDACTED as T;

  if (typeof value === 'string') return scrubText(value) as T;

  if (Array.isArray(value)) return value.map((item) => scrubData(item, depth + 1)) as T;

  if (value !== null && typeof value === 'object') {
    const out: Record<string, unknown> = {};

    for (const [key, item] of Object.entries(value as Record<string, unknown>)) {
      out[key] = isSensitive(key) ? REDACTED : scrubData(item, depth + 1);
    }

    return out as T;
  }

  return value;
}

/** The parts of an error report this reads. Structural, so no SDK is imported here. */
export interface ScrubbableBreadcrumb {
  message?: string;
  data?: Record<string, unknown>;
}

export interface ScrubbableEvent {
  message?: string;
  request?: { url?: string; query_string?: unknown; headers?: Record<string, string>; cookies?: unknown; data?: unknown };
  exception?: { values?: Array<{ value?: string }> };
  breadcrumbs?: ScrubbableBreadcrumb[];
  extra?: Record<string, unknown>;
  user?: Record<string, unknown>;
  transaction?: string;
}

export function scrubBreadcrumb<B extends ScrubbableBreadcrumb>(crumb: B): B {
  const data = crumb.data ? { ...crumb.data } : undefined;

  if (data) {
    for (const key of ['url', 'from', 'to']) {
      if (typeof data[key] === 'string') data[key] = scrubUrl(data[key] as string);
    }
  }

  return {
    ...crumb,
    ...(crumb.message !== undefined ? { message: scrubText(crumb.message) } : {}),
    ...(data ? { data: scrubData(data) } : {}),
  } as B;
}

/** Everything a report carries, cut down before it is sent. */
export function scrubEvent<E extends ScrubbableEvent>(event: E): E {
  const scrubbed: ScrubbableEvent = { ...event };

  if (scrubbed.message !== undefined) scrubbed.message = scrubText(scrubbed.message);
  if (scrubbed.transaction !== undefined) scrubbed.transaction = scrubUrl(scrubbed.transaction);

  if (scrubbed.request) {
    const { url, headers, data } = scrubbed.request;

    scrubbed.request = {
      ...(url !== undefined ? { url: scrubUrl(url) } : {}),
      ...(headers ? { headers: scrubData(headers) } : {}),
      ...(data !== undefined ? { data: scrubData(data) } : {}),
    };
  }

  if (scrubbed.exception?.values) {
    scrubbed.exception = {
      ...scrubbed.exception,
      values: scrubbed.exception.values.map((value) => (value.value === undefined ? value : { ...value, value: scrubText(value.value) })),
    };
  }

  if (scrubbed.breadcrumbs) scrubbed.breadcrumbs = scrubbed.breadcrumbs.map((crumb) => scrubBreadcrumb(crumb));
  if (scrubbed.extra) scrubbed.extra = scrubData(scrubbed.extra);

  // Which account, if anything said — never who, and never where from.
  if (scrubbed.user) {
    scrubbed.user = scrubbed.user['id'] === undefined ? undefined : { id: scrubbed.user['id'] };
  }

  return scrubbed as E;
}
