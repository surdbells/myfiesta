import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Copy, KeyRound, MoreHorizontal, ScanLine, Share2, ShieldOff } from 'lucide-angular';
import { Share } from '@capacitor/share';
import type { DoorPass as IssuedDoorPass, OrganizerEventDetail, SalesReport } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { formatMoney } from '../../core/money';
import { messageOf } from '../../core/errors';
import { shortEventTime } from '../../core/event-time';
import {
  Dialogs,
  MfBadge,
  MfButton,
  MfCard,
  MfEmpty,
  MfField,
  MfIcon,
  MfIconButton,
  MfQr,
  MfScreen,
  MfSheet,
  MfSkeleton,
  MfStat,
  ToastStore,
} from '../../ui';
import { EventContext } from './event-context';

interface FreshPass {
  label: string;
  link: string;
}

type PassState = IssuedDoorPass['state'];

const STATE_LABEL: Record<PassState, string> = {
  waiting: 'Not opened yet',
  active: 'Scanning',
  expired: 'Expired',
  revoked: 'Taken back',
  ended: 'Ended',
};

/**
 * The door, from the organizer's side: scanning, handing the door to staff,
 * and what came in through it.
 *
 * A door pass is one link for one phone for one event. Whoever opens it can
 * scan tickets and nothing else — no account, no password, no place on the
 * team — and it stops by itself when the night is over. The link exists only
 * at the moment it is made, so that is when it is shown, with a code to point
 * a phone at; after that only the label and what it has done are kept.
 */
