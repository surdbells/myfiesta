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
  UiSkeleton,
} from '@myfiesta/ui';
import { Banknote, Landmark, Pencil, ShieldCheck } from 'lucide-angular';
import { Api } from '../../core/api';
import { Money, PayoutStatement, PayoutDestination } from '../../core/api.types';
import { formatMoney } from '../../core/money';

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
    UiModal,
    UiEmpty,
    UiErrorState,
    UiSkeleton,
    UiIcon,
  ],
  templateUrl: './payouts.html',
  styleUrl: './payouts.css',
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

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.refused.set(false);

    this.api.payouts().subscribe({
      next: (statement) => {
        this.statement.set(statement);
        this.loading.set(false);
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
