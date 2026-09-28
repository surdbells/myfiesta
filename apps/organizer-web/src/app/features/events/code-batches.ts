import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ConfirmDialog, ToastStore, UiButton, UiConfirm, UiIcon, UiSelect, type SelectOption } from '@myfiesta/ui';
import { Download } from 'lucide-angular';
import { Api } from '../../core/api';
import { CodeBatch, Money, TicketType } from '../../core/api.types';
import { saveFile } from '../../core/download';
import { messageFor } from '../../core/errors';
import { amountProblem, formatMoney, toMinorUnits } from '../../core/money';
import { describeZone, localZone, zonedWallClockToIso } from '../../core/zoned-time';
import { whenCodeWorks } from '@myfiesta/shared/code-window';

/**
 * Single-use codes, made in bulk and handed out as a spreadsheet.
 *
 * Its own section on the codes screen, below the codes people type in by
 * name: a batch is a stack of two hundred codes for a sponsor or a radio
 * giveaway, and it is looked after as one thing — how many are used, the
 * download, and stopping the rest.
 */
@Component({
  selector: 'app-code-batches',
  imports: [FormsModule, UiButton, UiConfirm, UiIcon, UiSelect],
  templateUrl: './code-batches.html',
})
export class CodeBatches implements OnInit {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  private readonly confirmDialog = inject(ConfirmDialog);

  readonly eventId = input.required<string>();
  readonly eventSlug = input<string | null>(null);
  readonly currency = input('CAD');
  /** The event's zone: a batch's window is its wall clock, not the browser's. */
  readonly timezone = input<string | null>(null);
  readonly ticketTypes = input<TicketType[]>([]);
  readonly canManage = input(false);

  protected readonly downloadIcon = Download;

  readonly batches = signal<CodeBatch[]>([]);
  readonly loading = signal(true);
  readonly formOpen = signal(false);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly downloading = signal<string | null>(null);
  readonly stopping = signal<CodeBatch | null>(null);
  readonly stoppingBusy = signal(false);

  readonly form = signal(this.blank());

  readonly purposeOptions: SelectOption[] = [
    { value: 'discount', label: 'Takes money off' },
    { value: 'access', label: 'Unlocks tickets (presale)' },
  ];

  readonly discountTypeOptions: SelectOption[] = [
    { value: 'percentage', label: 'A percentage' },
    { value: 'fixed', label: 'A fixed amount' },
  ];

  /** What the codes will look like, before any exist. */
  readonly example = computed(() => {
    const prefix = (this.form().prefix.trim() || this.prefixFrom(this.form().name)).toUpperCase();

    return `${prefix || 'CODE'}-7K2M9Q`;
  });

  readonly canCreate = computed(() => {
    const f = this.form();
    const quantity = Number(f.quantity);

    if (!f.name.trim() || !Number.isInteger(quantity) || quantity < 1 || quantity > 1000) return false;

    if (f.purpose !== 'discount') return f.unlock_ticket_type_ids.length > 0;

    return f.discount_type === 'fixed'
      ? (toMinorUnits(f.discount_value) ?? 0) > 0
      : Number(f.discount_value) > 0;
  });

