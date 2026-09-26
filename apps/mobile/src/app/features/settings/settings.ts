import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { SessionStore } from '../../core/session';
import { Theme, ThemeChoice } from '../../core/theme';
import { Reminders } from '../../core/reminders';
import { Api, Ticket } from '../../core/api';
import { messageOf, fieldErrors } from '../../core/errors';
import {
  MfButton,
  MfCard,
  MfField,
  MfScreen,
  MfSegmented,
  MfSelect,
  MfSheet,
  MfSwitch,
  ToastStore,
  type MfOption,
  type MfSegment,
} from '../../ui';

/**
 * The account, the theme, and the way out.
 *
 * Short on purpose: a phone settings page that goes on for a page and a half
 * is one nobody reads. What an organization configures lives under Manage;
 * this is the person — their name, their address, their password, how the app
 * looks.
 */
@Component({
  selector: 'mf-settings',
  imports: [MfScreen, MfCard, MfButton, MfField, MfSegmented, MfSelect, MfSheet, MfSwitch],
  template: `
    <mf-screen title="Settings" large>
      <mf-card>
        <p class="label">Signed in as</p>
        <h2>{{ session.session()?.name }}</h2>
        @if (session.session()?.email; as email) {
          <p class="muted">{{ email }}</p>
        }
        @if (!session.locked()) {
          <div class="account-actions">
            <button mfButton size="sm" variant="secondary" [loading]="loadingDetails()" (click)="editDetails()">Your details</button>
            <button mfButton size="sm" variant="secondary" (click)="startEmail()">Change email</button>
            <button mfButton size="sm" variant="secondary" (click)="startPassword()">Change password</button>
          </div>
        }
      </mf-card>

      @if (reminders.available && !session.locked()) {
        <mf-card class="block">
          <p class="label">Reminders</p>
          <mf-switch
            label="Remind me before doors"
            hint="Three hours before a night you hold a ticket for. Scheduled on this phone, so it arrives with or without signal."
            [checked]="reminders.on() === true"
            (changed)="setReminders($event)"
          />
          @if (reminders.refused()) {
            <p class="hint muted">
              Your phone is not letting this app send notifications. Turn them on in its settings and try again.
            </p>
          }
        </mf-card>
      }

      @if (!session.locked()) {
        <mf-card class="block">
          <p class="label">Your lists</p>
          <button mfButton class="mt" variant="secondary" block (click)="go('/saved')">Saved events</button>
          <button mfButton class="mt" variant="secondary" block (click)="go('/following')">Following</button>
          <p class="hint muted">
            Nights you kept for later, and organizers you hear from. Yours alone — an organizer is told how
            many follow them, never who.
          </p>
        </mf-card>
      }

      <mf-card class="block">
        <p class="label">Appearance</p>
        <mf-segmented
          class="tabs"
          ariaLabel="Appearance"
          [segments]="themes"
          [value]="theme.choice()"
          (valueChange)="choose($event)"
        />
        <p class="hint muted">
          @if (theme.choice() === 'system') {
            Following your phone, which is {{ theme.resolved() }} right now.
          } @else {
            Always {{ theme.choice() }}, whatever the phone is set to.
          }
        </p>
      </mf-card>

      @if (organizations().length > 1) {
        <mf-card class="block">
          <p class="label">Organization</p>
          <mf-select
            class="picker"
            heading="Which organization"
            subheading="What the events and money screens are about."
            [options]="organizations()"
            [value]="organizationId()"
            (valueChange)="switchOrganization($event)"
          />
        </mf-card>
      }

      <button mfButton class="block" variant="secondary" block (click)="confirming.set(true)">
        Sign out
      </button>

      <p class="version subtle">myFiesta {{ version }}</p>
    </mf-screen>

    <mf-sheet [open]="detailsOpen()" heading="Your details" subheading="To sign in with a different email address, use Change email." closable (closed)="detailsOpen.set(false)">
      <div class="form">
        <mf-field label="Name" [error]="err('name')">
          <input autocomplete="name" [value]="name()" (input)="name.set($any($event.target).value)" maxlength="120" />
        </mf-field>
        <mf-field label="Phone" optional hint="For the team to reach you on the night. Never shown to buyers." [error]="err('phone')">
          <input type="tel" inputmode="tel" autocomplete="tel" [value]="phone()" (input)="phone.set($any($event.target).value)" maxlength="32" />
        </mf-field>
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="detailsOpen.set(false)">Cancel</button>
        <button mfButton [loading]="saving()" [disabled]="!name().trim()" (click)="saveDetails()">Save</button>
      </ng-container>
    </mf-sheet>

    <mf-sheet
      [open]="emailOpen()"
      heading="Change email"
      subheading="We send a link to the new address. Nothing changes until it is opened — until then, sign in as you do now."
      closable
      (closed)="closeEmail()"
    >
      @if (emailSent(); as message) {
        <p class="sent">{{ message }}</p>
      } @else {
        <div class="form">
          <mf-field label="New email address" [error]="err('email') ?? (sameEmail() ? 'That is the address you already use.' : null)">
            <input
              type="email"
              inputmode="email"
              autocomplete="email"
              autocapitalize="off"
              spellcheck="false"
              maxlength="190"
              [value]="newEmail()"
              (input)="newEmail.set($any($event.target).value)"
            />
          </mf-field>
          <mf-field label="Current password" hint="So a phone left unlocked is not enough to move your account." [error]="err('current_password')">
            <input type="password" autocomplete="current-password" [value]="emailPassword()" (input)="emailPassword.set($any($event.target).value)" />
          </mf-field>
        </div>
      }
      <ng-container sheetFooter>
        @if (emailSent()) {
          <button mfButton (click)="closeEmail()">Done</button>
        } @else {
          <button mfButton variant="secondary" (click)="closeEmail()">Cancel</button>
          <button mfButton [loading]="saving()" [disabled]="!emailReady()" (click)="saveEmail()">Send the link</button>
        }
      </ng-container>
    </mf-sheet>

    <mf-sheet [open]="passwordOpen()" heading="Change password" subheading="Every other phone and browser signed in as you is signed out." closable (closed)="closePassword()">
      <div class="form">
        <mf-field label="Current password" [error]="err('current_password')">
          <input type="password" autocomplete="current-password" [value]="current()" (input)="current.set($any($event.target).value)" />
        </mf-field>
        <mf-field label="New password" [hint]="passwordHint()" [error]="err('password')">
          <input type="password" autocomplete="new-password" [value]="next()" (input)="next.set($any($event.target).value)" />
        </mf-field>
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="closePassword()">Cancel</button>
        <button mfButton [loading]="saving()" [disabled]="!passwordReady()" (click)="savePassword()">Change it</button>
      </ng-container>
    </mf-sheet>

    <mf-sheet
      [open]="confirming()"
      heading="Sign out?"
      subheading="Your tickets stay on your account. You will need your password to come back."
      (closed)="confirming.set(false)"
    >
      <div class="confirm">
        <button mfButton variant="danger" block label="Signing out…" [loading]="busy()" (click)="signOut()">
          Sign out
        </button>
        <button mfButton variant="ghost" block (click)="confirming.set(false)">Stay signed in</button>
      </div>
    </mf-sheet>
  `,
  styles: `
    .account-actions {
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-2);
      margin-top: var(--space-4);
    }

    .form {
      display: grid;
      gap: var(--space-4);
    }

    .sent {
      overflow-wrap: anywhere;
      color: var(--text);
    }

    .label {
      font-size: var(--font-size-xs);
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--text-muted);
    }

    .block {
      display: block;
      margin-top: var(--space-4);
    }

    .tabs {
      display: block;
      margin-top: var(--space-3);
    }

    .picker {
      margin-top: var(--space-3);
    }

    .mt {
      margin-top: var(--space-3);
    }

    .hint {
      margin-top: var(--space-3);
      font-size: var(--font-size-sm);
    }

    .confirm {
      display: grid;
      gap: var(--space-3);
      padding-top: var(--space-2);
    }

    .version {
      margin-top: var(--space-6);
      text-align: center;
      font-size: var(--font-size-xs);
    }
  `,
})
export class Settings {
  readonly session = inject(SessionStore);
  readonly theme = inject(Theme);
  readonly reminders = inject(Reminders);
  private readonly api = inject(Api);
  private readonly router = inject(Router);

