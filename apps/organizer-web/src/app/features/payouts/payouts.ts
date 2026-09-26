import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import {
  ToastStore,
  UiBadge,
  UiButton,
  UiEmpty,
  UiErrorState,
  UiField,
  UiIcon,
  UiModal,
  UiPageHeader,
  UiSelect,
  UiSkeleton,
  type SelectOption,
} from '@myfiesta/ui';
import { Banknote, Landmark, Pencil, ShieldCheck } from 'lucide-angular';
import { Api } from '../../core/api';
import { Money, PayoutRequestRow, PayoutStatement, PayoutDestination } from '../../core/api.types';
import { formatMoney, toMinorUnits } from '../../core/money';
import { messageFor } from '../../core/errors';

/** The destination form, as somebody types it. */
interface DestinationDraft {
  rail: 'interac' | 'bank_transfer';
  interac_email: string;
  account_name: string;
  bank_name: string;
  account_number: string;
  transit_number: string;
  institution_number: string;
  bank_code: string;
}

/**
 * What is owed, what has been sent, and where it goes.
 *
 * None of this was reachable. The settlements table, the ledger and the payout
 * details have existed since the first migrations and nothing returned any of
 * them — the dashboard showed a balance and there was no screen that could say
 * which events it came from or whether a penny had ever been paid.
 *
 * The order answers the three questions as they are actually asked: what am I
 * owed, which nights is that, and what have you sent me. The destination sits
 * last because it is set once and then never looked at again — until it is
 * wrong, which is why it is on the same screen rather than buried in settings.
 *
 * Everybody who can see the money sees where it goes; only an owner may change
 * it. Pointing the payouts at another account is how a member of a team
 * becomes a thief, so the API refuses anybody else and this screen tells them
 * who can instead of offering a form that would fail.
 */
