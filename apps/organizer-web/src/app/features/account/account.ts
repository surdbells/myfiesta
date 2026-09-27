import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { ToastStore, UiAlert, UiButton, UiField, UiModal, UiPageHeader } from '@myfiesta/ui';
import { Api } from '../../core/api';
import type { AccountErasurePreview } from '../../core/api.types';
import { EmailVerification } from '../../core/email-verification';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';
import { SITE_URL } from '../../core/site-url';

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
 *
 * And deleting it: the privacy page's erasure, asked for signed in, the same
 * as the phone app's "Delete my account".
 */
@Component({
  selector: 'app-account',
  imports: [FormsModule, UiPageHeader, UiField, UiButton, UiAlert, UiModal],
  templateUrl: './account.html',
})
export class Account {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  private readonly router = inject(Router);
  private readonly verification = inject(EmailVerification);
  readonly session = inject(SessionStore);
  readonly site = inject(SITE_URL).replace(/\/+$/, '');

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
        // Opened after following the link: the banner goes now, not at the
        // next reload.
        if (typeof me.email_verified === 'boolean') this.verification.learn(me.email_verified);
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

  // --- deleting the account ---------------------------------------------------

  readonly deleteOpen = signal(false);
  readonly erasure = signal<AccountErasurePreview | null>(null);
  readonly erasureError = signal<string | null>(null);
  readonly deletePassword = signal('');
  readonly deletePasswordError = signal<string | null>(null);
  readonly deleteError = signal<string | null>(null);
  readonly deleting = signal(false);

  /** "We sent a link": an address never proved has to be, before anything goes. */
  readonly deletePending = signal<string | null>(null);

  /** The organizations this person would leave, and those they would leave with nobody in charge. */
  readonly leaving = computed(() => this.erasure()?.organizations ?? []);
  readonly stranded = computed(() => this.leaving().filter((organization) => organization.only_owner));
  readonly leavingNames = computed(() => this.leaving().map((organization) => organization.name).join(', '));

  readonly canDelete = computed(
    () => !!this.erasure() && !this.erasure()?.refused && this.deletePassword() !== '' && !this.deleting() && !this.deletePending(),
  );

  /**
   * Opens with what would happen, asked of the server first — including
   * whether it would be refused, which is better known before a password is
   * typed than after.
   */
  openDelete(): void {
    this.erasure.set(null);
    this.erasureError.set(null);
    this.deletePassword.set('');
    this.deletePasswordError.set(null);
    this.deleteError.set(null);
    this.deletePending.set(null);
    this.deleteOpen.set(true);

    this.api.erasurePreview().subscribe({
      next: (preview) => this.erasure.set(preview),
      error: (response) => this.erasureError.set(messageFor(response, 'Could not work out what deleting would involve.')),
    });
  }

  /** The password typed here does not outlive the dialog. */
  closeDelete(): void {
    this.deleteOpen.set(false);
    this.deletePassword.set('');
  }

  /** To the team screen of an organization somebody else has to be made an owner of. */
  handOver(organizationId: string): void {
    this.closeDelete();
    this.session.select(organizationId);
    void this.router.navigate(['/team']);
  }

  deleteAccount(): void {
    if (!this.canDelete()) return;

    this.deleting.set(true);
    this.deletePasswordError.set(null);
    this.deleteError.set(null);

    this.api.eraseAccount(this.deletePassword()).subscribe({
      next: (result) => {
        this.deleting.set(false);
        this.deletePassword.set('');

        if (result.status === 'pending') {
          this.deletePending.set(result.message);
          return;
        }

        // Every token the account had is gone, this one included: there is
        // nothing to sign out of, only a session to forget.
        this.deleteOpen.set(false);
        this.verification.forget();
        this.session.clear();
        this.toasts.show(result.message, 'success');
        void this.router.navigate(['/sign-in'], { replaceUrl: true });
      },
      error: (response: unknown) => {
        this.deleting.set(false);

        const body = response instanceof HttpErrorResponse ? response.error : null;

        if (body?.errors?.current_password?.[0]) {
          this.deletePasswordError.set(body.errors.current_password[0]);
          return;
        }

        // Somebody became the only owner of something since the dialog
        // opened. Nothing happened; the reason is the server's.
        if (response instanceof HttpErrorResponse && response.status === 409 && typeof body?.message === 'string') {
          const preview = this.erasure();
          if (preview) this.erasure.set({ ...preview, refused: body.message });
          return;
        }

        this.deleteError.set(messageFor(response, 'Your account could not be deleted. Nothing has changed.'));
      },
    });
  }
}