  readonly version = '2.0.0';

  readonly themes: MfSegment[] = [
    { value: 'system', label: 'System' },
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
  ];

  readonly confirming = signal(false);

  private readonly toasts = inject(ToastStore);

  readonly detailsOpen = signal(false);
  readonly passwordOpen = signal(false);
  readonly emailOpen = signal(false);
  readonly loadingDetails = signal(false);
  readonly name = signal('');
  readonly phone = signal('');
  readonly current = signal('');
  readonly next = signal('');
  readonly newEmail = signal('');
  readonly emailPassword = signal('');
  readonly emailSent = signal<string | null>(null);
  readonly saving = signal(false);
  readonly errors = signal<Record<string, string>>({});

  /**
   * The number the server had when the form opened, or null for none.
   *
   * What makes an emptied box mean "take it off" rather than "leave it": a
   * number nobody could see used to survive every save, and one that was never
   * loaded must not be wiped by a save that simply did not know about it.
   */
  private readonly phoneOnFile = signal<string | null>(null);

  constructor() {
    // The address shown up top can change from a link opened on another
    // device, so it is asked for rather than taken from when this phone
    // signed in. Quietly: without signal, what the phone remembers will do.
    if (this.session.signedIn() && !this.session.locked()) void this.load();
  }

  /** The server's rule, said while typing — the same words the join screen uses. */
  readonly passwordHint = computed(() => {
    const p = this.next();
    if (p === '') return 'At least 10 characters, with a letter and a number.';
    if (p.length < 10) return 'At least 10 characters.';
    if (!/[a-zA-Z]/.test(p) || !/\d/.test(p)) return 'Needs at least one letter and one number.';
    return null;
  });