@Component({
  selector: 'app-payouts',
  imports: [
    FormsModule,
    RouterLink,
    UiPageHeader,
    UiButton,
    UiBadge,
    UiField,
    UiSelect,
    UiModal,
    UiEmpty,
    UiErrorState,
    UiSkeleton,
    UiIcon,
  ],
  templateUrl: './payouts.html',
})
export class Payouts {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);

  protected readonly interacIcon = Banknote;
  protected readonly bankIcon = Landmark;
  protected readonly editIcon = Pencil;
  protected readonly verifiedIcon = ShieldCheck;

  readonly statement = signal<PayoutStatement | null>(null);
  readonly loading = signal(true);
  readonly failed = signal(false);
  readonly refused = signal(false);

  readonly formOpen = signal(false);
  readonly saving = signal(false);
  readonly formError = signal<string | null>(null);
  readonly draft = signal<DestinationDraft>(this.blank());

  readonly railOptions: SelectOption[] = [
    { value: 'interac', label: 'Interac e-Transfer' },
    { value: 'bank_transfer', label: 'Bank transfer' },
  ];

  // --- asking to be paid ----------------------------------------------------

  /** The amount being asked for, as typed, in major units. */
  readonly askAmount = signal('');
  readonly askNote = signal('');
  readonly asking = signal(false);
  readonly askError = signal<string | null>(null);
  readonly withdrawing = signal(false);

  readonly requests = computed<PayoutRequestRow[]>(() => this.statement()?.requests ?? []);
  readonly pendingRequest = computed(() => this.requests().find((r) => r.status === 'pending') ?? null);
  readonly pastRequests = computed(() => this.requests().filter((r) => r.status !== 'pending'));

  /** Asking is offered when there is something owed, somewhere to send it, and nothing already waiting. */
  readonly canAsk = computed(() => {
    const statement = this.statement();

    return !!statement && statement.can_request && !!statement.destination && statement.balance.amount > 0 && !this.pendingRequest();
  });

  readonly askTooMuch = computed(() => {
    const balance = this.statement()?.balance.amount ?? 0;

    return this.askAmount() !== '' && toMinorUnits(this.askAmount()) > balance;
  });

  constructor() {
    this.load();
  }

  ask(): void {
    const amount = toMinorUnits(this.askAmount());
    if (amount <= 0 || this.askTooMuch() || this.asking()) return;

    this.asking.set(true);
    this.askError.set(null);

    this.api.requestPayout(amount, this.askNote().trim() || null).subscribe({
      next: ({ message }) => {
        this.asking.set(false);
        this.askNote.set('');
        this.toasts.show(message, 'success');
        this.load();
      },
      error: (response) => {
        this.asking.set(false);
        this.askError.set(messageFor(response, 'That request could not be sent.'));
      },
    });
  }

  withdraw(request: PayoutRequestRow): void {
    if (this.withdrawing()) return;

    this.withdrawing.set(true);

    this.api.withdrawPayoutRequest(request.id).subscribe({
      next: ({ message }) => {
        this.withdrawing.set(false);
        this.toasts.show(message, 'success');
        this.load();
      },
      error: (response) => {
        this.withdrawing.set(false);
        this.toasts.show(messageFor(response, 'That request could not be withdrawn.'), 'danger');
      },
    });
  }

  setAskAmount(value: string | number | null): void {
    this.askAmount.set(value === null || value === undefined ? '' : String(value));
  }

  /** "your Interac address a•••@example.com", "the bank account ending 5678". */
  destinationSummary(): string {
    const destination = this.statement()?.destination;
    if (!destination) return '';

    if (destination.rail === 'interac') return `Interac at ${destination.interac_email ?? 'your address on file'}`;

    return destination.account_last_four ? `the bank account ending ${destination.account_last_four}` : 'your bank account';
  }

  requestLabel(status: PayoutRequestRow['status']): string {
    return { pending: 'Waiting', paid: 'Paid', rejected: 'Not paid', cancelled: 'Withdrawn' }[status];
  }

  requestTone(status: PayoutRequestRow['status']): 'success' | 'warning' | 'danger' | 'neutral' {
    return status === 'paid' ? 'success' : status === 'pending' ? 'warning' : status === 'rejected' ? 'danger' : 'neutral';
  }

  day(iso: string): string {
    return new Intl.DateTimeFormat('en-CA', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(iso));
  }

  load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.refused.set(false);

    this.api.payouts().subscribe({
      next: (statement) => {
        this.statement.set(statement);
        this.loading.set(false);
        // Offered as the whole balance: that is what most people are asking for.
        this.askAmount.set(statement.balance.amount > 0 ? (statement.balance.amount / 100).toFixed(2) : '');
      },
      error: (error) => {
        this.loading.set(false);

        // A 403 here is not a fault. It is the server saying this account may
        // not see the money, and telling somebody to "try again" would have
        // them retrying something that will never work.
        if (error?.status === 403) this.refused.set(true);
        else this.failed.set(true);
      },
    });
  }

  // --- the figures ---------------------------------------------------------

  readonly balance = computed<Money | null>(() => this.statement()?.balance ?? null);

  readonly events = computed(() => this.statement()?.events ?? []);
  readonly settlements = computed(() => this.statement()?.settlements ?? []);
  readonly destination = computed<PayoutDestination | null>(
    () => this.statement()?.destination ?? null,
  );

  /**
   * Whether this member may change where the money goes.
   *
   * Owners only, and the server's answer rather than a role check here: the
   * API refuses everybody else, so offering them the form would only lead to
   * a 403 after they had typed a whole account number.
   */
  readonly canChangeDestination = computed(() => this.statement()?.can_change_destination ?? false);

  /** What everybody else is told in place of the Add and Change buttons. */
  readonly ownerOnly = "Only the organization's owner can change where payouts go.";

  /**
   * Whether there is money owed with nowhere to send it.
   *
   * The single most useful thing this screen can say, and the reason the
   * destination is here rather than in settings: a balance that cannot be paid
   * is a balance nobody has told the organizer about.
   */
  readonly needsDestination = computed(
    () => !this.destination() && (this.balance()?.amount ?? 0) > 0,
  );

  cash(money: Money): string {
    return formatMoney(money);
  }

  when(iso: string | null): string {
    if (!iso) return '—';

    return new Intl.DateTimeFormat('en-CA', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    }).format(new Date(iso));
  }

  railLabel(rail: string): string {
    return rail === 'interac' ? 'Interac e-Transfer' : 'Bank transfer';
  }

  settlementTone(type: string): 'success' | 'warning' | 'danger' {
    // Overdraft is money sent beyond what was earned. It is not an error, and
    // it is not routine either — it is the row somebody will ask about.
    return type === 'full' ? 'success' : type === 'partial' ? 'warning' : 'danger';
  }

  // --- where it goes -------------------------------------------------------

  private blank(): DestinationDraft {
    return {
      rail: 'interac',
      interac_email: '',
      account_name: '',
      bank_name: '',
      account_number: '',
      transit_number: '',
      institution_number: '',
      bank_code: '',
    };
  }

  openForm(): void {
    if (!this.canChangeDestination()) return;

    const current = this.destination();

    this.formError.set(null);
    this.draft.set({
      ...this.blank(),
      rail: current?.rail ?? 'interac',
      interac_email: current?.interac_email ?? '',
      account_name: current?.account_name ?? '',
      bank_name: current?.bank_name ?? '',
      // Never prefilled. The API does not return it and could not: the point
      // of storing only the last four is that the rest never comes back.
      account_number: '',
    });
    this.formOpen.set(true);
  }

  update<K extends keyof DestinationDraft>(key: K, value: DestinationDraft[K]): void {
    this.draft.set({ ...this.draft(), [key]: value });
  }

  readonly canSave = computed(() => {
    const draft = this.draft();

    if (draft.rail === 'interac') {
      return draft.interac_email.trim().includes('@');
    }

    return (
      draft.account_name.trim() !== '' &&
      draft.bank_name.trim() !== '' &&
      draft.account_number.trim() !== ''
    );
  });

  save(): void {
    if (!this.canSave() || this.saving()) return;

    const draft = this.draft();

    const body: Record<string, unknown> =
      draft.rail === 'interac'
        ? { rail: 'interac', interac_email: draft.interac_email.trim() }
        : {
            rail: 'bank_transfer',
            account_name: draft.account_name.trim(),
            bank_name: draft.bank_name.trim(),
            account_number: draft.account_number.replace(/\s/g, ''),
            transit_number: draft.transit_number.trim() || null,
            institution_number: draft.institution_number.trim() || null,
            bank_code: draft.bank_code.trim() || null,
          };

    this.saving.set(true);
    this.formError.set(null);

    this.api.setPayoutDetails(body).subscribe({
      next: () => {
        this.saving.set(false);
        this.formOpen.set(false);
        this.toasts.show('Payout details saved.');
        this.load();
      },
      error: (error) => {
        this.saving.set(false);
        this.formError.set(
          error?.error?.message ?? 'Those details could not be saved. Check them and try again.',
        );
      },
    });
  }
}
