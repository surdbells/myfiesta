import { framingHeaders } from './framing';

/**
 * The headers every response from the public site carries.
 *
 * The Content-Security-Policy allows what the site actually loads and nothing
 * else: its own scripts, styles and fonts; the API it calls; pictures from
 * wherever posters are stored. The only inline scripts that run are the ones
 * Angular writes into a page as it renders it — the few lines that catch a tap
 * before the page has come alive, and the loader for the rest of the
 * stylesheet — and each of those carries this response's nonce. A script that
 * found its way into an event's description has no nonce, so it does not run.
 *
 * Who may frame a page is decided in framing.ts and folded in here: nobody,
 * except for the embedded checkout, which any site may frame.
 */
export interface PolicyContext {
  /** Fresh for every response. See server.ts and app.config.server.ts. */
  nonce: string;

  /** Where the browser half calls, from API_BASE_URL. Pictures on the API's own disk come from here too. */
  apiOrigin: string;

  /**
   * Where browser errors are reported, from SENTRY_DSN: the ingest origin
   * only. Absent when no DSN is set, and then nothing is allowed for it.
   */
  errorReportingOrigin?: string;

  /** Under `ng serve`, whose reloads arrive over a websocket to this same host. */
  devServerHost?: string;

  /** Whether the load balancer said this was https. */
  https?: boolean;
}

export function securityHeaders(path: string, context: PolicyContext): Record<string, string> {
  const framing = framingHeaders(path);

  const policy = [
    "default-src 'self'",
    `script-src 'self' 'nonce-${context.nonce}'`,
    // Inline styles are allowed. Angular writes component styles into the
    // page, and a style cannot run anything.
    "style-src 'self' 'unsafe-inline'",
    // Posters are on this API's disk or in a bucket whose address only the API
    // knows, so any https address. A picture cannot run anything either. On a
    // laptop they come from the API's APP_URL, which is plain http and not
    // always spelled the way API_BASE_URL is.
    [
      "img-src 'self' data: blob: https:",
      context.apiOrigin,
      ...(context.devServerHost ? ['http://localhost:*', 'http://127.0.0.1:*'] : []),
    ].join(' '),
    "font-src 'self' data:",
    [
      "connect-src 'self'",
      context.apiOrigin,
      // Error reports, when there is somewhere to send them.
      ...(context.errorReportingOrigin ? [context.errorReportingOrigin] : []),
      ...(context.devServerHost ? [`ws://${context.devServerHost}`] : []),
    ].join(' '),
    // Nothing here puts another page inside itself. Paying happens on the
    // processor's own page, in this tab or a new one, never in a frame.
    "frame-src 'none'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    framing['Content-Security-Policy'],
  ].join('; ');

  return {
    ...framing,
    'Content-Security-Policy': policy,
    'X-Content-Type-Options': 'nosniff',
    // Ticket and order pages carry their token in the path. Another site told
    // only this origin is never told the token.
    'Referrer-Policy': 'strict-origin-when-cross-origin',
    // Sharing and copying a link stay allowed; nothing here wants the rest.
    'Permissions-Policy': 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
    // Not includeSubDomains: this is the bare domain, and that would bind every
    // name under it to https for a year, including ones nobody here runs.
    ...(context.https ? { 'Strict-Transport-Security': 'max-age=31536000' } : {}),
  };
}
