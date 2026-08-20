import { HttpErrorResponse } from '@angular/common/http';

/**
 * Turns a failed request into something worth showing a person.
 *
 * The rule is which side of 500 the status falls on.
 *
 * A 4xx message is written for whoever made the request — "that code is not
 * valid for this event", "those details do not match an account" — and is
 * shown as-is.
 *
 * A 5xx message is written for us. Passing it through is how a database error
 * ends up on screen complete with its SQL, host and port, or how a payment
 * provider's "you did not provide an API key" gets shown to a buyer as though
 * they had done something wrong. Both happened here before this existed.
 * Server-side detail is replaced with something the reader can act on, and the
 * original goes to the console for whoever is debugging.
 */
export function messageFor(error: unknown, fallback = 'Something went wrong.'): string {
  if (!(error instanceof HttpErrorResponse)) {
    return fallback;
  }

  // The browser could not reach the server at all: no status, no body.
  if (error.status === 0) {
    return 'Could not reach the server. Check your connection and try again.';
  }

  if (error.status >= 500) {
    // Kept for whoever is looking, and kept off the screen.
    console.error('Server error', error.status, error.error);

    // One exception, and it has to be opted into explicitly.
    //
    // Some 5xx responses are authored rather than accidental: a refund the
    // payment processor declined is a 502 because nothing about the request
    // was wrong, and the organizer still needs to be told what happened. The
    // server marks those with `display`, so the rule stays "server detail
    // never leaks" rather than becoming "unless the status looks deliberate" —
    // a distinction no status code can carry on its own.
    const body = error.error;

    if (body?.display === true && typeof body?.message === 'string' && body.message.trim() !== '') {
      return body.message;
    }

    return 'Something went wrong at our end. Nothing you did caused it — please try again.';
  }

  if (error.status === 429) {
    return 'Too many attempts. Wait a moment and try again.';
  }

  const body = error.error;

  if (typeof body?.message === 'string' && body.message.trim() !== '') {
    return body.message;
  }

  // Laravel validation: the first message is the one to lead with, since the
  // form will highlight the rest.
  const errors = body?.errors;

  if (errors && typeof errors === 'object') {
    const first = Object.values(errors as Record<string, string[]>)[0]?.[0];

    if (typeof first === 'string') {
      return first;
    }
  }

  return fallback;
}