  readonly passwordReady = computed(
    () => !this.saving() && this.current() !== '' && this.next().length >= 10 && /[a-zA-Z]/.test(this.next()) && /\d/.test(this.next()),
  );

  /** The one refusal that can be seen from here, said before it is sent. */
  readonly sameEmail = computed(() => {
    const typed = this.newEmail().trim().toLowerCase();

    return typed !== '' && typed === (this.session.session()?.email ?? '').toLowerCase();
  });

  readonly emailReady = computed(
    () => !this.saving() && this.newEmail().trim().includes('@') && !this.sameEmail() && this.emailPassword() !== '',
  );
  readonly busy = signal(false);

  readonly organizations = computed<MfOption[]>(() =>
    (this.session.session()?.organizations ?? []).map((organization) => ({
      value: organization.id,
      label: organization.name,
      hint: organization.role,
    })),
  );

  readonly organizationId = computed(() => this.session.organization()?.id ?? null);

  /**
   * Turning reminders on needs the tickets, because turning them on is what
   * schedules them — a switch that only takes effect at the next refresh is a
   * switch somebody flips twice.
   */
  async setReminders(on: boolean): Promise<void> {
    let tickets: Ticket[] = [];

    if (on) {
      try {
        tickets = await this.api.tickets();
      } catch {
        // No tickets read means nothing to schedule yet. The switch still goes
        // on, and the next visit to the tickets screen fills the schedule in.
      }
    }

    await this.reminders.set(on, tickets);
  }

  choose(choice: string): void {
    void this.theme.set(choice as ThemeChoice);
  }

  async switchOrganization(id: string | null): Promise<void> {
    if (!id || id === this.organizationId()) return;

    // The session holds which organization the API answers for; everything
    // under Manage reads it from there and loads afresh on the way in.
    await this.session.chooseOrganization(id);
    await this.router.navigate(['/manage']);
  }

