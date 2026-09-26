import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { SessionStore } from '../../core/session';
import { Theme, ThemeChoice } from '../../core/theme';
import { Reminders } from '../../core/reminders';
import { Api, Ticket } from '../../core/api';
import {
  MfButton,
  MfCard,
  MfScreen,
  MfSegmented,
  MfSelect,
  MfSheet,
  MfSwitch,
  type MfOption,
  type MfSegment,
} from '../../ui';

/**
 * The account, the theme, and the way out.
 *
 * Three settings and no more. Everything else this app could be asked to
 * configure belongs in the console on a bigger screen; a phone settings page
 * that goes on for a page and a half is one nobody reads.
 */
@Component({
  selector: 'mf-settings',
  imports: [MfScreen, MfCard, MfButton, MfSegmented, MfSelect, MfSheet, MfSwitch],
  template: `
    <mf-screen title="Settings" large>
      <mf-card>
        <p class="label">Signed in as</p>
        <h2>{{ session.session()?.name }}</h2>
        @if (session.session()?.email; as email) {
          <p class="muted">{{ email }}</p>
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

  switchOrganization(id: string | null): void {
    if (!id) return;

    // Which organization the API answers for is decided by the session's own
    // order, so switching is a reorder rather than a second source of truth.
    void this.router.navigate(['/events'], { queryParams: { organization: id } });
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
