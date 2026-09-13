import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { UiAlert, UiButton, UiField } from '@myfiesta/ui';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';

/**
 * Signing up as an organizer.
 *
 * Asks for the organization name here rather than on a second screen. An
 * organizer with no organization has nowhere to put an event, and the moment
 * somebody has decided to join is the cheapest moment to ask.
 */
@Component({
  selector: 'app-register',
  imports: [FormsModule, RouterLink, UiButton, UiField, UiAlert],
  templateUrl: './register.html',
})
export class Register {
  private readonly api = inject(Api);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  /**
   * An invitation being accepted by signing up.
   *
   * The organization question disappears — they are joining one, not making
   * one — and the email is the address the invitation was sent to, since the
   * server will accept no other.
   */
  readonly invitation = this.route.snapshot.queryParamMap.get('invitation');

  readonly form = signal({
    name: '',
    email: this.route.snapshot.queryParamMap.get('email') ?? '',
    organization: '',
    password: '',
    confirm: '',
  });

  readonly busy = signal(false);
  readonly error = signal<string | null>(null);
  readonly sent = signal(false);

  /**
   * Checked here as well as on the server, purely so somebody finds out before
   * submitting rather than after. The server's rule is the one that counts.
   */
  readonly passwordHint = computed(() => {
    const password = this.form().password;

    if (password === '') return null;
    if (password.length < 10) return 'At least 10 characters.';
    if (!/[a-zA-Z]/.test(password) || !/\d/.test(password)) {
      return 'Needs at least one letter and one number.';
    }

    return null;
  });

  readonly mismatch = computed(() => {
    const { password, confirm } = this.form();

    return confirm !== '' && password !== confirm;
  });

  submit(): void {
    if (this.busy() || this.mismatch() || this.passwordHint()) return;

    const form = this.form();

    this.busy.set(true);
    this.error.set(null);

    this.api
      .register({
        name: form.name.trim(),
        email: form.email.trim(),
        ...(this.invitation ? { invitation: this.invitation } : { organization: form.organization.trim() }),
        password: form.password,
        password_confirmation: form.confirm,
      })
      .subscribe({
        next: (session) => {
          this.busy.set(false);

          /*
           * A 202 with no token means the address already has an account. The
           * server will not say so, and neither does this — the same wording
           * covers both, because "that email is taken" is how somebody tests
           * whether a named venue is on the platform.
           */
          if (!session.token) {
            this.sent.set(true);

            return;
          }

          this.session.start(session);
          void this.router.navigateByUrl('/events');
        },
        error: (response) => {
          this.busy.set(false);
          this.error.set(messageFor(response, 'That account could not be created.'));
        },
      });
  }
}
