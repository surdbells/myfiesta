import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastStore, UiAlert, UiButton, UiField, UiPageHeader } from '@myfiesta/ui';
import { Api } from '../../core/api';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';

/**
 * The signed-in person's own account: their name, their address and their
 * password.
 *
 * Both endpoints existed with nothing in the console to reach them, so the
 * only way to change a password was to forget it and reset it — which signs
 * out every device, when the person only wanted a better password.
 *
 * The address is not edited in place. A new one is asked for, with the current
 * password, and the account moves only when the link sent to it is opened —
 * otherwise a borrowed laptop is enough to take an account over.
 */
@Component({
  selector: 'app-account',
  imports: [FormsModule, UiPageHeader, UiField, UiButton, UiAlert],
  templateUrl: './account.html',
})
export class Account {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  readonly session = inject(SessionStore);

  constructor() {
    /*
     * What the server has now, rather than what signing in said.
     *
     * The address changes from a link that may be opened on another device,
     * and a session started before that still shows the old one — here, in the
     * sidebar, and as the account a password manager saves against.
     */
    const signedInAs = this.session.user()?.name ?? '';

    this.api.me().subscribe({
      next: (me) => {
        this.session.updateUser({ name: me.name, email: me.email });
        // Unless somebody has already started typing over it.
        if (this.name() === signedInAs) this.name.set(me.name);
      },
      // The page works from the session either way; a refused token is
      // handled where every other one is.
      error: () => undefined,
    });
  }

  // --- name -------------------------------------------------------------------

  readonly name = signal(this.session.user()?.name ?? '');
  readonly savingName = signal(false);
  readonly nameError = signal<string | null>(null);

  readonly nameChanged = computed(() => {
    const next = this.name().trim();

    return next !== '' && next !== this.session.user()?.name;
  });

  saveName(): void {
    if (!this.nameChanged() || this.savingName()) return;

    this.savingName.set(true);
    this.nameError.set(null);

    this.api.updateProfile({ name: this.name().trim() }).subscribe({
      next: (profile) => {
        this.savingName.set(false);
        this.name.set(profile.name);
        // The sidebar and every "made by" line read the session.
        this.session.updateUser({ name: profile.name, email: profile.email });
        this.toasts.show('Name saved.', 'success');
      },
      error: (response) => {
        this.savingName.set(false);
        this.nameError.set(messageFor(response, 'Your name could not be saved.'));
      },
    });
  }

  // --- email ------------------------------------------------------------------

  readonly newEmail = signal('');
  readonly emailPassword = signal('');
  readonly requesting = signal(false);
  readonly emailError = signal<string | null>(null);
  readonly newEmailError = signal<string | null>(null);
  readonly emailPasswordError = signal<string | null>(null);
  readonly emailSent = signal<string | null>(null);

  /** Said while typing: the one thing the server would refuse that can be seen from here. */
  readonly sameEmail = computed(
    () => this.newEmail().trim().toLowerCase() !== '' && this.newEmail().trim().toLowerCase() === this.session.user()?.email.toLowerCase(),
  );

  readonly canRequestEmail = computed(
    () => this.newEmail().trim().includes('@') && !this.sameEmail() && this.emailPassword() !== '',
  );

  requestEmail(): void {
    if (!this.canRequestEmail() || this.requesting()) return;

    this.requesting.set(true);
    this.emailError.set(null);
    this.newEmailError.set(null);
    this.emailPasswordError.set(null);
    this.emailSent.set(null);

    this.api.requestEmailChange({ email: this.newEmail().trim(), current_password: this.emailPassword() }).subscribe({
      next: ({ message }) => {
        this.requesting.set(false);
        // The password does not outlive the request. The address stays, so
        // somebody who mistyped it can see which one the link went to.
        this.emailPassword.set('');
        this.emailSent.set(message);
      },
      error: (response: unknown) => {
        this.requesting.set(false);

        if (!(response instanceof HttpErrorResponse)) {
          this.emailError.set(messageFor(response, 'The link could not be sent.'));
          return;
        }

        // Said with how long to wait, which the server knows and a generic
        // "too many attempts" does not.
        if (response.status === 429) {
          this.emailError.set(response.error?.errors?.email?.[0] ?? messageFor(response));
          return;
        }

        const errors = response.status === 422 ? response.error?.errors : null;

        if (errors?.current_password?.[0]) {
          this.emailPasswordError.set(errors.current_password[0]);
          return;
        }

        if (errors?.email?.[0]) {
          this.newEmailError.set(errors.email[0]);
          return;
        }

        this.emailError.set(messageFor(response, 'The link could not be sent.'));
      },
    });
  }

  // --- password ---------------------------------------------------------------

  readonly current = signal('');
  readonly password = signal('');
  readonly confirm = signal('');
  readonly changing = signal(false);
  readonly passwordError = signal<string | null>(null);
  readonly currentError = signal<string | null>(null);
  readonly changed = signal<string | null>(null);

  /** The same rule the server applies, said while typing rather than after. */
  readonly passwordHint = computed(() => {
    const password = this.password();

    if (password === '') return null;
    if (password.length < 10) return 'At least 10 characters.';
    if (!/[a-zA-Z]/.test(password) || !/\d/.test(password)) return 'Needs at least one letter and one number.';
    if (password === this.current()) return 'That is the password you have now.';

    return null;
  });

  readonly mismatch = computed(() => this.confirm() !== '' && this.password() !== this.confirm());

  readonly canChange = computed(
    () => this.current() !== '' && this.password() !== '' && this.confirm() !== '' && !this.passwordHint() && !this.mismatch(),
  );

  changePassword(): void {
    if (!this.canChange() || this.changing()) return;

    this.changing.set(true);
    this.passwordError.set(null);
    this.currentError.set(null);
    this.changed.set(null);

    this.api
      .changePassword({ current_password: this.current(), password: this.password(), password_confirmation: this.confirm() })
      .subscribe({
        next: ({ message }) => {
          this.changing.set(false);
          this.current.set('');
          this.password.set('');
          this.confirm.set('');
          this.changed.set(message);
        },
        error: (response: unknown) => {
          this.changing.set(false);

          // A wrong current password belongs next to that field, not in a
          // banner above a form that is otherwise fine.
          const errors = response instanceof HttpErrorResponse ? response.error?.errors : null;

          if (errors?.current_password?.[0]) {
            this.currentError.set(errors.current_password[0]);
            return;
          }

          this.passwordError.set(messageFor(response, 'Your password could not be changed.'));
        },
      });
  }
}
