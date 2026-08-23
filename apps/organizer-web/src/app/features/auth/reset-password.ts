import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { UiAlert, UiButton, UiField } from '@myfiesta/ui';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { messageFor } from '../../core/errors';

/**
 * Setting a new password from an emailed link.
 *
 * The token and the address arrive in the query string, so this screen never
 * asks for either — somebody who has just been locked out should not also have
 * to remember which address they signed up with.
 */
@Component({
  selector: 'app-reset-password',
  imports: [FormsModule, RouterLink, UiButton, UiField, UiAlert],
  templateUrl: './reset-password.html',
  styleUrl: './sign-in.css',
})
export class ResetPassword {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  private readonly params = this.route.snapshot.queryParamMap;

  readonly token = this.params.get('token') ?? '';
  readonly email = this.params.get('email') ?? '';

  readonly password = signal('');
  readonly confirm = signal('');
  readonly busy = signal(false);
  readonly done = signal(false);
  readonly error = signal<string | null>(null);

  /** A link that arrived without its token is not worth showing a form for. */
  readonly usable = computed(() => this.token !== '' && this.email !== '');

  readonly passwordHint = computed(() => {
    const password = this.password();

    if (password === '') return null;
    if (password.length < 10) return 'At least 10 characters.';
    if (!/[a-zA-Z]/.test(password) || !/\d/.test(password)) {
      return 'Needs at least one letter and one number.';
    }

    return null;
  });

  readonly mismatch = computed(() => this.confirm() !== '' && this.password() !== this.confirm());

  submit(): void {
    if (this.busy() || this.mismatch() || this.passwordHint()) return;

    this.busy.set(true);
    this.error.set(null);

    this.api
      .resetPassword({
        token: this.token,
        email: this.email,
        password: this.password(),
        password_confirmation: this.confirm(),
      })
      .subscribe({
        next: () => {
          this.busy.set(false);
          this.done.set(true);
          // Deliberately not signed in automatically. The server revoked every
          // token as part of the reset, and signing in once proves the new
          // password is the one they think it is.
          setTimeout(() => void this.router.navigateByUrl('/sign-in'), 2500);
        },
        error: (response) => {
          this.busy.set(false);
          this.error.set(messageFor(response, 'That link has expired. Ask for a new one.'));
        },
      });
  }
}
