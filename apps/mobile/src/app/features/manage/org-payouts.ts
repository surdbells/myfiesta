import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Banknote, Landmark, ShieldCheck } from 'lucide-angular';
import type { PayoutRequestRow, PayoutStatement } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { formatMoney } from '../../core/money';
import { messageOf } from '../../core/errors';
import {
  Dialogs,
  MfBadge,
  MfButton,
  MfCard,
  MfChoices,
  MfEmpty,
  MfField,
  MfIcon,
  MfList,
  MfMoney,
  MfRow,
  MfScreen,
  MfSheet,
  MfSkeleton,
  ToastStore,
  type MfChoice,
} from '../../ui';

interface DestinationDraft {
  rail: 'interac' | 'bank_transfer';
  interacEmail: string;
  accountName: string;
  bankName: string;
  accountNumber: string;
  transit: string;
  institution: string;
  bankCode: string;
}

/**
 * What is owed, which nights it came from, what has been sent, and where it
 * goes.
 *
 * The destination is on the same screen rather than in settings because a
 * balance with nowhere to go is the one thing here that needs doing. An
 * account number is typed, sent, and never seen again: the API keeps only its
 * last four in the clear, so that is all this screen can ever show, and the
 * field is empty every time it opens.
 */
@Component({
  selector: 'mf-org-payouts',
  imports: [MfScreen, MfCard, MfBadge, MfButton, MfEmpty, MfSkeleton, MfSheet, MfField, MfMoney, MfChoices, MfList, MfRow, MfIcon],
  template: `
    <mf-screen title="Payouts" back backTo="/manage" refreshable [busy]="loading()" (refresh)="load()">
      @if (error(); as message) {
        <mf-empty title="Could not load your payouts" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (statement(); as s) {
        <mf-card class="balance">
          <p class="eyebrow">Owed to you</p>
          <p class="big figure">{{ cash(s.balance) }}</p>
          <p class="muted">{{ cash(s.settled) }} paid out so far</p>

          @if (pending(); as p) {
            <div class="waiting">
              <p><strong>{{ cash(p.amount) }}</strong> asked for {{ day(p.requested_at) }} — waiting to be paid.</p>
              <button mfButton size="sm" variant="secondary" [loading]="busy()" (click)="withdraw(p)">Withdraw the request</button>
            </div>
          } @else if (canAsk()) {
            <button mfButton block class="ask" (click)="startAsk()">Ask to be paid</button>
          } @else if (s.balance.amount > 0 && !s.destination) {
            <p class="warn">There is money owed and nowhere to send it. Add where it goes below.</p>
          }
        </mf-card>

        <section class="block">
          <div class="heading-row">
            <h2 class="heading">Where it goes</h2>
            <button mfButton size="sm" variant="secondary" (click)="openDestination()">{{ s.destination ? 'Change' : 'Add' }}</button>
          </div>
          @if (s.destination; as d) {
            <mf-card quiet class="destination">
              <mf-icon [icon]="d.rail === 'interac' ? interacIcon : bankIcon" />
              <span class="who">
                <span class="name">{{ d.rail === 'interac' ? 'Interac e-Transfer' : (d.bank_name ?? 'Bank transfer') }}</span>
                <span class="sub">
                  @if (d.rail === 'interac') {
                    {{ d.interac_email }}
                  } @else {
                    {{ d.account_name }} · account ending {{ d.account_last_four ?? '••••' }}
                  }
                </span>
              </span>
              @if (d.verified_at) {
                <mf-badge tone="success"><mf-icon [icon]="verifiedIcon" size="sm" /> Checked</mf-badge>
              } @else {
                <mf-badge tone="warning">Not checked yet</mf-badge>
              }
            </mf-card>
          } @else {
            <p class="muted">Nothing yet. Payouts cannot be sent until this is set.</p>
          }
        </section>

        @if (s.events.length > 0) {
          <mf-list class="block" heading="By night">
            @for (e of s.events; track e.event_id ?? e.title) {
              <mf-row [label]="e.title" [sub]="cash(e.gross) + ' sold · ' + cash(e.settled) + ' paid'" [value]="cash(e.balance)" [chevron]="false" />
            }
          </mf-list>
        }

        @if (s.settlements.length > 0) {
          <mf-list class="block" heading="Sent to you">
            @for (p of s.settlements; track p.id) {
              <mf-row [label]="cash(p.amount)" [sub]="(p.event?.title ?? 'Several nights') + ' · ' + railLabel(p.rail) + ' · ' + day(p.settled_at)" [chevron]="false">
                <mf-badge [tone]="p.type === 'full' ? 'success' : p.type === 'partial' ? 'warning' : 'danger'">{{ p.type === 'full' ? 'In full' : p.type === 'partial' ? 'Part' : 'Advance' }}</mf-badge>
              </mf-row>
            }
          </mf-list>
        }

        @if (past().length > 0) {
          <mf-list class="block" heading="Requests">
            @for (r of past(); track r.id) {
              <mf-row [label]="cash(r.amount)" [sub]="day(r.requested_at) + (r.decision_note ? ' · ' + r.decision_note : '')" [chevron]="false">
                <mf-badge [tone]="requestTone(r.status)">{{ requestLabel(r.status) }}</mf-badge>
              </mf-row>
            }
          </mf-list>
        }
      } @else {
        <mf-card><mf-skeleton height="8rem" /></mf-card>
      }
    </mf-screen>

    <mf-sheet [open]="asking()" heading="Ask to be paid" [subheading]="statement() ? 'To ' + destinationSummary() : null" closable (closed)="asking.set(false)">
      <div class="form">
        <mf-money label="How much" [currency]="statement()?.currency ?? 'CAD'" [hint]="'Up to ' + cash(statement()?.balance ?? null)" [error]="tooMuch() ? 'That is more than you are owed.' : askError()" [value]="askAmount()" (valueChange)="askAmount.set($event)" />
        <mf-field label="A note for us" optional [limit]="500" [count]="askNote().length">
          <textarea [value]="askNote()" (input)="askNote.set($any($event.target).value)" maxlength="500" placeholder="Rent for the venue is due Friday."></textarea>
        </mf-field>
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="asking.set(false)">Cancel</button>
        <button mfButton [loading]="busy()" [disabled]="!askAmount() || tooMuch()" (click)="ask()">Ask for {{ cash(askMoney()) }}</button>
      </ng-container>
    </mf-sheet>

    <mf-sheet [open]="editing()" heading="Where payouts go" subheading="Changing this means it is checked again before money is sent." closable (closed)="editing.set(false)">
      <div class="form">
        @if (formError(); as message) {
          <p class="form-error" role="alert">{{ message }}</p>
        }
        @if (statement()?.currency === 'CAD') {
          <mf-choices legend="How" [options]="rails" [value]="draft().rail" (valueChange)="set('rail', $any($event))" />
        }
        @if (draft().rail === 'interac') {
          <mf-field label="Interac email" hint="Set to deposit automatically, or you will be asked a security question.">
            <input type="email" inputmode="email" autocapitalize="off" autocomplete="email" [value]="draft().interacEmail" (input)="set('interacEmail', $any($event.target).value)" placeholder="payouts@yourcompany.ca" />
          </mf-field>
        } @else {
          <mf-field label="Name on the account">
            <input autocomplete="off" [value]="draft().accountName" (input)="set('accountName', $any($event.target).value)" maxlength="120" />
          </mf-field>
          <mf-field label="Bank">
            <input autocomplete="off" [value]="draft().bankName" (input)="set('bankName', $any($event.target).value)" maxlength="120" />
          </mf-field>
          <mf-field label="Account number" [hint]="statement()?.destination?.account_last_four ? 'Type it in full to change it. Only the last four are ever shown back.' : 'Only the last four are ever shown back.'">
            <input inputmode="numeric" autocomplete="off" [value]="draft().accountNumber" (input)="set('accountNumber', $any($event.target).value)" maxlength="34" />
          </mf-field>
          @if (statement()?.currency === 'CAD') {
            <div class="pair">
              <mf-field label="Transit" optional>
                <input inputmode="numeric" autocomplete="off" [value]="draft().transit" (input)="set('transit', $any($event.target).value)" maxlength="10" placeholder="5 digits" />
              </mf-field>
              <mf-field label="Institution" optional>
                <input inputmode="numeric" autocomplete="off" [value]="draft().institution" (input)="set('institution', $any($event.target).value)" maxlength="10" placeholder="3 digits" />
              </mf-field>
            </div>
          } @else {
            <mf-field label="Bank code" optional>
              <input inputmode="numeric" autocomplete="off" [value]="draft().bankCode" (input)="set('bankCode', $any($event.target).value)" maxlength="20" />
            </mf-field>
          }
        }
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="editing.set(false)">Cancel</button>
        <button mfButton [loading]="busy()" [disabled]="!canSave()" (click)="saveDestination()">Save</button>
      </ng-container>
    </mf-sheet>
  `,
  styles: `
    .balance {
      display: grid;
      gap: var(--space-1);
      margin-bottom: var(--space-6);
    }

    .eyebrow {
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-semibold);
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--text-subtle);
    }

    .big {
      font-size: var(--font-size-4xl);
      line-height: 1.05;
    }

    .muted {
      color: var(--text-muted);
      font-size: var(--font-size-sm);
    }

    .ask {
      margin-top: var(--space-4);
    }

    .waiting {
      display: grid;
      gap: var(--space-3);
      justify-items: start;
      margin-top: var(--space-4);
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-lg);
      background: color-mix(in srgb, var(--warning) 12%, transparent);
      font-size: var(--font-size-sm);
    }

    .warn {
      margin-top: var(--space-3);
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
      color: var(--danger-text);
    }

    .block {
      display: block;
      margin-bottom: var(--space-6);
    }

    .heading-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: var(--space-3);
    }

    .heading {
      font-size: var(--font-size-lg);
    }

    .destination {
      display: flex;
      align-items: center;
      gap: var(--space-3);
    }

    .who {
      flex: 1;
      display: grid;
      min-width: 0;
    }

    .name {
      font-weight: var(--font-weight-semibold);
    }

    .sub {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      overflow-wrap: anywhere;
    }

    .form {
      display: grid;
      gap: var(--space-4);
    }

    .pair {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-3);
    }

    .form-error {
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-lg);
      background: color-mix(in srgb, var(--danger) 10%, transparent);
      color: var(--danger-text);
      font-size: var(--font-size-sm);
    }
  `,
})
export class OrgPayouts implements OnInit {
  private readonly organizer = inject(Organizer);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  protected readonly statement = signal<PayoutStatement | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly busy = signal(false);