@Component({
  selector: 'mf-event-door',
  imports: [MfScreen, MfIconButton, MfIcon, MfCard, MfBadge, MfButton, MfEmpty, MfSkeleton, MfSheet, MfField, MfStat, MfQr],
  template: `
    <mf-screen title="Door" [subtitle]="event()?.title ?? null" back [backTo]="'/manage/events/' + id()" refreshable [busy]="loading()" (refresh)="load()">
      @if (event()?.status !== 'cancelled') {
        <button mfButton class="scan" block (click)="scan()"><mf-icon [icon]="scanIcon" size="sm" /> Scan tickets on this phone</button>
      }

      @if (door(); as d) {
        <section class="block">
          <h2 class="heading">Taken at the door</h2>
          <div class="stats">
            <mf-stat lead label="Taken" [value]="cash(d.total)" [hint]="d.tickets + (d.tickets === 1 ? ' ticket sold' : ' tickets sold')" />
            @for (m of d.by_method; track m.method) {
              <mf-stat [label]="methodLabel(m.method)" [value]="cash(m.total)" [hint]="m.orders + (m.orders === 1 ? ' sale' : ' sales')" />
            }
          </div>
          @if (d.by_till.length > 1) {
            <ul class="tills">
              @for (till of d.by_till; track till.label) {
                <li><span>{{ till.label }}</span><span class="figure">{{ cash(till.total) }}</span></li>
              }
            </ul>
          }
          <p class="note">Money in your own hands — cash, your card reader, a transfer — so it is not part of what is paid out to you.</p>
        </section>
      }

      <section class="block">
        <div class="heading-row">
          <h2 class="heading">Door passes</h2>
          <button mfButton size="sm" variant="secondary" (click)="startPass()"><mf-icon [icon]="keyIcon" size="sm" /> New pass</button>
        </div>
        @if (until(); as u) {
          <p class="note">Every pass for this night stops working at {{ u }}.</p>
        }

        @if (error(); as message) {
          <mf-empty title="Could not load the door passes" [hint]="message">
            <button mfButton variant="secondary" (click)="load()">Try again</button>
          </mf-empty>
        } @else if (passes(); as all) {
          @if (all.length === 0) {
            <mf-empty title="Nobody else on the door" hint="Give venue staff or a friend a link that scans tickets for this night only. No account needed." />
          } @else {
            <ul class="passes">
              @for (pass of all; track pass.id) {
                <li [class.done]="pass.state !== 'waiting' && pass.state !== 'active'">
                  <span class="who">
                    <span class="name">{{ pass.label }}</span>
                    <span class="sub">
                      @if (pass.scans > 0) {
                        {{ pass.admitted_scans }} let in · {{ pass.scans }} scans
                      } @else {
                        Made {{ time(pass.created_at) }}
                      }
                    </span>
                  </span>
                  <mf-badge [tone]="pass.state === 'active' ? 'success' : pass.state === 'waiting' ? 'warning' : 'neutral'">{{ stateLabel(pass.state) }}</mf-badge>
                  @if (pass.state === 'waiting' || pass.state === 'active') {
                    <button mfIconButton size="sm" [icon]="moreIcon" [label]="'More for ' + pass.label" (click)="passMenu(pass)"></button>
                  }
                </li>
              }
            </ul>
          }
        } @else {
          <mf-card><mf-skeleton height="3rem" /></mf-card>
        }
      </section>
    </mf-screen>

    <mf-sheet [open]="making()" heading="New door pass" subheading="One link for one phone, for this night only." closable (closed)="making.set(false)">
      <mf-field label="Who it is for" hint="So you know which one to take back." [error]="makeError()">
        <input [value]="label()" (input)="label.set($any($event.target).value)" placeholder="Front door — Kemi" maxlength="60" />
      </mf-field>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="making.set(false)">Cancel</button>
        <button mfButton [loading]="busy()" [disabled]="!label().trim()" (click)="makePass()">Make pass</button>
      </ng-container>
    </mf-sheet>

    <mf-sheet [open]="!!fresh()" [heading]="fresh()?.label ?? ''" subheading="Point their camera at this, or send them the link. It is shown once." closable (closed)="fresh.set(null)">
      @if (fresh(); as pass) {
        <div class="fresh">
          <mf-qr [code]="pass.link" [size]="220" />
          <p class="link">{{ pass.link }}</p>
        </div>
      }
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="copy()"><mf-icon [icon]="copyIcon" size="sm" /> Copy</button>
        <button mfButton (click)="sharePass()"><mf-icon [icon]="shareIcon" size="sm" /> Send</button>
      </ng-container>
    </mf-sheet>
  `,
  styles: `
    .scan {
      margin-bottom: var(--space-5);
    }

    .block {
      display: grid;
      gap: var(--space-3);
      margin-bottom: var(--space-6);
    }

    .heading {
      font-size: var(--font-size-lg);
    }

    .heading-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: var(--space-3);
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: var(--space-3);
    }

    .stats mf-stat:first-child {
      grid-column: 1 / -1;
    }

    .tills {
      display: grid;
      margin: 0;
      padding: var(--space-2) var(--space-4);
      list-style: none;
      border-radius: var(--radius-xl);
      background: var(--surface-raised);
      box-shadow: var(--shadow-sm);
    }

    .tills li {
      display: flex;
      justify-content: space-between;
      padding: var(--space-2) 0;
      font-size: var(--font-size-sm);
    }

    .tills li + li {
      border-top: 1px solid var(--border-subtle);
    }

    .note {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .passes {
      display: grid;
      margin: 0;
      padding: 0;
      list-style: none;
      border-radius: var(--radius-xl);
      background: var(--surface-raised);
      box-shadow: var(--shadow-sm);
    }

    .passes li {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      min-height: var(--mf-tap);
      padding: var(--space-3) var(--space-2) var(--space-3) var(--space-4);
    }

    .passes li + li {
      border-top: 1px solid var(--border-subtle);
    }

    .passes li.done {
      opacity: 0.6;
    }

    .who {
      flex: 1;
      display: grid;
      min-width: 0;
    }

    .name {
      font-weight: var(--font-weight-medium);
    }

    .sub {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .fresh {
      display: grid;
      justify-items: center;
      gap: var(--space-3);
    }

    .link {
      max-width: 100%;
      padding: var(--space-2) var(--space-3);
      border-radius: var(--radius-md);
      background: var(--surface-inset);
      font-family: var(--font-family-mono);
      font-size: var(--font-size-xs);
      overflow-wrap: anywhere;
      user-select: all;
    }
  `,
})
export class EventDoor implements OnInit {
  readonly id = input.required<string>();

