import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Bell, CreditCard, Pencil, Share2, Ticket, Trash2, Users, Wallet } from 'lucide-angular';
import { Theme, ThemeChoice } from '../core/theme';
import {
  Dialogs,
  MfAvatar,
  MfBadge,
  MfButton,
  MfCard,
  MfCheck,
  MfChips,
  MfChoices,
  MfEmpty,
  MfField,
  MfIconButton,
  MfImagePick,
  MfList,
  MfMoney,
  MfMultiSelect,
  MfQr,
  MfRow,
  MfScreen,
  MfSearch,
  MfSegmented,
  MfSelect,
  MfSheet,
  MfSkeleton,
  MfStat,
  MfStepper,
  MfSwitch,
  ToastStore,
  type MfChip,
  type MfChoice,
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
    MfIconButton,
    MfCard,
    MfButton,
    MfField,
    MfMoney,
    MfStepper,
    MfCheck,
    MfChoices,
    MfChips,
    MfSearch,
    MfSelect,
    MfMultiSelect,
    MfSheet,
    MfSegmented,
    MfSwitch,
    MfBadge,
    MfEmpty,
    MfSkeleton,
    MfQr,
    MfList,
    MfRow,
    MfStat,
    MfAvatar,
    MfImagePick,
  ],
  template: `
    <mf-screen title="Components" subtitle="Everything this app is built from" back backTo="/settings" [busy]="busy()">
      <button mfIconButton screenActions [icon]="shareIcon" label="Share"></button>
      <button mfIconButton screenActions [icon]="bellIcon" label="Notifications" badge></button>

      <mf-segmented ariaLabel="Theme" [segments]="themes" [value]="theme.choice()" (valueChange)="setTheme($event)" />

      <h2 class="section">Figures</h2>
      <div class="grid">
        <mf-stat class="span" lead label="Owed to you" value="CA$1,168.95" hint="Ask to be paid whenever you like" />
        <mf-stat label="Sold" value="146" hint="of 200" [portion]="0.73" />
        <mf-stat label="Earned" value="CA$5,840" hint="92 orders" />
      </div>

      <h2 class="section">Lists</h2>
      <mf-list heading="Money" footer="Settlements are read-only here: paying out is done against a real bank.">
        <mf-row label="Payouts" sub="CA$1,168.95 owed" [icon]="walletIcon" tone="brand" link="/ui" />
        <mf-row label="Orders" value="92" [icon]="cardIcon" link="/ui" />
        <mf-row label="Busy saving" [icon]="ticketIcon" action (pressed)="pulse()" />
      </mf-list>
      <mf-list class="gap" heading="People">
        <mf-row label="Ada Okoro" sub="Owner">
          <mf-avatar name="Ada Okoro" [size]="32" />
        </mf-row>
        <mf-row label="Remind me before doors" [icon]="bellIcon">
          <mf-switch [checked]="reminding()" (changed)="reminding.set($event)" />
        </mf-row>
        <mf-row label="Remove from team" [icon]="removeIcon" action danger (pressed)="ask()" />
      </mf-list>

      <h2 class="section">Dialogs</h2>
      <div class="row">
        <button mfButton variant="secondary" (click)="ask()">Confirm</button>
        <button mfButton variant="secondary" (click)="menu()">Menu</button>
        <button mfButton variant="secondary" (click)="prompt()">Prompt</button>
      </div>

      <h2 class="section">Sheets and toasts</h2>
      <div class="row">
        <button mfButton variant="secondary" (click)="sheet.set(true)">Floating sheet</button>
        <button mfButton variant="secondary" (click)="toasts.show('Saved.', 'success')">Toast</button>
      </div>

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

      <h2 class="section">Search and filters</h2>
      <div class="stack">
        <mf-search placeholder="Name, email or reference" [(value)]="query" />
        <mf-chips ariaLabel="Status" [options]="statuses" [(value)]="status" />
        <mf-chips ariaLabel="Days" multiple [options]="days" [(values)]="chosenDays" />
      </div>

      <h2 class="section">Fields</h2>
      <div class="stack">
        <mf-field label="Email" hint="Solid, not outlined: legible in a dark room.">
          <input type="email" placeholder="name@example.com" />
        </mf-field>
        <mf-field label="Ticket code" error="That code is not for tonight.">
          <input value="WFY7-F77K4EJW" />
        </mf-field>
        <mf-field label="Discount" suffix="%">
          <input inputmode="numeric" value="15" />
        </mf-field>
        <mf-field label="About the night" optional [limit]="280" [count]="about().length">
          <textarea [ngModel]="about()" (ngModelChange)="about.set($event)" placeholder="What should people know?"></textarea>
        </mf-field>
        <mf-money label="Price" currency="CAD" hint="Before fees and tax." [(value)]="price" />
        <mf-field label="Doors open">
          <input type="datetime-local" value="2026-10-03T21:00" />
        </mf-field>
        <mf-select heading="Which city" placeholder="Choose a city" [options]="cities" [value]="city()" (valueChange)="city.set($event)" />
        <mf-multi-select heading="Ticket types" emptyLabel="Every ticket type" [options]="tiers" [(value)]="chosenTiers" />
      </div>

      <h2 class="section">Choosing</h2>
      <mf-card>
        <mf-stepper label="Per order" hint="The most one buyer can take." [min]="1" [max]="10" [(value)]="perOrder" />
        <mf-check label="ID is checked at the door" hint="Said on the ticket, so nobody is surprised." [(value)]="idRequired" />
      </mf-card>
      <mf-choices class="gap" legend="Who it goes to" variant="cards" [options]="audiences" [(value)]="audience" />

      <h2 class="section">Pictures</h2>
      <mf-image-pick label="Add a poster" hint="Portrait works best: 4 by 5." ratio="4 / 5" />

      <h2 class="section">Badges and people</h2>
      <div class="row center">
        <mf-badge tone="success">Ready</mf-badge>
        <mf-badge tone="warning">Waiting</mf-badge>
        <mf-badge tone="danger">Refused</mf-badge>
        <mf-badge>Used</mf-badge>
        <mf-avatar name="Tunde Bakare" />
        <mf-avatar name="Chioma Eze" />
      </div>

      <h2 class="section">Cards and loading</h2>
      <mf-card tappable>
        <h3>A raised card</h3>
        <p class="muted">Rounded, elevated, and it answers a press.</p>
      </mf-card>
      <mf-card class="gap" quiet>
        <mf-skeleton height="2rem" />
      </mf-card>

      <h2 class="section">A ticket</h2>
      <div class="qr">
        <mf-qr code="WFY7-F77K4EJW" [size]="160" />
      </div>

      <h2 class="section">Nothing here</h2>
      <mf-empty title="No tickets yet" hint="Tickets you buy show up here, ready to scan." />
    </mf-screen>

    <mf-sheet
      [open]="sheet()"
      heading="Add a ticket type"
      subheading="Floating, with its actions pinned below whatever scrolls."
      closable
      (closed)="sheet.set(false)"
    >
      <div class="stack">
        <mf-field label="Name"><input placeholder="General admission" /></mf-field>
        <mf-money label="Price" currency="CAD" [(value)]="price" />
        <mf-stepper label="How many" [min]="0" [max]="5000" [step]="10" [(value)]="quantity" />
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="sheet.set(false)">Cancel</button>
        <button mfButton (click)="sheet.set(false); toasts.show('Ticket type added.', 'success')">Add</button>
      </ng-container>
    </mf-sheet>
  `,
  styles: `
    .section {
      margin: var(--space-8) 0 var(--space-3);
      font-family: var(--font-family-sans);
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

    .row.center {
      align-items: center;
    }

    .stack {
      display: grid;
      gap: var(--space-4);
    }

    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-3);
    }

    .span {
      grid-column: 1 / -1;
    }

    .gap {
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
  private readonly dialogs = inject(Dialogs);

  protected readonly shareIcon = Share2;
  protected readonly bellIcon = Bell;
  protected readonly walletIcon = Wallet;
  protected readonly cardIcon = CreditCard;
  protected readonly ticketIcon = Ticket;
  protected readonly removeIcon = Trash2;

  readonly reminding = signal(true);
  readonly sheet = signal(false);
  readonly busy = signal(false);
  readonly city = signal<string | null>('toronto');
  readonly query = signal('');
  readonly status = signal('all');
  readonly chosenDays = signal<string[]>(['fri']);
  readonly about = signal('');
  readonly price = signal<number | null>(2500);
  readonly quantity = signal(200);
  readonly perOrder = signal(4);
  readonly idRequired = signal(true);
  readonly audience = signal('past_attendees');
  readonly chosenTiers = signal<string[]>([]);

  readonly themes: MfSegment[] = [
    { value: 'system', label: 'System' },
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
  ];

  readonly statuses: MfChip[] = [
    { value: 'all', label: 'All', count: 92 },
    { value: 'paid', label: 'Paid', count: 84 },
    { value: 'refunded', label: 'Refunded', count: 6 },
    { value: 'pending', label: 'Pending', count: 2 },
  ];

  readonly days: MfChip[] = [
    { value: 'thu', label: 'Thursday' },
    { value: 'fri', label: 'Friday' },
    { value: 'sat', label: 'Saturday' },
  ];

  readonly tiers: MfOption[] = [
    { value: 'early', label: 'Early Bird', hint: 'CA$40.00' },
    { value: 'general', label: 'General', hint: 'CA$50.00' },
    { value: 'vip', label: 'VIP table', hint: 'CA$400.00 · admits 6' },
  ];

  readonly audiences: MfChoice[] = [
    { value: 'followers', label: 'People who follow you', hint: 'Anybody who tapped Follow on your page.', icon: Users },
    { value: 'past_attendees', label: 'People who came before', hint: 'Anyone whose ticket was used in the last two years.', icon: Ticket },
    { value: 'abandoned', label: 'People who nearly bought', hint: 'Started checkout for this event and stopped.', icon: Pencil },
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

  pulse(): void {
    this.busy.set(true);
    setTimeout(() => this.busy.set(false), 1600);
  }

  async ask(): Promise<void> {
    const sure = await this.dialogs.confirm({
      title: 'Remove Tunde from the team?',
      message: 'They lose access to this organization straight away. You can invite them again later.',
      confirm: 'Remove',
      danger: true,
    });

    if (sure) this.toasts.show('Removed.', 'success');
  }

  async menu(): Promise<void> {
    const chosen = await this.dialogs.menu({
      title: 'Detty December Warm-Up',
      subtitle: 'Sat 3 Oct · Ottawa',
      actions: [
        { key: 'edit', label: 'Edit details', icon: Pencil },
        { key: 'share', label: 'Share the link', icon: Share2, hint: 'myfiesta.ca/detty-december' },
        { key: 'cancel', label: 'Cancel the event', icon: Trash2, danger: true, hint: 'Refunds every buyer.' },
      ],
    });

    if (chosen) this.toasts.show(`Chose ${chosen}.`, 'success');
  }

  async prompt(): Promise<void> {
    const reason = await this.dialogs.prompt({
      title: 'Why the refund?',
      message: 'The buyer sees this in the email that tells them.',
      label: 'Reason',
      placeholder: 'Could not make it',
      confirm: 'Refund',
      multiline: true,
      required: true,
    });

    if (reason) this.toasts.show(`Refunded: ${reason}`, 'success');
  }
}
