import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ToastStore, UiButton, UiConfirm, UiIcon, UiSelect, type SelectOption } from '@myfiesta/ui';
import { Download } from 'lucide-angular';
import { Api } from '../../core/api';
import { CodeBatch, TicketType } from '../../core/api.types';
import { saveFile } from '../../core/download';
import { messageFor } from '../../core/errors';
import { toMinorUnits } from '../../core/money';

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

  readonly eventId = input.required<string>();
  readonly eventSlug = input<string | null>(null);
  readonly currency = input('CAD');
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

    return f.purpose === 'discount' ? Number(f.discount_value) > 0 : f.unlock_ticket_type_ids.length > 0;
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

  create(): void {
    if (!this.canCreate() || this.saving()) return;

    const f = this.form();
    this.saving.set(true);
    this.error.set(null);

    this.api
      .createCodeBatch(this.eventId(), {
        name: f.name.trim(),
        prefix: f.prefix.trim() || null,
        quantity: Number(f.quantity),
        discount_type: f.purpose === 'discount' ? f.discount_type : null,
        discount_value:
          f.purpose === 'discount'
            ? f.discount_type === 'percentage'
              ? Math.round(Number(f.discount_value) * 100)
              : toMinorUnits(f.discount_value)
            : null,
        unlock_ticket_type_ids: f.purpose === 'access' ? f.unlock_ticket_type_ids : [],
        starts_at: f.starts_at ? new Date(f.starts_at).toISOString() : null,
        ends_at: f.ends_at ? new Date(f.ends_at).toISOString() : null,
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
