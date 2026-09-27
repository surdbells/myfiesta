import { Injectable, signal } from '@angular/core';

/**
 * Whether the signed-in account's address is proved.
 *
 * Putting an event on sale, asking to be paid and changing where payouts go
 * wait for it. GET /api/auth/me says so up front, and a 403 with the code
 * `email_unverified` says so after somebody has pressed the button; both land
 * here, and the prompt in the shell (VerifyEmail) reads it.
 *
 * State and nothing else. The interceptor reports refusals into it, and a
 * store that made requests of its own would be a client the interceptor
 * depends on while intercepting it.
 */
@Injectable({ providedIn: 'root' })
export class EmailVerification {
  /** Null until the server has said either way. */
  readonly verified = signal<boolean | null>(null);

  /**
   * The server's sentence from a refusal that just happened, while its prompt
   * is showing. It says where the link went — the refusal sends one too.
   */
  readonly refusal = signal<string | null>(null);

  learn(verified: boolean): void {
    this.verified.set(verified);
    if (verified) this.refusal.set(null);
  }

  refused(message: string | null): void {
    this.verified.set(false);
    this.refusal.set(message ?? 'Confirm your email address first. Open the link we sent you, then try again.');
  }

  /** The prompt was closed. The banner stays until the address is proved. */
  dismiss(): void {
    this.refusal.set(null);
  }

  /** Somebody else's account now, or nobody's. */
  forget(): void {
    this.verified.set(null);
    this.refusal.set(null);
  }
}
