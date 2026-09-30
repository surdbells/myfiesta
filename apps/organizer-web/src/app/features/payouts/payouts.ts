import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import {
  ConfirmDialog,
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
  UiSortHeader,
  sortLocally,
  type Sort,
} from '@myfiesta/ui';
import { Banknote, Landmark, Pencil, ShieldCheck } from 'lucide-angular';
import { Api } from '../../core/api';
import { Money, PayoutOverdraft, PayoutRequestRow, PayoutStatement, PayoutDestination } from '../../core/api.types';
import { amountProblem, formatMoney, toMinorUnits } from '../../core/money';
import { isEmailUnverified, messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';

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
    UiSortHeader,
  ],
  templateUrl: './payouts.html',
})
export class Payouts {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  private readonly confirmDialog = inject(ConfirmDialog);
  private readonly session = inject(SessionStore);

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

    return (toMinorUnits(this.askAmount()) ?? 0) > balance;
  });

  /** "50,000" is fifty thousand; "50,00" is a question, asked under the box. */
  readonly askProblem = computed(() => amountProblem(this.askAmount()));

  constructor() {
    this.load();
  }

  async ask(): Promise<void> {
    const amount = toMinorUnits(this.askAmount());
    const balance = this.statement()?.balance;
    if (amount === null || amount <= 0 || this.askTooMuch() || this.asking() || !balance) return;

    const asked = formatMoney({ ...balance, amount });

    // The amount and where it lands, said back at the moment of asking: the
    // box is prefilled with the whole balance, and a request is paid to the
    // destination as it stands when myFiesta pays it.
    const sure = await this.confirmDialog.confirm({
      title: `Ask to be paid ${asked}?`,
      body: `myFiesta sends ${asked} to ${this.destinationSummary()}, and emails you when it goes out.`,
      consequences: [
        amount < balance.amount
          ? `The other ${formatMoney({ ...balance, amount: balance.amount - amount })} stays owed to you.`
          : 'That is everything you are owed today.',
        'Nothing more can be asked for until this one is paid or withdrawn.',
      ],
      confirmLabel: `Ask for ${asked}`,
      tone: 'default',
    });

    if (!sure || this.asking()) return;

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
        // An unproved address gets the shell's prompt instead.
        if (isEmailUnverified(response)) return;
        this.askError.set(messageFor(response, 'That request could not be sent.'));
      },
    });
  }

  async withdraw(request: PayoutRequestRow): Promise<void> {
    if (this.withdrawing()) return;

    const sure = await this.confirmDialog.confirm({
      title: `Withdraw the request for ${formatMoney(request.amount)}?`,
      body: 'myFiesta does not pay it. The money stays owed to you, and you can ask again whenever you like.',
      confirmLabel: 'Withdraw the request',
      tone: 'danger',
    });

    if (!sure || this.withdrawing()) return;

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

  /**
   * Money owed back to myFiesta, and how it is coming back.
   *
   * An advance, or refunds after a payout, take the balance below zero, and
   * the next sales pay it back before anything more is paid out. Said in the
   * server's own sentence — the one myFiesta's staff read too — with the
   * figures beside it, so a balance below zero never reads as money gone
   * missing.
   */
  readonly overdraft = computed<PayoutOverdraft | null>(() => this.statement()?.overdraft ?? null);

  /** What the organization owes, as an amount to show rather than a minus sign. */
  readonly owedToUs = computed<Money | null>(() => {
    const balance = this.balance();

    return balance && balance.amount < 0 ? { ...balance, amount: -balance.amount } : null;
  });

  readonly events = computed(() => this.statement()?.events ?? []);
  readonly settlements = computed(() => this.statement()?.settlements ?? []);

  // --- reading the statement ------------------------------------------------
  //
  // The statement comes whole, so its two tables sort and narrow in the
  // browser: by what is still owed first, because that is what somebody
  // opens this screen to find out.

  readonly eventSort = signal<Sort>({ column: 'balance', direction: 'desc' });
  readonly eventSearch = signal('');
  /** Only the nights with money still to come. */
  readonly owedOnly = signal(false);

  readonly shownEvents = computed(() => {
    const term = fold(this.eventSearch());
    const rows = this.events().filter(
      (row) => (!term || fold(row.title).includes(term)) && (!this.owedOnly() || row.balance.amount > 0),
    );

    return sortLocally(rows, this.eventSort(), {
      title: (row) => row.title,
      starts_at: (row) => (row.starts_at ? Date.parse(row.starts_at) : null),
      gross: (row) => row.gross.amount,
      settled: (row) => row.settled.amount,
      balance: (row) => row.balance.amount,
    });
  });

  readonly paymentSort = signal<Sort>({ column: 'settled_at', direction: 'desc' });
  readonly paymentTypes = signal<string[]>([]);

  readonly paymentTypeOptions: SelectOption[] = [
    { value: 'full', label: 'Full' },
    { value: 'partial', label: 'Partial' },
    { value: 'overdraft', label: 'Includes an advance' },
  ];

  readonly shownSettlements = computed(() => {
    const types = this.paymentTypes();
    const rows = this.settlements().filter((row) => types.length === 0 || types.includes(row.type));

    return sortLocally(rows, this.paymentSort(), {
      settled_at: (row) => (row.settled_at ? Date.parse(row.settled_at) : null),
      amount: (row) => row.amount.amount,
    });
  });
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

  /**
   * What everybody else is told in place of the Add and Change buttons.
   *
   * Staff acting as the organization hold the owner's role, so "only the
   * owner" would contradict the admin, which says they see the console as an
   * owner. They are told what the server tells them when it refuses.
   */
  readonly ownerOnly = computed(() =>
    this.session.impersonation()?.withheld.includes('payouts.destination')
      ? 'Where payouts are sent is the owners’ decision alone. Staff cannot change it while acting as the organization.'
      : "Only the organization's owner can change where payouts go.",
  );

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

  /** "overdraft" is our word; the organizer's is that part of it was advanced. */
  settlementLabel(type: string): string {
    return type === 'overdraft' ? 'includes an advance' : type;
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

  async save(): Promise<void> {
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

    if (!(await this.confirmSave(draft)) || this.saving()) return;

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
        // An unproved address gets the shell's prompt on top of this form,
        // which stays open with what was typed.
        if (isEmailUnverified(error)) return;
        this.formError.set(
          error?.error?.message ?? 'Those details could not be saved. Check them and try again.',
        );
      },
    });
  }

  /**
   * Where the money will go, said back before it is saved.
   *
   * Pointing the payouts somewhere new is the one change on this screen that
   * sends real money to the wrong place when it holds a typo, and it takes
   * the verified mark off. So the destination is named — an account by its
   * last four only, as everywhere else — and so is who hears about it.
   */
  private confirmSave(draft: DestinationDraft): Promise<boolean> {
    const digits = draft.account_number.replace(/\D/g, '');
    const where =
      draft.rail === 'interac'
        ? `Interac at ${draft.interac_email.trim()}`
        : `${draft.account_name.trim()} at ${draft.bank_name.trim()}${digits.length >= 4 ? `, account ending ${digits.slice(-4)}` : ''}`;
    const current = this.destination();
    const consequences = current
      ? ['If this is a different account, every owner of the organization gets an email saying where payouts go now.']
      : ['Every owner of the organization gets an email saying where payouts go.'];

    if (current?.verified_at) consequences.push('A different account loses the verified mark until myFiesta has checked it.');

    return this.confirmDialog.confirm({
      title: current ? 'Change where payouts go?' : 'Save these payout details?',
      body: `From now on, payouts are sent to ${where}.`,
      consequences,
      confirmLabel: current ? 'Change payout details' : 'Save payout details',
      tone: current ? 'danger' : 'default',
    });
  }
}

/** Case and accents aside, for matching what somebody typed against a title. */
function fold(text: string): string {
  return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim();
}
