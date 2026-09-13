import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastStore, UiAlert, UiButton, UiField, UiPageHeader } from '@myfiesta/ui';
import { Api } from '../../core/api';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';

/**
 * The signed-in person's own account: their name and their password.
 *
 * Both endpoints existed with nothing in the console to reach them, so the
 * only way to change a password was to forget it and reset it — which signs
 * out every device, when the person only wanted a better password.
 *
 * The address is shown and not editable. Changing where an account is reached
 * needs confirming at the new address first, or a borrowed laptop is enough to
 * take an account over; the API refuses it for the same reason.
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
