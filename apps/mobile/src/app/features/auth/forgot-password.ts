import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Api, ApiError } from '../../core/api';
import { MfButton, MfField, MfScreen } from '../../ui';

/**
 * Asking for a reset link.
 *
 * The answer is the same whether or not the address is known, and this screen
 * repeats the server's words rather than improving on them — a friendlier
 * "we've sent it" for known addresses and "we don't know you" for the rest
 * turns this screen into a way to test who holds an account here.
 *
 * The link itself opens in a browser, because that is where a password is set.
 * Nothing about a reset belongs inside an app somebody may have been handed.
 */
@Component({
  selector: 'mf-forgot-password',
  imports: [FormsModule, MfScreen, MfField, MfButton],
  template: `
    <mf-screen title="Forgotten password" back (backed)="back()">
      @if (sent(); as message) {
        <p class="sent">{{ message }}</p>
        <p class="note">The link opens in your browser, where you can set a new one.</p>
        <button mfButton class="mt" size="lg" block variant="secondary" (click)="back()">
          Back to signing in
        </button>
      } @else {
        <form (ngSubmit)="submit()">
          @if (error(); as message) {
            <p class="error" role="alert">{{ message }}</p>
          }

          <mf-field label="Email">
            <input
              name="email"
              type="email"
              inputmode="email"
              autocomplete="email"
              autocapitalize="off"
              autocorrect="off"
              spellcheck="false"
              enterkeyhint="go"
              [ngModel]="email()"
              (ngModelChange)="email.set($event)"
            />
          </mf-field>

          <button
            mfButton
            type="submit"
            size="lg"
            block
            label="Sending…"
            [loading]="busy()"
            [disabled]="!email().includes('@')"
          >
            Send a reset link
          </button>
        </form>
      }
    </mf-screen>
  `,
  styles: `
    form {
      display: grid;
      gap: var(--space-4);
    }

    .error {
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-md);
      background: color-mix(in srgb, var(--danger) 12%, transparent);
      color: var(--danger-text);
      font-size: var(--font-size-sm);
    }

    .sent {
      margin: 0;
      color: var(--text);
      font-size: var(--font-size-base);
      line-height: var(--font-leading-snug);
    }

    .note {
      margin-top: var(--space-3);
      color: var(--text-muted);
      font-size: var(--font-size-sm);
      line-height: var(--font-leading-snug);
    }

    .mt {
      margin-top: var(--space-4);
    }
  `,
})
export class ForgotPassword {
  private readonly api = inject(Api);
  private readonly router = inject(Router);

  readonly email = signal('');
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);
  readonly sent = signal<string | null>(null);

  async submit(): Promise<void> {
    if (this.busy() || !this.email().includes('@')) return;

    this.busy.set(true);
    this.error.set(null);

    try {
      this.sent.set(await this.api.forgotPassword(this.email().trim()));
    } catch (error) {
      this.error.set(error instanceof ApiError ? error.message : 'That did not work. Try again.');
    } finally {
      this.busy.set(false);
    }
  }

  back(): void {
    void this.router.navigate(['/sign-in']);
  }
}
