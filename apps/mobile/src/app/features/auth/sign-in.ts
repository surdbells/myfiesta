import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Api, ApiError } from '../../core/api';
import { SessionStore } from '../../core/session';
import { MfButton, MfField } from '../../ui';

/**
 * Signing in.
 *
 * Buying needs no account, so this is not a gate in front of the product — it
 * is how somebody reaches tickets they already hold, or the events they run.
 * Said on the screen, because an app that opens on a password box reads as one
 * you cannot use without an account.
 */
@Component({
  selector: 'mf-sign-in',
  imports: [FormsModule, MfField, MfButton],
  template: `
    <main class="wrap">
      <header class="brand">
        <img src="brand/mark-192.png" alt="" width="52" height="52" />
        <h1>myFiesta</h1>
        <p class="sub">Your tickets, your door, your night.</p>
      </header>

      <form (ngSubmit)="submit()">
        @if (error(); as message) {
          <p class="error" role="alert">{{ message }}</p>
        }

        <mf-field label="Email">
          <input
            #control
            name="email"
            type="email"
            inputmode="email"
            autocomplete="email"
            autocapitalize="off"
            autocorrect="off"
            spellcheck="false"
            enterkeyhint="next"
            [ngModel]="email()"
            (ngModelChange)="email.set($event)"
          />
        </mf-field>

        <mf-field label="Password">
          <input
            #control
            name="password"
            type="password"
            autocomplete="current-password"
            enterkeyhint="go"
            [ngModel]="password()"
            (ngModelChange)="password.set($event)"
          />
        </mf-field>

        <button
          mfButton
          type="submit"
          size="lg"
          block
          label="Signing in…"
          [loading]="busy()"
          [disabled]="!email().trim() || !password()"
        >
          Sign in
        </button>
      </form>

      <p class="note">
        Buying a ticket needs no account. Sign in to see tickets you already hold, or to run your
        events.
      </p>

      <p class="note">
        Working a door tonight? Open the link you were sent — it turns this phone into the scanner
        for that event.
      </p>
    </main>
  `,
  styles: `
    .wrap {
      display: grid;
      align-content: center;
      gap: var(--space-6);
      min-height: 100dvh;
      padding: calc(var(--mf-safe-top) + var(--space-6)) var(--space-6)
        calc(var(--mf-safe-bottom) + var(--space-6));
      background: var(--surface);
    }

    .brand {
      display: grid;
      justify-items: center;
      gap: var(--space-2);
      text-align: center;
    }

    .brand img {
      border-radius: var(--radius-lg);
    }

    h1 {
      font-size: var(--font-size-3xl);
      letter-spacing: var(--font-tracking-tight);
    }

    .sub {
      color: var(--text-muted);
    }

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

    .note {
      color: var(--text-muted);
      font-size: var(--font-size-sm);
      line-height: var(--font-leading-snug);
      text-align: center;
    }
  `,
})
export class SignIn {
  private readonly api = inject(Api);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);

  readonly email = signal('');
  readonly password = signal('');
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);

  async submit(): Promise<void> {
    if (this.busy()) return;

    this.busy.set(true);
    this.error.set(null);

    try {
      const body = await this.api.signIn(this.email().trim(), this.password());
      const session = await this.session.startFromLogin(body);

      await this.router.navigate([session.scope === 'organizer' ? '/events' : '/tickets'], {
        replaceUrl: true,
      });
    } catch (error) {
      // ApiError has already decided what is safe to show: a 4xx message is
      // written for the reader, a 5xx one is not repeated.
      this.error.set(
        error instanceof ApiError ? error.message : 'That did not work. Try again.',
      );
      this.busy.set(false);
    }
  }
}
