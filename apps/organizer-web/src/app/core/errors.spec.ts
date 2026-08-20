import { HttpErrorResponse } from '@angular/common/http';
import { messageFor } from './errors';

/**
 * The rule that decides what a failed request is allowed to say.
 *
 * Written because the same bug appeared twice while building this: a payment
 * provider told a ticket buyer they had not provided an API key, and a database
 * outage put its SQL, host and port on a sign-in form. Both were server-side
 * detail, written for us, shown to somebody else, phrased as though they had
 * done something wrong.
 */
describe('messageFor', () => {
  const response = (status: number, error: unknown) => new HttpErrorResponse({ status, error });

  it('shows a 4xx message as written, because it is addressed to the reader', () => {
    const message = messageFor(
      response(422, { message: 'That code is not valid for this event.' }),
    );

    expect(message).toBe('That code is not valid for this event.');
  });

  it('leads with the first validation message', () => {
    const message = messageFor(
      response(422, { errors: { 'buyer.email': ['Enter a real email address.'] } }),
    );

    expect(message).toBe('Enter a real email address.');
  });

  it('never repeats a 5xx body, however tempting it looks', () => {
    const leak =
      'SQLSTATE[08006] connection to server at "127.0.0.1", port 55432 failed: ' +
      'Connection refused (SQL: select * from "cache")';

    const message = messageFor(response(500, { message: leak }));

    expect(message).not.toContain('SQLSTATE');
    expect(message).not.toContain('55432');
    expect(message).not.toContain('select *');
  });

  it('does not leak a payment provider error either', () => {
    const message = messageFor(
      response(502, {
        message: 'You did not provide an API key. Set it in the Authorization header.',
      }),
    );

    expect(message).not.toContain('API key');
    expect(message).not.toContain('Authorization');
  });

  it('shows a 5xx message the server explicitly marked for the reader', () => {
    // A refund the processor declined is a 502 — nothing about the request was
    // wrong — and the organizer still has to be told no money moved.
    const message = messageFor(
      response(502, {
        message: 'The payment processor would not complete this refund. No money has moved.',
        display: true,
      }),
    );

    expect(message).toContain('No money has moved');
  });

  it('still hides a 5xx that only claims to be safe by looking tidy', () => {
    // The marker is the whole mechanism. A well-phrased 5xx without it is
    // still server detail, and a leak that reads nicely is still a leak.
    const message = messageFor(
      response(502, { message: 'Upstream connect error to 10.0.3.7:5432.' }),
    );

    expect(message).not.toContain('10.0.3.7');
    expect(message).toContain('Nothing you did caused it');
  });

  it('ignores a display flag that is not exactly true', () => {
    // Nothing but the server's own marker opens this door — not a truthy
    // string that arrived from somewhere else.
    const message = messageFor(
      response(500, { message: 'Stack trace follows.', display: 'yes' }),
    );

    expect(message).not.toContain('Stack trace');
  });

  it('tells the reader a 5xx was not their fault', () => {
    // The worry when something fails mid-action is whether you broke it, or
    // whether money moved. Saying so is most of the job.
    expect(messageFor(response(500, {}))).toContain('Nothing you did caused it');
  });

  it('distinguishes not reaching the server from the server failing', () => {
    const message = messageFor(response(0, null));

    expect(message).toContain('Could not reach the server');
    expect(message).not.toContain('our end');
  });

  it('says plainly when someone is being rate limited', () => {
    expect(messageFor(response(429, {}))).toContain('Too many attempts');
  });

  it('falls back rather than showing nothing when a 4xx carries no message', () => {
    expect(messageFor(response(404, {}), 'That event could not be found.')).toBe(
      'That event could not be found.',
    );
  });

  it('handles something that is not an HTTP error at all', () => {
    expect(messageFor(new TypeError('undefined is not a function'), 'Fallback')).toBe('Fallback');
  });
});
