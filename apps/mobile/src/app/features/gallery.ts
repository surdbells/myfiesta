import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Theme, ThemeChoice } from '../core/theme';
import {
  MfBadge,
  MfButton,
  MfCard,
  MfEmpty,
  MfField,
  MfQr,
  MfScreen,
  MfSegmented,
  MfSelect,
  MfSheet,
  MfSkeleton,
  ToastStore,
  type MfOption,
  type MfSegment,
} from '../ui';

/**
 * Every control, on one screen, in both themes.
 *
 * Development only — the route is guarded by isDevMode. It exists because a
 * design system nobody can see whole is one that drifts: this is where a
 * changed radius or a mis-set token shows up before a screen ships with it,
 * and where the light and dark versions are compared side by side rather than
 * from memory.
 */
@Component({
  selector: 'mf-gallery',
  imports: [
    FormsModule,
    MfScreen,
    MfCard,
    MfButton,
    MfField,
    MfSelect,
    MfSheet,
    MfSegmented,
    MfBadge,
    MfEmpty,
    MfSkeleton,
    MfQr,
  ],
  template: `
    <mf-screen title="Components" subtitle="Everything this app is built from">
      <mf-segmented
        class="block"
        ariaLabel="Theme"
        [segments]="themes"
        [value]="theme.choice()"
        (valueChange)="setTheme($event)"
      />

      <h2 class="section">Buttons</h2>
      <div class="row">
        <button mfButton>Primary</button>
        <button mfButton variant="secondary">Secondary</button>
        <button mfButton variant="ghost">Ghost</button>
      </div>
      <div class="row">
        <button mfButton variant="danger" size="sm">Danger</button>
        <button mfButton [loading]="true" label="Working…">Busy</button>
        <button mfButton [disabled]="true">Disabled</button>
      </div>
      <button mfButton class="block" size="lg" block>Full width</button>

      <h2 class="section">Fields</h2>
      <div class="stack">
        <mf-field label="Email" hint="Solid, not outlined: legible in a dark room.">
          <input #control name="email" type="email" placeholder="name@example.com" />
        </mf-field>

        <mf-field label="Ticket code" error="That code is not for tonight.">
          <input #control name="code" value="WFY7-F77K4EJW" />
        </mf-field>

        <mf-field label="Note" optional>
          <input #control name="note" placeholder="Anything else" />
        </mf-field>
      </div>

      <h2 class="section">Searchable select</h2>
      <mf-select
        heading="Which city"
        subheading="Long lists get a search box; short ones do not."
        placeholder="Choose a city"
        [options]="cities"
        [value]="city()"
        (valueChange)="city.set($event)"
      />

      <h2 class="section">Badges</h2>
      <div class="row">
        <mf-badge tone="success">Ready</mf-badge>
        <mf-badge tone="warning">Waiting</mf-badge>
        <mf-badge tone="danger">Refused</mf-badge>
        <mf-badge>Used</mf-badge>
      </div>

      <h2 class="section">Cards and loading</h2>
      <mf-card class="block">
        <h3>A raised card</h3>
        <p class="muted">The one container this app uses.</p>
      </mf-card>
      <mf-card class="block" quiet>
        <mf-skeleton height="2rem" />
      </mf-card>

      <h2 class="section">Sheets and toasts</h2>
      <div class="row">
        <button mfButton variant="secondary" (click)="sheet.set(true)">Open a sheet</button>
        <button mfButton variant="secondary" (click)="toasts.show('Saved.', 'success')">Toast</button>
      </div>

      <h2 class="section">A ticket</h2>
      <div class="qr">
        <mf-qr code="WFY7-F77K4EJW" [size]="160" />
      </div>

      <h2 class="section">Nothing here</h2>
      <mf-empty title="No tickets yet" hint="Tickets you buy show up here, ready to scan." />
    </mf-screen>

    <mf-sheet
      [open]="sheet()"
      heading="A bottom sheet"
      subheading="Drag it down, tap the scrim, or press Escape."
      (closed)="sheet.set(false)"
    >
      <p class="muted">
        Everything that would be a modal on a desktop arrives from the bottom here, because that is
        the half of a phone a thumb reaches.
      </p>
      <button mfButton class="block" block (click)="sheet.set(false)">Close</button>
    </mf-sheet>
  `,
  styles: `
    .section {
      margin: var(--space-6) 0 var(--space-3);
      font-size: var(--font-size-xs);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--text-subtle);
    }

    .row {
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-2);
      margin-bottom: var(--space-2);
    }

    .stack {
      display: grid;
      gap: var(--space-4);
    }

    .block {
      display: block;
      margin-top: var(--space-3);
    }

    .qr {
      display: grid;
      justify-items: center;
    }
  `,
})
export class Gallery {
  readonly theme = inject(Theme);
  readonly toasts = inject(ToastStore);

  readonly sheet = signal(false);
  readonly city = signal<string | null>('toronto');

  readonly themes: MfSegment[] = [
    { value: 'system', label: 'System' },
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
  ];

  readonly cities: MfOption[] = [
    { value: 'toronto', label: 'Toronto', hint: 'Ontario' },
    { value: 'montreal', label: 'Montréal', hint: 'Québec' },
    { value: 'ottawa', label: 'Ottawa', hint: 'Ontario' },
    { value: 'calgary', label: 'Calgary', hint: 'Alberta' },
    { value: 'vancouver', label: 'Vancouver', hint: 'British Columbia' },
    { value: 'lagos', label: 'Lagos', hint: 'Lagos State' },
    { value: 'abuja', label: 'Abuja', hint: 'FCT' },
    { value: 'port-harcourt', label: 'Port Harcourt', hint: 'Rivers' },
  ];

  setTheme(choice: string): void {
    void this.theme.set(choice as ThemeChoice);
  }
}
