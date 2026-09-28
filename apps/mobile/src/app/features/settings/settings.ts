import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Browser } from '@capacitor/browser';
import type { AccountErasurePreview } from '@myfiesta/api-types';
import { SessionStore } from '../../core/session';
import { Theme, ThemeChoice } from '../../core/theme';
import { Reminders } from '../../core/reminders';
import { Api, ApiError, Ticket } from '../../core/api';
import { Discover } from '../../core/discovery';
import { messageOf, fieldErrors } from '../../core/errors';
import {
  Dialogs,
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
 *
 * And the way out for good: "Delete my account", which the App Store requires
 * of an app that makes accounts. It is the privacy page's erasure, asked for
 * signed in, and says what goes and what stays before the password is typed.
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

      @if (!session.locked()) {
        <mf-card class="block">
          <p class="label">Delete your account</p>
          <p class="hint muted">
            Closes your account and takes your name, email address and phone number off everything we hold. Orders and
            tickets stay, with nobody’s name on them, because the law says we keep them.
          </p>
          <button mfButton class="mt delete-account" variant="ghost" block [loading]="loadingErasure()" (click)="startDelete()">
            Delete my account
          </button>
        </mf-card>
      }

      <p class="version subtle">myFiesta {{ version }}</p>
    </mf-screen>

    <mf-sheet
      [open]="deleteOpen()"
      [heading]="erasure()?.refused ? 'Not yet' : 'Delete your account?'"
      [subheading]="erasure()?.refused ? null : 'This cannot be undone.'"
      closable
      (closed)="closeDelete()"
    >
      @if (deletePending(); as message) {
        <p class="sent">{{ message }}</p>
      } @else if (erasure(); as preview) {
        @if (preview.refused) {
          <!-- The only owner of an organization cannot leave it with nobody in charge; a staff account waits for
               another administrator. The message says which. -->
          <p class="sent">{{ preview.refused }}</p>
          @if (stranded().length > 0) {
            @for (organization of stranded(); track organization.id) {
              <button mfButton class="mt" variant="secondary" block (click)="handOver(organization.id)">
                Open the team at {{ organization.name }}
              </button>
            }
            <p class="hint muted">
              Make somebody on it an owner, or invite one. If there is nobody to hand it to, write to us and we will
              close the organization with you. Then come back here.
            </p>
            <button mfButton class="mt" variant="ghost" block (click)="writeToUs()">Write to us</button>
          }
        } @else {
          <div class="erasure">
            <p class="label">What goes</p>
            <ul>
              <li>Your sign-in, on this phone and everywhere else.</li>
              <li>Your name, email address and phone number.</li>
              <li>Nights you saved, organizers you follow, and waitlist and guest-list places under your address.</li>
              @if (leaving()) {
                <li>Your place on the team at {{ leaving() }}. Their events, orders and money stay with them.</li>
              }
            </ul>
            <p class="label">What stays</p>
            <ul>
              <li>
                Orders, tickets and payments, with nobody’s name on them, for {{ preview.kept_for_years }} years, because
                tax and accounting law requires it. Tickets for nights still to come keep working from the link in their
                confirmation email.
              </li>
              @if (preview.history_kept) {
                <li>
                  What you did on an organizer’s team — a refund, a price change, a cancelled event — in that history,
                  under your name. Nobody can edit it afterwards, us included.
                </li>
              }
              <li>That you asked us not to email or text you, if you did, so that it stays that way.</li>
              <li>A record that you asked for this, and what we did.</li>
            </ul>
            @if (!preview.email_verified) {
              <p class="hint">
                Your address has not been confirmed, so first we email a link to {{ preview.email }}. Your account is
                deleted when you open it.
              </p>
            }
            <mf-field label="Your password" hint="So a phone left unlocked is not enough to delete you." [error]="err('current_password')">
              <input type="password" autocomplete="current-password" [value]="deletePassword()" (input)="deletePassword.set($any($event.target).value)" />
            </mf-field>
          </div>
        }
      }
      <ng-container sheetFooter>
        @if (deletePending() || erasure()?.refused) {
          <button mfButton (click)="closeDelete()">Done</button>
        } @else {
          <button mfButton variant="secondary" (click)="closeDelete()">Keep it</button>
          <button mfButton variant="danger" label="Deleting…" [loading]="deleting()" [disabled]="!deleteReady()" (click)="deleteAccount()">
            Delete
          </button>
        }
      </ng-container>
    </mf-sheet>

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

    .delete-account {
      color: var(--danger);
    }

    .erasure {
      display: grid;
      gap: var(--space-3);
    }

    .erasure ul {
      display: grid;
      gap: var(--space-2);
      padding-left: var(--space-5);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      overflow-wrap: anywhere;
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
  private readonly dialogs = inject(Dialogs);

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
    if (this.saving()) return;

    const phone = this.phone().trim();
    const body: { name: string; phone?: string | null } = { name: this.name().trim() };

    if (phone !== '') body.phone = phone;
    // Emptied a box that had a number in it: take the number off.
    else if (this.phoneOnFile()) body.phone = null;

    const sure = await this.dialogs.confirm({
      title: 'Save your details?',
      body: `Your name becomes ${body.name}${
        body.phone === null ? ', and the phone number on file is taken off' : body.phone ? `, and your phone number ${body.phone}` : ''
      }.`,
      consequences: ['It is the name on your tickets from now on, and the one your team sees.'],
      confirmLabel: 'Save details',
      tone: 'default',
    });

    if (!sure || this.saving()) return;

    this.saving.set(true);
    this.errors.set({});

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

    const next = this.newEmail().trim();
    const sure = await this.dialogs.confirm({
      title: `Move your account to ${next}?`,
      body: `A link goes to ${next}. Your account moves there only when it is opened, within the hour.`,
      consequences: [`Until then you keep signing in with ${this.session.session()?.email ?? 'the address you have now'}.`],
      confirmLabel: 'Send the link',
      tone: 'default',
    });

    if (!sure || !this.emailReady()) return;

    this.saving.set(true);
    this.errors.set({});

    try {
      const { message } = await this.api.requestEmailChange(next, this.emailPassword());
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
    if (this.busy()) return;

    // Asked first: the tickets on this phone go with the session, and
    // getting them back needs the password somebody may not have to hand at
    // a door.
    const sure = await this.dialogs.confirm({
      title: 'Sign out?',
      body: 'You are signed out on this phone, and need your email and password to get back in.',
      consequences: ['The tickets kept on this phone, and their reminders, go too until you sign in again.'],
      confirmLabel: 'Sign out',
      tone: 'default',
    });

    if (!sure || this.busy()) return;

    this.busy.set(true);
    await this.session.signOut();
    await this.router.navigate(['/sign-in'], { replaceUrl: true });
  }

  go(path: string): void {
    void this.router.navigate([path]);
  }

  // --- deleting the account ---------------------------------------------------

  private readonly discover = inject(Discover);

  readonly deleteOpen = signal(false);
  readonly loadingErasure = signal(false);
  readonly erasure = signal<AccountErasurePreview | null>(null);
  readonly deletePassword = signal('');
  readonly deleting = signal(false);

  /** "We sent a link": an address never proved has to be, before anything goes. */
  readonly deletePending = signal<string | null>(null);

  readonly stranded = computed(() => (this.erasure()?.organizations ?? []).filter((organization) => organization.only_owner));

  /** The teams this person would leave, by name, or null for none. */
  readonly leaving = computed(() => {
    const names = (this.erasure()?.organizations ?? []).map((organization) => organization.name);

    return names.length ? names.join(', ') : null;
  });

  readonly deleteReady = computed(
    () => !!this.erasure() && !this.erasure()?.refused && this.deletePassword() !== '' && !this.deleting() && !this.deletePending(),
  );

  /**
   * Opens with what would happen, asked of the server first — including
   * whether it would be refused, which is better known before a password is
   * typed than after.
   */
  async startDelete(): Promise<void> {
    if (this.loadingErasure()) return;

    this.loadingErasure.set(true);
    this.errors.set({});
    this.deletePassword.set('');
    this.deletePending.set(null);

    try {
      this.erasure.set(await this.api.erasurePreview());
      this.deleteOpen.set(true);
    } catch (error) {
      this.toasts.show(messageOf(error, 'Could not work out what deleting would involve. Try again.'), 'danger');
    } finally {
      this.loadingErasure.set(false);
    }
  }

  /** The password typed here does not outlive the sheet. */
  closeDelete(): void {
    this.deleteOpen.set(false);
    this.deletePassword.set('');
  }

  /** To the team of an organization somebody else has to be made an owner of. */
  async handOver(organizationId: string): Promise<void> {
    this.closeDelete();
    await this.session.chooseOrganization(organizationId);
    await this.router.navigate(['/manage/team']);
  }

  /** The site's contact page, in the browser: closing an organization is done with us. */
  writeToUs(): void {
    void Browser.open({ url: this.discover.siteBase() + '/contact' }).catch(() => undefined);
  }

  async deleteAccount(): Promise<void> {
    if (!this.deleteReady()) return;

    this.deleting.set(true);
    this.errors.set({});

    try {
      const result = await this.api.eraseAccount(this.deletePassword());
      this.deletePassword.set('');

      if (result.status === 'pending') {
        // Nothing has gone yet, so the phone stays signed in until it has.
        this.deletePending.set(result.message);
        return;
      }

      // Every token the account had is gone, this phone's included: there is
      // nothing to sign out of, only a session to forget.
      this.deleteOpen.set(false);
      await this.session.clear();
      this.toasts.show(result.message, 'success', 6000);
      await this.router.navigate(['/'], { replaceUrl: true });
    } catch (error) {
      // Somebody became the only owner of something since the sheet opened.
      // Nothing happened; the reason is the server's.
      if (error instanceof ApiError && error.status === 409) {
        const preview = this.erasure();
        if (preview) this.erasure.set({ ...preview, refused: error.message });
        return;
      }

      this.errors.set(fieldErrors(error));
      // A 429 is the server saying how long to wait, and belongs in the same place.
      if (error instanceof ApiError && error.status === 429) this.errors.set({ current_password: error.message });
      if (!Object.keys(this.errors()).length) this.toasts.show(messageOf(error, 'Your account could not be deleted. Nothing has changed.'), 'danger');
    } finally {
      this.deleting.set(false);
    }
  }
}
