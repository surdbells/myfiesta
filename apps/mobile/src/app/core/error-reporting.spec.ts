import type { Route } from '@angular/router';
import { scrubBreadcrumb, scrubEvent, scrubUrl } from '@myfiesta/shared/error-reporting';
import { routes } from '../app.routes';
import { reportError, resetErrorReportingForTests, startErrorReporting } from './error-reporting';

/**
 * The phone app reports errors only when its build names somewhere, and
 * never with a ticket's token or a door pass in the address.
 */
describe('phone error reporting', () => {
  function page(dsn: string): Pick<Document, 'querySelector'> {
    const doc = document.implementation.createHTMLDocument('');
    const meta = doc.createElement('meta');
    meta.setAttribute('name', 'sentry-dsn');
    meta.setAttribute('content', dsn);
    doc.head.appendChild(meta);

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

  it('loads nothing without a DSN', async () => {
    let loaded = false;

    expect(
      await startErrorReporting(page(''), async () => {
        loaded = true;
        return fakeSdk() as never;
      }),
    ).toBe(false);

    reportError(new Error('nobody hears this'));
    expect(loaded).toBe(false);
  });

  it('starts scrubbed, with no personal data and no tracing', async () => {
    const sdk = fakeSdk();

    await startErrorReporting(page('https://key@o1.ingest.sentry.io/3'), async () => sdk as never);
    reportError(new Error('boom'));

    expect(sdk.options).toEqual(
      expect.objectContaining({ sendDefaultPii: false, tracesSampleRate: 0, beforeSend: scrubEvent, beforeBreadcrumb: scrubBreadcrumb }),
    );
    expect(sdk.captured).toHaveLength(1);
  });

  it('never sends the token a held ticket is opened with', () => {
    const scrubbed = scrubEvent({ request: { url: 'https://localhost/tickets/Tk_8f3kQ2mZr9LwX1vB' } });

    expect(scrubbed.request?.url).toBe('https://localhost/tickets/[redacted]');
  });

  it('never sends a door link, where it happened or where it came from', () => {
    const scrubbed = scrubEvent({
      request: { url: 'capacitor://localhost/door-pass/Dp_s3cr3tPass' },
      breadcrumbs: [{ message: 'navigation', data: { from: '/door-pass/Dp_s3cr3tPass', to: '/door' } }],
    });

    expect(scrubbed.request?.url).toBe('capacitor://localhost/door-pass/[redacted]');
    expect(scrubbed.breadcrumbs?.[0].data).toEqual({ from: '/door-pass/[redacted]', to: '/door' });
  });

  it('takes the credential out of every route that is opened with one', () => {
    const opened = pathsWithCredentials(routes);

    // The walk found the link it is here for.
    expect(opened).toContain('door-pass/:secret');

    for (const path of opened) {
      expect(scrubUrl(address(path)), `/${path} sends its credential: add it to SECRET_AFTER`).not.toContain(PLANTED);
    }
  });
});

/** Route parameters that are the credential: a link's token or secret. */
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