  protected readonly asking = signal(false);
  protected readonly askAmount = signal<number | null>(null);
  protected readonly askNote = signal('');
  protected readonly askError = signal<string | null>(null);

  protected readonly editing = signal(false);
  protected readonly draft = signal<DestinationDraft>(this.blank());
  protected readonly formError = signal<string | null>(null);

  protected readonly interacIcon = Banknote;
  protected readonly bankIcon = Landmark;
  protected readonly verifiedIcon = ShieldCheck;
  protected readonly cash = formatMoney;

  protected readonly rails: MfChoice[] = [
    { value: 'interac', label: 'Interac e-Transfer', hint: 'To an email address. Usually the same day.' },
    { value: 'bank_transfer', label: 'Bank transfer', hint: 'Straight into an account.' },
  ];

  protected readonly pending = computed(() => this.statement()?.requests.find((r) => r.status === 'pending') ?? null);
  protected readonly past = computed(() => (this.statement()?.requests ?? []).filter((r) => r.status !== 'pending'));

  /** Asking is offered when there is something owed, somewhere to send it, and nothing already waiting. */
  protected readonly canAsk = computed(() => {
    const s = this.statement();
    return !!s && s.can_request && !!s.destination && s.balance.amount > 0 && !this.pending();
  });

  protected readonly tooMuch = computed(() => (this.askAmount() ?? 0) > (this.statement()?.balance.amount ?? 0));

