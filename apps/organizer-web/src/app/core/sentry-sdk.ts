/**
 * The two things error-reporting.ts uses from Sentry, and only those.
 *
 * Imported lazily, and by name, so the chunk it becomes holds the error
 * reporter and nothing else of the SDK — no session replay, no tracing, no
 * feedback widget. Importing the package itself lazily would bring all of it.
 */
export { captureException, init } from '@sentry/angular';
