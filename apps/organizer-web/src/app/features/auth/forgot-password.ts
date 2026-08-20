import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { messageFor } from '../../core/errors';

/**
 * Asking for a way back in.
 *
 * The confirmation is shown whatever the server says, because the server
 * deliberately answers identically for a known and an unknown address. A screen
 * that showed "no account with that address" would undo that in one line.
 */
@Component({
  selector: 'app-forgot-password',
  imports: [FormsModule, RouterLink],
  templateUrl: './forgot-password.html',
  styleUrl: './sign-in.css',
})
export class ForgotPassword {
  private readonly api = inject(Api);

  readonly email = signal('');
  readonly busy = signal(false);
  readonly sent = signal(false);
  readonly error = signal<string | null>(null);

  submit(): void {
    if (this.busy()) return;

    this.busy.set(true);
    this.error.set(null);

    this.api.forgotPassword(this.email().trim()).subscribe({
      next: () => {
        this.busy.set(false);
        this.sent.set(true);
      },
      error: (response) => {
        this.busy.set(false);
        // Only a genuine failure — the endpoint being unreachable, or the rate
        // limit. Never "we do not know that address".
        this.error.set(messageFor(response, 'That could not be sent. Try again in a moment.'));
      },
    });
  }
}
