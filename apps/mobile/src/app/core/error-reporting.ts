import { ErrorHandler, Injectable } from '@angular/core';
import {
  type ErrorReportingConfig,
  readErrorReportingConfig,
  scrubBreadcrumb,
  scrubEvent,
} from '@myfiesta/shared/error-reporting';

/**
 * Errors in the phone app, sent to Sentry — when the build says where.
 *
 * The DSN is written into the built page before it is packaged
 * (tools/stamp-mobile-sentry.cjs), the way the API address is, with the
 * release named after the store version. With none, nothing here does anything
 * and the SDK never loads.
 *
 * This is the browser SDK inside the WebView, not @sentry/capacitor: that one
 * links a native SDK into both projects, and this project links plugins through
 * SPM, where the scanner has already shown what a plugin that cannot be linked
 * costs (tools/check-native-plugins.cjs). So a crash in native code — the
 * shell, not the app — is not reported; everything the app itself does is.
 * docs/OPERATIONS.md says so where somebody deciding about it will look.
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
    // Errors only: no tracing, no session replay.
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
