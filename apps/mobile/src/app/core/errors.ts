import { ApiError } from './api';

/**
 * What to say when something did not work.
 *
 * The API's own sentence when it wrote one for the person holding the phone —
 * the client already refuses to pass a server error through — and a plain
 * fallback otherwise, never a stack trace or "undefined".
 */
export function messageOf(error: unknown, fallback = 'That did not work. Try again.'): string {
  if (error instanceof ApiError) return error.message;

  return fallback;
}

/**
 * The refusal that means "prove your address first".
 *
 * Putting a night on sale, asking to be paid and changing where payouts go
 * wait for it. The shell answers it with a prompt that can send the link
 * again (EmailVerification), so a screen that meets it says nothing itself.
 */
export function isEmailUnverified(error: unknown): boolean {
  return error instanceof ApiError && error.status === 403 && error.code === 'email_unverified';
}

/** The field-by-field complaints a 422 carries, keyed by field. */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError) || error.status !== 422 || !error.fields) return {};

  const out: Record<string, string> = {};

  for (const [field, messages] of Object.entries(error.fields)) {
    if (messages[0]) out[field] = messages[0];
  }

  return out;
}