  err(field: string): string | null {
    return this.errors()[field] ?? null;
  }

  /** What the server holds about this person now, or null when it could not be asked. */
  private async load(): Promise<{ name: string; email: string; phone: string | null } | null> {
    try {
      const me = await this.api.me();
      await this.session.identify(me.name, me.email);

      return me;
    } catch {
      return null;
    }
  }

  /**
   * Opens with what is on file, phone number included — asked for first, so
   * the form never opens empty and then fills in under somebody's thumb.
   */
  async editDetails(): Promise<void> {
    if (this.loadingDetails()) return;

    this.loadingDetails.set(true);
    const me = await this.load();
    this.loadingDetails.set(false);

    this.name.set(me?.name ?? this.session.session()?.name ?? '');
    this.phone.set(me?.phone ?? '');
    // Unknown when the server could not be asked. Then an empty box is left
    // alone on save rather than taken as "no number".
    this.phoneOnFile.set(me ? me.phone : null);
    this.errors.set({});
    this.detailsOpen.set(true);
  }

  async saveDetails(): Promise<void> {
    this.saving.set(true);
    this.errors.set({});

    const phone = this.phone().trim();
    const body: { name: string; phone?: string | null } = { name: this.name().trim() };

    if (phone !== '') body.phone = phone;
    // Emptied a box that had a number in it: take the number off.
    else if (this.phoneOnFile()) body.phone = null;

    try {
      const saved = await this.api.updateProfile(body);
      await this.session.rename(saved.name);
      this.phoneOnFile.set(saved.phone);
      this.detailsOpen.set(false);
      this.toasts.show('Saved.', 'success');
    } catch (error) {
      this.errors.set(fieldErrors(error));
      if (!Object.keys(this.errors()).length) this.toasts.show(messageOf(error), 'danger');
    } finally {
      this.saving.set(false);
    }
  }

  startEmail(): void {
    this.newEmail.set('');
    this.emailPassword.set('');
    this.emailSent.set(null);
    this.errors.set({});
    this.emailOpen.set(true);
  }

  /** The password typed here does not outlive the sheet. */
  closeEmail(): void {
    this.emailOpen.set(false);
    this.emailPassword.set('');
  }

  async saveEmail(): Promise<void> {
    if (!this.emailReady()) return;

    this.saving.set(true);
    this.errors.set({});

    try {
      const { message } = await this.api.requestEmailChange(this.newEmail().trim(), this.emailPassword());
      this.emailPassword.set('');
      // Said in the sheet rather than a toast: which inbox to go and look in
      // is worth more than three seconds on screen.
      this.emailSent.set(message);
    } catch (error) {
      this.errors.set(fieldErrors(error));
      if (!Object.keys(this.errors()).length) this.toasts.show(messageOf(error), 'danger');
    } finally {
      this.saving.set(false);
    }
  }

  startPassword(): void {
    this.current.set('');
    this.next.set('');
    this.errors.set({});
    this.passwordOpen.set(true);
  }

  /** What was typed does not outlive the sheet. */
  closePassword(): void {
    this.passwordOpen.set(false);
    this.current.set('');
    this.next.set('');
  }

  async savePassword(): Promise<void> {
    if (!this.passwordReady()) return;

    this.saving.set(true);
    this.errors.set({});

    try {
      const { message } = await this.api.changePassword(this.current(), this.next());
      this.closePassword();
      this.toasts.show(message, 'success');
    } catch (error) {
      this.errors.set(fieldErrors(error));
      if (!Object.keys(this.errors()).length) this.toasts.show(messageOf(error), 'danger');
    } finally {
      this.saving.set(false);
    }
  }

  async signOut(): Promise<void> {
    this.busy.set(true);
    await this.session.signOut();
    await this.router.navigate(['/sign-in'], { replaceUrl: true });
  }

  go(path: string): void {
    void this.router.navigate([path]);
  }
}
