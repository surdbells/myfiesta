import { securityHeaders } from './security-headers';

/**
 * The public site's policy: what may run, what may be called, who may frame.
 *
 * Checked in a browser too, with no violations on the home, event, organizer
 * and embedded pages. These hold the parts a later edit is likeliest to undo.
 */
describe('securityHeaders', () => {
  const context = { nonce: 'abc123==', apiOrigin: 'https://api.myfiesta.ca' };

  function directive(policy: string, name: string): string {
    return policy.split('; ').find((part) => part.startsWith(`${name} `)) ?? '';
  }

  it('runs only its own scripts and the ones carrying this response’s nonce', () => {
    const scripts = directive(securityHeaders('/', context)['Content-Security-Policy'], 'script-src');

    expect(scripts).toBe("script-src 'self' 'nonce-abc123=='");
    expect(scripts).not.toContain('unsafe-inline');
    expect(scripts).not.toContain('unsafe-eval');
  });

  it('calls the API and nothing else', () => {
    const policy = securityHeaders('/afro-fest', context)['Content-Security-Policy'];

    expect(directive(policy, 'connect-src')).toBe("connect-src 'self' https://api.myfiesta.ca");
    expect(directive(policy, 'frame-src')).toBe("frame-src 'none'");
    expect(directive(policy, 'object-src')).toBe("object-src 'none'");
  });

  it('lets any site frame the embedded checkout and nothing else', () => {
    expect(directive(securityHeaders('/embed/afro-fest', context)['Content-Security-Policy'], 'frame-ancestors')).toBe(
      'frame-ancestors *',
    );
    expect(securityHeaders('/embed/afro-fest', context)['X-Frame-Options']).toBeUndefined();

    for (const path of ['/', '/afro-fest/checkout', '/tickets/abc', '/order/ABC123']) {
      const headers = securityHeaders(path, context);

      expect(directive(headers['Content-Security-Policy'], 'frame-ancestors')).toBe("frame-ancestors 'self'");
      expect(headers['X-Frame-Options']).toBe('SAMEORIGIN');
    }
  });

  it('lets error reports out only to the ingest origin a DSN names, and only when there is one', () => {
    expect(directive(securityHeaders('/', context)['Content-Security-Policy'], 'connect-src')).not.toContain('sentry');

    const policy = securityHeaders('/', { ...context, errorReportingOrigin: 'https://o123.ingest.us.sentry.io' })[
      'Content-Security-Policy'
    ];

    expect(directive(policy, 'connect-src')).toBe("connect-src 'self' https://api.myfiesta.ca https://o123.ingest.us.sentry.io");
    // Reports are sent, never loaded: nothing else widens.
    expect(directive(policy, 'script-src')).toBe("script-src 'self' 'nonce-abc123=='");
  });

  it('allows the dev server its reload socket only under ng serve', () => {
    expect(securityHeaders('/', context)['Content-Security-Policy']).not.toContain('ws://');
    expect(
      directive(securityHeaders('/', { ...context, devServerHost: 'localhost:4320' })['Content-Security-Policy'], 'connect-src'),
    ).toContain('ws://localhost:4320');
  });

  it('insists on https only when it was https', () => {
    expect(securityHeaders('/', context)['Strict-Transport-Security']).toBeUndefined();
    expect(securityHeaders('/', { ...context, https: true })['Strict-Transport-Security']).toContain('max-age=');
  });

  it('keeps tokens in ticket addresses from reaching other sites', () => {
    expect(securityHeaders('/tickets/abc', context)['Referrer-Policy']).toBe('strict-origin-when-cross-origin');
    expect(securityHeaders('/', context)['X-Content-Type-Options']).toBe('nosniff');
  });

  it('lets in YouTube’s no-cookie player on the how-to videos page, and nothing else there', () => {
    for (const path of ['/help/videos', '/help/videos/']) {
      expect(directive(securityHeaders(path, context)['Content-Security-Policy'], 'frame-src')).toBe(
        'frame-src https://www.youtube-nocookie.com',
      );
    }

    // Still framed by nobody else, and still running only its own scripts.
    const policy = securityHeaders('/help/videos', context)['Content-Security-Policy'];
    expect(directive(policy, 'frame-ancestors')).toBe("frame-ancestors 'self'");
    expect(directive(policy, 'script-src')).toBe("script-src 'self' 'nonce-abc123=='");
  });

  it('frames nothing on every other page, the help page and lookalikes of the videos page included', () => {
    for (const path of ['/', '/help', '/help/videos/extra', '/help/videosx', '/afro-fest', '/embed/afro-fest', '/o/lagos-nights']) {
      expect(directive(securityHeaders(path, context)['Content-Security-Policy'], 'frame-src')).toBe("frame-src 'none'");
    }
  });
});
