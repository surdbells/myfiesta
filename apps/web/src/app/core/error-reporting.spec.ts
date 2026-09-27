import type { Route } from '@angular/router';
import {
  errorReportingOrigin,
  readErrorReportingConfig,
  scrubBreadcrumb,
  scrubEvent,
  scrubUrl,
} from '@myfiesta/shared/error-reporting';
import { routes } from '../app.routes';
import { reportError, resetErrorReportingForTests, sentryOptions, startErrorReporting } from './error-reporting';

/**
 * Error reports: sent only when the page names somewhere, and only after
 * everything that would identify somebody — or let somebody in — is out.
 *
 * The reporter is stood in for; what is held here is when it is loaded at
 * all, what it is started with, and what the scrubber leaves of a report.
 */
describe('error reporting', () => {
  const DSN = 'https://abc123@o42.ingest.us.sentry.io/7';

  function page(values: Record<string, string>): Pick<Document, 'querySelector'> {
    const doc = document.implementation.createHTMLDocument('');

    for (const [name, content] of Object.entries(values)) {
      const meta = doc.createElement('meta');
      meta.setAttribute('name', name);
      meta.setAttribute('content', content);
      doc.head.appendChild(meta);
    }

    return doc;
  }

  function fakeSdk() {
    const sdk = {
      options: null as unknown,
      captured: [] as unknown[],
      init(options: unknown) {
        sdk.options = options;
        return undefined;
      },
      captureException(error: unknown) {
        sdk.captured.push(error);
        return 'id';
      },
    };

    return sdk;
  }

  beforeEach(() => resetErrorReportingForTests());

  it('loads nothing and sends nothing without a DSN', async () => {
    let loaded = false;
    const load = async () => {
      loaded = true;
      return fakeSdk() as never;
    };

    const pages: Record<string, string>[] = [
      {},
      { 'sentry-dsn': '' },
      { 'sentry-dsn': 'not a url' },
      { 'sentry-dsn': 'http://key@insecure.example/1' },
    ];

    for (const values of pages) {
      expect(await startErrorReporting(page(values), load)).toBe(false);
    }

    reportError(new Error('nobody hears this'));

    expect(loaded).toBe(false);
  });

  it('starts with no personal data, no tracing, and the scrubber in front', async () => {
    const sdk = fakeSdk();

    expect(
      await startErrorReporting(
        page({ 'sentry-dsn': DSN, 'sentry-environment': 'staging', 'sentry-release': 'v2026.09.27' }),
        async () => sdk as never,
      ),
    ).toBe(true);

    expect(sdk.options).toEqual(
      expect.objectContaining({
        dsn: DSN,
        environment: 'staging',
        release: 'v2026.09.27',
        sendDefaultPii: false,
        tracesSampleRate: 0,
        beforeSend: scrubEvent,
        beforeBreadcrumb: scrubBreadcrumb,
      }),
    );
  });

  it('keeps errors raised while the reporter loads and sends them when it lands', async () => {
    const sdk = fakeSdk();
    let arrive!: () => void;
    const landed = new Promise<void>((resolve) => (arrive = resolve));

    const started = startErrorReporting(page({ 'sentry-dsn': DSN }), async () => {
      await landed;
      return sdk as never;
    });

    const early = new Error('while loading');
    reportError(early);
    expect(sdk.captured).toEqual([]);

    arrive();
    await started;

    const late = new Error('after');
    reportError(late);

    expect(sdk.captured).toEqual([early, late]);
  });

  it('reads the page, defaulting the environment to production', () => {
    expect(readErrorReportingConfig(page({ 'sentry-dsn': DSN }))).toEqual({ dsn: DSN, environment: 'production', release: undefined });
    expect(errorReportingOrigin(DSN)).toBe('https://o42.ingest.us.sentry.io');
    expect(errorReportingOrigin('')).toBeNull();
  });

  it('takes the credential out of every address a report carries', () => {
    expect(scrubUrl('https://myfiesta.ca/tickets/Tk_8f3kQ2mZr9LwX1vB?utm_source=sms')).toBe(
      'https://myfiesta.ca/tickets/[redacted]?utm_source=sms',
    );
    expect(scrubUrl('https://myfiesta.ca/order/ABC123?token=s3cret')).toBe('https://myfiesta.ca/order/[redacted]?token=[redacted]');
    expect(scrubUrl('https://console.myfiesta.ca/impersonate#code=handoff')).toBe('https://console.myfiesta.ca/impersonate#[redacted]');
    expect(scrubUrl('/afro-fest/checkout')).toBe('/afro-fest/checkout');

    // The console's and the phone app's own spellings of the same links.
    expect(scrubUrl('https://console.myfiesta.ca/door-pass/Dp_s3cr3tPass')).toBe('https://console.myfiesta.ca/door-pass/[redacted]');
    expect(scrubUrl('/join/Inv_t0ken')).toBe('/join/[redacted]');
    expect(scrubUrl('capacitor://localhost/door-pass/Dp_s3cr3tPass')).toBe('capacitor://localhost/door-pass/[redacted]');
  });

  it('takes the credential out of every route that is opened with one', () => {
    const opened = pathsWithCredentials(routes);

    // The walk found the links it is here for.
    expect(opened).toEqual(expect.arrayContaining(['order/:reference', 'tickets/:token']));

    for (const path of opened) {
      expect(scrubUrl(address(path)), `/${path} sends its credential: add it to SECRET_AFTER`).not.toContain(PLANTED);
    }
  });

  it('leaves a report with what went wrong and nothing about who', () => {
    const scrubbed = scrubEvent({
      message: 'Could not send to ada@example.com',
      request: {
        url: 'https://myfiesta.ca/tickets/Tk_8f3kQ2mZr9LwX1vB',
        headers: { Referer: 'https://myfiesta.ca/unsubscribe/abc123', 'User-Agent': 'Mozilla/5.0' },
        cookies: { session: 'x' },
        query_string: 'token=abc',
      },
      exception: { values: [{ value: 'Payout to 0123456789 failed' }] },
      breadcrumbs: [
        { message: 'navigated', data: { from: '/door-passes/s3cr3t', to: '/afro-fest', status_code: 200 } },
      ],
      extra: { buyer_email: 'ada@example.com', quantity: 2 },
      user: { id: 'u-1', email: 'ada@example.com', ip_address: '203.0.113.9' },
    });

    expect(scrubbed.message).toBe('Could not send to [redacted]');
    expect(scrubbed.request).toEqual({
      url: 'https://myfiesta.ca/tickets/[redacted]',
      headers: { Referer: 'https://myfiesta.ca/unsubscribe/[redacted]', 'User-Agent': 'Mozilla/5.0' },
    });
    expect(scrubbed.exception?.values?.[0].value).toBe('Payout to [redacted] failed');
    expect(scrubbed.breadcrumbs?.[0].data).toEqual({ from: '/door-passes/[redacted]', to: '/afro-fest', status_code: 200 });
    expect(scrubbed.extra).toEqual({ buyer_email: '[redacted]', quantity: 2 });
    expect(scrubbed.user).toEqual({ id: 'u-1' });
  });

  it('is started with exactly the options a spec can see', () => {
    expect(sentryOptions({ dsn: DSN, environment: 'production', release: undefined }).sendDefaultPii).toBe(false);
  });
});

/** Route parameters that are the credential: a link's token, an order's reference. */
const CREDENTIALS = ['token', 'secret', 'reference', 'code'];
const PLANTED = 'Cr3dential';

/** Every path the router knows, children joined to their parents, that takes a credential. */
function pathsWithCredentials(list: Route[], parent = ''): string[] {
  return list.flatMap((route) => {
    const path = [parent, route.path ?? ''].filter(Boolean).join('/');
    const own = path.split('/').some((segment) => segment.startsWith(':') && CREDENTIALS.includes(segment.slice(1)));

    return [...(own ? [path] : []), ...pathsWithCredentials(route.children ?? [], path)];
  });
}

/** The address a route is opened at, with something recognisable where each credential goes. */
function address(path: string): string {
  return (
    '/' +
    path
      .split('/')
      .map((segment) => (segment.startsWith(':') ? (CREDENTIALS.includes(segment.slice(1)) ? PLANTED + segment.slice(1) : '42') : segment))
      .join('/')
  );
}
