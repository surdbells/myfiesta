import { ErrorHandler, Injectable } from '@angular/core';
import {
  type ErrorReportingConfig,
  readErrorReportingConfig,
  scrubBreadcrumb,
  scrubEvent,
} from '@myfiesta/shared/error-reporting';

/**
 * Errors in the browser, sent to Sentry — when the page says where.
 *
 * The server stamps the DSN into the page (server.ts), like the API address.
 * With none, nothing here does anything: the SDK is never even downloaded,
 * because it is only fetched once there is a DSN to give it. So development,
 * tests and any deployment that has not set one send nothing anywhere.
 *
 * Never on the server. main.ts is the browser's entry point and the only
 * caller of startErrorReporting(); a server render that fails is the Node
 * process's to log, and there is no browser SDK to hand it to.
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
    // Failing to load the reporter must never become an error of its own.
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
    // Errors only. No performance tracing and no session replay are loaded,
    // so neither can record what somebody typed into a checkout.
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
