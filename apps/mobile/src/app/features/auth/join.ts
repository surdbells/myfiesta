import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Api, ApiError } from '../../core/api';
import { SessionStore } from '../../core/session';
import { MfButton, MfField, MfScreen, MfSegmented, type MfSegment } from '../../ui';
import { Navigation } from '../../core/navigation';

/**
 * Making an account, from the phone.
 *
 * Guest checkout is the primary path here, so most people arrive holding a
 * ticket and no account. This exists so the answer to "can my tickets follow
 * me to a new phone" is not "go and find the website".
 *
 * Most people signing up here are going out, so that is the default and it
 * asks nothing about a business they do not have. Somebody who puts on
 * events says so with one tap, names their events page, and lands in Manage
 * with everything the console has — the phone is a whole organizer's tool
 * now, not a window onto one.
 *
 * The server answers the same way whether or not the address is already in use
 * — saying "that email is taken" turns sign-up into a way to test who holds an
 * account — so this screen repeats that answer rather than improving on it.
 */
@Component({
  selector: 'mf-join',
  imports: [FormsModule, MfScreen, MfField, MfButton, MfSegmented],
  template: `
    <mf-screen title="Create an account" back backTo="/sign-in">
      @if (sent(); as message) {
        <p class="sent">{{ message }}</p>
        <button mfButton class="mt" size="lg" block variant="secondary" (click)="back()">
          Back to signing in
        </button>
      } @else {
        <form (ngSubmit)="submit()">
          @if (error(); as message) {
            <p class="error" role="alert">{{ message }}</p>
          }

          <mf-segmented ariaLabel="What the account is for" [segments]="kinds" [(value)]="kind" />

          <mf-field label="Your name">
            <input
              name="name"
              type="text"
              autocomplete="name"
              enterkeyhint="next"
              [ngModel]="name()"
              (ngModelChange)="name.set($event)"
            />
          </mf-field>

          @if (kind() === 'hosting') {
            <mf-field label="Your events page" hint="What buyers see — Lagos Nights, Danforth Sessions. You can change it later.">
              <input
                name="organization"
                type="text"
                autocomplete="organization"
                enterkeyhint="next"
                maxlength="120"
                [ngModel]="organization()"
                (ngModelChange)="organization.set($event)"
              />
            </mf-field>
          }
          <mf-field label="Email" [hint]="kind() === 'hosting' ? 'Where orders, payouts and your team reach you.' : 'Use the address you bought tickets with and they will be here already.'">
            <input
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

          <mf-field label="Password" [hint]="passwordHint()">
            <input
              name="password"
              type="password"
              autocomplete="new-password"
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
            label="Creating…"
            [loading]="busy()"
            [disabled]="!ready()"
          >
            Create account
          </button>
        </form>

        <p class="note">
          @if (kind() === 'hosting') {
            Your first event starts as a draft. Nothing is public until you put it on sale.
          } @else {
            You do not need an account to buy a ticket. This is so the ones you already hold follow
            you to whatever phone you are holding.
          }
        </p>
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
      margin-top: var(--space-6);
      color: var(--text-muted);
      font-size: var(--font-size-sm);
      line-height: var(--font-leading-snug);
      text-align: center;
    }

    .mt {
      margin-top: var(--space-4);
    }
  `,
})
export class Join {
  private readonly nav = inject(Navigation);
  private readonly api = inject(Api);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);

  readonly kind = signal<'going' | 'hosting'>('going');
  readonly kinds: MfSegment[] = [
    { value: 'going', label: 'Going out' },
    { value: 'hosting', label: 'Putting on events' },
  ];

  readonly organization = signal('');
  readonly name = signal('');
  readonly email = signal('');
  readonly password = signal('');
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);

  /** Set when the address was already in use: the server emailed them instead. */
  readonly sent = signal<string | null>(null);

  /**
   * Said while they type rather than after they submit.
   *
   * The same words the console uses for the same rule — the server's rule is
   * the one that counts, and two clients disagreeing about it is how somebody
   * ends up writing a password twice.
   */
  readonly passwordHint = computed(() => {
    const password = this.password();

    if (password === '') return 'At least 10 characters, with a letter and a number.';
    if (password.length < 10) return 'At least 10 characters.';
    if (!/[a-zA-Z]/.test(password) || !/\d/.test(password)) {
      return 'Needs at least one letter and one number.';
    }

    return null;
  });

  readonly ready = computed(
    () =>
      this.name().trim().length > 1 &&
      this.email().includes('@') &&
      this.strong() &&
      (this.kind() === 'going' || this.organization().trim().length > 1),
  );

  private strong(): boolean {
    const password = this.password();

    return password.length >= 10 && /\d/.test(password) && /[a-zA-Z]/.test(password);
  }

  async submit(): Promise<void> {
    if (this.busy() || !this.ready()) return;

    this.busy.set(true);
    this.error.set(null);

    try {
      const body = await this.api.register(
        this.name().trim(),
        this.email().trim(),
        this.password(),
        this.kind() === 'hosting' ? this.organization().trim() : null,
      );

      // An address already in use gets a 202 and an email, not an account and
      // not a token. The screen says what happened without saying whether the
      // address was known.
      if (typeof body['token'] !== 'string') {
        this.sent.set(
          (body['message'] as string) ?? 'Check your email to finish setting up your account.',
        );

        return;
      }

      const session = await this.session.startFromLogin(body);
      await this.router.navigateByUrl(session.scope === 'organizer' ? '/manage' : '/tickets', { replaceUrl: true });
    } catch (error) {
      this.error.set(
        error instanceof ApiError ? error.message : 'That did not work. Try again.',
      );
    } finally {
      this.busy.set(false);
    }
  }

  back(): void {
    this.nav.back('/sign-in');
  }
}
