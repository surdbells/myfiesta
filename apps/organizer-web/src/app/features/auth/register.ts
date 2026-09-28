import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { UiAlert, UiButton, UiField } from '@myfiesta/ui';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';
import { SITE_URL } from '../../core/site-url';
import { AuthStage } from '../../shared/auth-stage';

/**
 * Signing up as an organizer.
 *
 * Asks for the organization name here rather than on a second screen. An
 * organizer with no organization has nowhere to put an event, and the moment
 * somebody has decided to join is the cheapest moment to ask.
 */
@Component({
  selector: 'app-register',
  imports: [AuthStage, FormsModule, RouterLink, UiButton, UiField, UiAlert],
  templateUrl: './register.html',
})
export class Register {
  private readonly api = inject(Api);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  /**
   * The public site, where the terms, privacy and refund pages live. The
   * console serves none of them, so the links name the site's host.
   */
  readonly site = inject(SITE_URL);

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
    /**
     * The terms, privacy and refund policies, agreed to. Starts unticked: a
     * box ticked for somebody is not somebody agreeing. The server refuses
     * the sign-up without it and keeps which version was agreed to.
     */
    agreed: false,
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
    if (this.busy() || this.mismatch() || this.passwordHint() || !this.form().agreed) return;

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
        accept_terms: form.agreed,
      })
      .subscribe({
        next: (session) => {
          this.busy.set(false);

          /*
           * A 202 with no token is every sign-up without an invitation: the
           * account is made when the link emailed to the address is opened,
           * and the same email says so if the address already has one. The
           * answer is the same for a new address and a known one, and so is
           * this screen — "that email is taken" is how somebody tests whether
           * a named venue is on the platform.
           *
           * Only joining by invitation comes back with a token: that link was
           * opened from the inbox already, so the account is made at once.
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