  /** A fixed amount is money, and is read as money is typed: "5,000" is five thousand. */
  readonly discountProblem = computed(() => {
    const f = this.form();

    return f.purpose === 'discount' && f.discount_type === 'fixed' ? amountProblem(f.discount_value) : null;
  });

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.api.codeBatches(this.eventId()).subscribe({
      next: ({ data }) => {
        this.batches.set(data);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  toggleUnlock(id: string): void {
    const ids = this.form().unlock_ticket_type_ids;
    this.form.set({ ...this.form(), unlock_ticket_type_ids: ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id] });
  }

  async create(): Promise<void> {
    if (!this.canCreate() || this.saving()) return;

    const f = this.form();
    const quantity = Number(f.quantity);
    const unlocks = this.ticketTypes()
      .filter((type) => f.unlock_ticket_type_ids.includes(type.id))
      .map((type) => type.name)
      .join(', ');
    const each =
      f.purpose === 'access'
        ? `Each one unlocks ${unlocks} for whoever types it.`
        : f.discount_type === 'percentage'
          ? `Each one takes ${Number(f.discount_value)}% off an order.`
          : `Each one takes ${formatMoney({ amount: toMinorUnits(f.discount_value) ?? 0, currency: this.currency() as Money['currency'] })} off an order.`;

    const sure = await this.confirmDialog.confirm({
      title: `Make ${quantity.toLocaleString()} ${quantity === 1 ? 'code' : 'codes'} for ${f.name.trim()}?`,
      body: `${each} Each works once.`,
      consequences: [
        // From the batch's own From and Until: a batch for a Friday giveaway
        // described as working now is handed out now, and refused until Friday.
        whenCodeWorks({
          startsAt: f.starts_at ? zonedWallClockToIso(f.starts_at, this.zone()) : null,
          endsAt: f.ends_at ? zonedWallClockToIso(f.ends_at, this.zone()) : null,
          format: (iso) => this.moment(iso),
          plural: true,
        }),
        'You can turn off the unused ones later.',
      ],
      confirmLabel: `Make ${quantity.toLocaleString()} ${quantity === 1 ? 'code' : 'codes'}`,
      tone: 'default',
    });

    if (!sure || this.saving()) return;

    this.saving.set(true);
    this.error.set(null);

    this.api
      .createCodeBatch(this.eventId(), {
        name: f.name.trim(),
        prefix: f.prefix.trim() || null,
        quantity,
        discount_type: f.purpose === 'discount' ? f.discount_type : null,
        discount_value:
          f.purpose === 'discount'
            ? f.discount_type === 'percentage'
              ? Math.round(Number(f.discount_value) * 100)
              : toMinorUnits(f.discount_value)
            : null,
        unlock_ticket_type_ids: f.purpose === 'access' ? f.unlock_ticket_type_ids : [],
        starts_at: f.starts_at ? zonedWallClockToIso(f.starts_at, this.zone()) : null,
        ends_at: f.ends_at ? zonedWallClockToIso(f.ends_at, this.zone()) : null,
      })
      .subscribe({
        next: (batch) => {
          this.saving.set(false);
          this.formOpen.set(false);
          this.form.set(this.blank());
          this.batches.set([batch, ...this.batches()]);
          this.toasts.show(`${batch.quantity} codes made. Download them to hand out.`, 'success');
        },
        error: (response) => {
          this.saving.set(false);
          this.error.set(messageFor(response, 'Those codes could not be made.'));
        },
      });
  }

  download(batch: CodeBatch): void {
    this.downloading.set(batch.id);

    this.api.exportCodeBatch(this.eventId(), batch.id).subscribe({
      next: (file) => {
        this.downloading.set(null);
        saveFile(file, `${this.eventSlug() ?? 'event'}-${batch.prefix.toLowerCase()}-codes.csv`);
      },
      error: () => {
        this.downloading.set(null);
        this.toasts.show('The codes could not be downloaded. Try again.', 'danger');
      },
    });
  }

  confirmStop(): void {
    const batch = this.stopping();
    if (!batch) return;

    this.stoppingBusy.set(true);

    this.api.deactivateCodeBatch(this.eventId(), batch.id).subscribe({
      next: ({ message }) => {
        this.stoppingBusy.set(false);
        this.stopping.set(null);
        this.toasts.show(message, 'success');
        this.load();
      },
      error: (response) => {
        this.stoppingBusy.set(false);
        this.toasts.show(messageFor(response, 'Those codes could not be turned off.'), 'danger');
      },
    });
  }

  readonly zoneName = computed(() => describeZone(this.zone()));

  private zone(): string {
    return this.timezone() ?? localZone();
  }

  /** A moment in the batch's window, on the event's wall clock — as the codes above write it. */
  private moment(iso: string): string {
    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
      timeZone: this.zone(),
    }).format(new Date(iso));
  }

  unused(batch: CodeBatch): number {
    return batch.quantity - batch.used - batch.turned_off;
  }

  /** What turning them off does, without "the 0 already used" when none are. */
  stopConsequence(batch: CodeBatch): string {
    const count = this.unused(batch);
    const stops = `${count === 1 ? 'The 1 code' : `The ${count} codes`} nobody has used will stop working.`;
    const kept = batch.used === 0 ? '' : ` ${batch.used === 1 ? 'The 1 already used keeps its order' : `The ${batch.used} already used keep their orders`}.`;

    return `${stops}${kept} This cannot be undone.`;
  }

  private prefixFrom(name: string): string {
    return (name.trim().split(/\s+/)[0] ?? '')
      .normalize('NFD')
      .replace(/[^A-Za-z0-9]/g, '')
      .slice(0, 8);
  }

  private blank() {
    return {
      name: '',
      prefix: '',
      quantity: '100',
      purpose: 'discount' as 'discount' | 'access',
      discount_type: 'percentage' as 'percentage' | 'fixed',
      discount_value: '100',
      unlock_ticket_type_ids: [] as string[],
      starts_at: '',
      ends_at: '',
    };
  }
}
