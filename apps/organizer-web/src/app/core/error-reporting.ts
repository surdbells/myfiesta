import { ErrorHandler, Injectable } from '@angular/core';
import {
  type ErrorReportingConfig,
  readErrorReportingConfig,
  scrubBreadcrumb,
  scrubEvent,
} from '@myfiesta/shared/error-reporting';

/**
 * Errors in the console, sent to Sentry — when the page says where.
 *
 * The container stamps the DSN into index.html as it starts
 * (ops/docker/console-entrypoint.sh), like the API address. With none, nothing
 * here does anything and the SDK is never downloaded. The same arrangement as
 * the public site's (apps/web/src/app/core/error-reporting.ts); what the two
 * must agree on — what is taken out of a report — is shared.
 *
 * The console's addresses carry more than most: a staff session arrives as a
 * code in the fragment, and the door works from event ids. The scrubber
 * removes the fragment outright.
 */

type Sdk = typeof import('./sentry-sdk');

let started = false;
let sdk: Sdk | null = null;

/** Errors raised while the SDK was still on its way, sent once it lands. */
const early: unknown[] = [];

export function startErrorReporting(
  doc: Pick<Document, 'querySelector'>,
  load: () => Promise<Sdk> = () => import('./sentry-sdk'),
): Promise<boolean> {
  const config = readErrorReportingConfig(doc);

  if (config === null || started) return Promise.resolve(false);

  started = true;

  return load().then(
    (Sentry) => {
      Sentry.init(sentryOptions(config));
      sdk = Sentry;

      for (const error of early.splice(0)) Sentry.captureException(error);

      return true;
    },
    // Failing to load the reporter must never become an error of its own —
    // least of all on a door phone with one bar of signal.
    () => false,
  );
}

/** Exactly what the SDK is started with. */
export function sentryOptions(config: ErrorReportingConfig) {
  return {
    dsn: config.dsn,
    environment: config.environment,
    release: config.release,
    // No addresses, no cookies, no signed-in person.
    sendDefaultPii: false,
    // Errors only: no tracing and no session replay, which would record the
    // payout form as somebody filled it in.
    tracesSampleRate: 0,
    beforeSend: scrubEvent,
    beforeBreadcrumb: scrubBreadcrumb,
  };
}

export function reportError(error: unknown): void {
  if (sdk !== null) {
    sdk.captureException(error);
  } else if (started && early.length < 20) {
    early.push(error);
  }
}

/** For the spec: back to a page that has not started anything. */
export function resetErrorReportingForTests(): void {
  started = false;
  sdk = null;
  early.length = 0;
}

/**
 * Angular's own handler — which writes the error to the console — and then
 * the report. Inert until startErrorReporting() has found a DSN.
 */
@Injectable()
export class ReportingErrorHandler extends ErrorHandler {
  override handleError(error: unknown): void {
    super.handleError(error);
    reportError(error);
  }
}