  protected readonly askMoney = computed(() => ({ amount: this.askAmount() ?? 0, currency: this.statement()?.currency ?? 'CAD' }));

  protected readonly canSave = computed(() => {
    const d = this.draft();
    if (this.busy()) return false;
    if (d.rail === 'interac') return /.+@.+\..+/.test(d.interacEmail.trim());
    return d.accountName.trim() !== '' && d.bankName.trim() !== '' && d.accountNumber.replace(/\s/g, '') !== '';
  });

  ngOnInit(): void {
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      this.statement.set(await this.organizer.payouts());
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected day(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
  }

  protected railLabel(rail: string): string {
    return rail === 'interac' ? 'Interac' : 'Bank transfer';
  }

  protected requestLabel(status: PayoutRequestRow['status']): string {
    return { pending: 'Waiting', paid: 'Paid', rejected: 'Not paid', cancelled: 'Withdrawn' }[status];
  }

  protected requestTone(status: PayoutRequestRow['status']): 'success' | 'warning' | 'danger' | 'neutral' {
    return status === 'paid' ? 'success' : status === 'pending' ? 'warning' : status === 'rejected' ? 'danger' : 'neutral';
  }

  protected destinationSummary(): string {
    const d = this.statement()?.destination;
    if (!d) return '';
    if (d.rail === 'interac') return `Interac at ${d.interac_email ?? 'your address on file'}`;
    return d.account_last_four ? `the account ending ${d.account_last_four}` : 'your bank account';
  }

  protected startAsk(): void {
    // Offered as the whole balance: that is what most people are asking for.
    this.askAmount.set(this.statement()?.balance.amount ?? null);
    this.askNote.set('');
    this.askError.set(null);
    this.asking.set(true);
  }

  protected async ask(): Promise<void> {
    const amount = this.askAmount() ?? 0;
    if (amount <= 0 || this.tooMuch()) return;

    this.busy.set(true);
    this.askError.set(null);

    try {
      const { message } = await this.organizer.requestPayout(amount, this.askNote().trim() || null);
      this.asking.set(false);
      this.toasts.show(message, 'success');
      await this.load();
    } catch (error) {
      this.askError.set(messageOf(error, 'That request could not be sent.'));
    } finally {
      this.busy.set(false);
    }
  }

  protected async withdraw(request: PayoutRequestRow): Promise<void> {
    const sure = await this.dialogs.confirm({
      title: `Withdraw the request for ${formatMoney(request.amount)}?`,
      message: 'Nothing is sent. You can ask again whenever you like.',
      confirm: 'Withdraw',
      danger: true,
    });

    if (!sure) return;

    this.busy.set(true);

    try {
      const { message } = await this.organizer.withdrawPayoutRequest(request.id);
      this.toasts.show(message, 'success');
      await this.load();
    } catch (error) {
      this.toasts.show(messageOf(error, 'That request could not be withdrawn.'), 'danger');
    } finally {
      this.busy.set(false);
    }
  }

  private blank(): DestinationDraft {
    return { rail: 'interac', interacEmail: '', accountName: '', bankName: '', accountNumber: '', transit: '', institution: '', bankCode: '' };
  }

  protected set<K extends keyof DestinationDraft>(key: K, value: DestinationDraft[K]): void {
    this.draft.update((d) => ({ ...d, [key]: value }));
  }

  protected openDestination(): void {
    const s = this.statement();
    const current = s?.destination;

    this.formError.set(null);
    this.draft.set({
      ...this.blank(),
      // Interac is Canadian; a Naira account is always a bank transfer.
      rail: s?.currency === 'NGN' ? 'bank_transfer' : (current?.rail ?? 'interac'),
      interacEmail: current?.interac_email ?? '',
      accountName: current?.account_name ?? '',
      bankName: current?.bank_name ?? '',
      // Never prefilled: the API does not return it and could not.
      accountNumber: '',
    });
    this.editing.set(true);
  }

  protected async saveDestination(): Promise<void> {
    if (!this.canSave()) return;

    const d = this.draft();
    const body: Record<string, unknown> =
      d.rail === 'interac'
        ? { rail: 'interac', interac_email: d.interacEmail.trim() }
        : {
            rail: 'bank_transfer',
            account_name: d.accountName.trim(),
            bank_name: d.bankName.trim(),
            account_number: d.accountNumber.replace(/\s/g, ''),
            transit_number: d.transit.trim() || null,
            institution_number: d.institution.trim() || null,
            bank_code: d.bankCode.trim() || null,
          };

    this.busy.set(true);
    this.formError.set(null);

    try {
      await this.organizer.setPayoutDetails(body);
      this.editing.set(false);
      // What was typed does not outlive the sheet.
      this.draft.set(this.blank());
      this.toasts.show('Saved. It is checked before the next payout.', 'success');
      await this.load();
    } catch (error) {
      this.formError.set(messageOf(error, 'Those details could not be saved. Check them and try again.'));
    } finally {
      this.busy.set(false);
    }
  }
}