  private readonly organizer = inject(Organizer);
  private readonly session = inject(SessionStore);
  private readonly context = inject(EventContext);
  private readonly router = inject(Router);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  protected readonly event = signal<OrganizerEventDetail | null>(null);
  protected readonly passes = signal<IssuedDoorPass[] | null>(null);
  protected readonly expiresAt = signal<string | null>(null);
  protected readonly door = signal<SalesReport['door'] | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly making = signal(false);
  protected readonly label = signal('');
  protected readonly busy = signal(false);
  protected readonly makeError = signal<string | null>(null);
  protected readonly fresh = signal<FreshPass | null>(null);

  protected readonly scanIcon = ScanLine;
  protected readonly keyIcon = KeyRound;
  protected readonly moreIcon = MoreHorizontal;
  protected readonly copyIcon = Copy;
  protected readonly shareIcon = Share2;
  protected readonly cash = formatMoney;

  protected readonly until = computed(() => {
    const at = this.expiresAt();
    return at ? this.time(at) : null;
  });

  ngOnInit(): void {
    this.event.set(this.context.peek(this.id()));
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    // What the door took is money, and only people who see money see it.
    if (this.session.can('money.view')) {
      void this.organizer
        .sales(this.id())
        .then((report) => this.door.set(report.door.tickets > 0 ? report.door : null))
        .catch(() => undefined);
    }

    try {
      const [event, passes] = await Promise.all([this.context.get(this.id()), this.organizer.doorPasses(this.id())]);
      this.event.set(event);
      this.passes.set(passes.data);
      this.expiresAt.set(passes.expires_at);
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected time(iso: string): string {
    return shortEventTime(iso, this.event()?.timezone ?? 'UTC');
  }

  protected stateLabel(state: PassState): string {
    return STATE_LABEL[state];
  }

  protected methodLabel(method: string): string {
    return ({ cash: 'Cash', card: 'Card reader', transfer: 'Transfer', comp: 'Free' } as Record<string, string>)[method] ?? method;
  }

  protected scan(): void {
    const ev = this.event();
    void this.router.navigate(['/door'], { queryParams: { event: this.id(), title: ev?.title ?? null } });
  }

  protected startPass(): void {
    this.label.set('');
    this.makeError.set(null);
    this.making.set(true);
  }

  protected async makePass(): Promise<void> {
    const label = this.label().trim();
    if (!label) return;

    this.busy.set(true);
    this.makeError.set(null);

    try {
      const { data, link } = await this.organizer.issueDoorPass(this.id(), label);
      this.passes.update((all) => [data, ...(all ?? [])]);
      this.making.set(false);
      this.fresh.set({ label: data.label, link });
    } catch (error) {
      this.makeError.set(messageOf(error, 'That door pass could not be made.'));
    } finally {
      this.busy.set(false);
    }
  }

  protected async copy(): Promise<void> {
    const pass = this.fresh();
    if (!pass) return;

    try {
      await navigator.clipboard.writeText(pass.link);
      this.toasts.show('Link copied.', 'success');
    } catch {
      this.toasts.show('Copying is blocked here. Send it instead.', 'danger');
    }
  }

  protected async sharePass(): Promise<void> {
    const pass = this.fresh();
    if (!pass) return;

    try {
      await Share.share({ title: `Door pass · ${pass.label}`, text: `Scan tickets for ${this.event()?.title ?? 'the night'}:`, url: pass.link });
    } catch {
      // Dismissed. The link is still on screen.
    }
  }

  protected async passMenu(pass: IssuedDoorPass): Promise<void> {
    const chosen = await this.dialogs.menu({
      title: pass.label,
      subtitle: this.stateLabel(pass.state),
      actions: [{ key: 'revoke', label: 'Take it back', icon: ShieldOff, danger: true, hint: 'That phone stops scanning at once' }],
    });

    if (chosen !== 'revoke') return;

    const sure = await this.dialogs.confirm({
      title: `Take back ${pass.label}?`,
      message: 'Whoever has it stops scanning straight away. Tickets they already let in stay in.',
      confirm: 'Take it back',
      danger: true,
    });

    if (!sure) return;

    try {
      const { message } = await this.organizer.revokeDoorPass(this.id(), pass.id);
      this.toasts.show(message, 'success');
      await this.load();
    } catch (error) {
      this.toasts.show(messageOf(error, 'That pass could not be taken back.'), 'danger');
    }
  }
}
