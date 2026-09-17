import { Component, computed, effect, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  Api,
  ApiError,
  DoorPaymentMethod,
  DoorSale,
  Sellable,
} from '../../core/api';
import { formatMoney } from '../../core/money';
import { MfBadge, MfButton, MfCard, MfField, MfSegmented, MfSheet, ToastStore, type MfSegment } from '../../ui';

/**
 * Selling to somebody standing in front of you.
 *
 * Built for a hand at a dark door with a queue behind it, which decides most
 * of what is here. Nothing to type: tiers are rows with a plus and a minus,
 * and how they paid is three buttons the width of a thumb. The total is the
 * biggest thing on the screen, because it is the number being said out loud.
 *
 * And nothing is asked of the buyer. A name and an address are behind a link
 * for the rare person who wants their ticket emailed; everybody else pays,
 * gets scanned by this same phone, and walks in.
 *
 * Online only. A sale is money changing hands and stock leaving the room, and
 * neither can be decided by a phone with no signal — the offline list is there
 * to admit people who already hold a ticket, not to invent ones nobody has
 * paid for.
 */
@Component({
  selector: 'mf-door-sell',
  imports: [FormsModule, MfSheet, MfCard, MfButton, MfBadge, MfField, MfSegmented],
  template: `
    <mf-sheet
      [open]="open()"
      heading="Sell a ticket"
      [subheading]="sold() ? null : 'Take the money, then scan them in'"
      (closed)="close()"
    >
      @if (sold(); as sale) {
        <!-- Done. The code is here because this phone is about to scan it. -->
        <mf-card>
          <p class="done">Sold · {{ money(sale.total) }}</p>
          <p class="subtle">{{ methodLabel(sale.method) }} · {{ sale.reference }}</p>

          @if (sale.emailed) {
            <p class="subtle">The ticket is on its way to them by email.</p>
          }
        </mf-card>

        <ul class="codes">
          @for (ticket of sale.tickets; track ticket.id) {
            <li>
              <span class="code">{{ ticket.code }}</span>
              <span class="subtle">{{ ticket.type }}</span>
            </li>
          }
        </ul>

        <button mfButton block size="lg" (click)="admit(sale)">Scan them in now</button>
        <button mfButton class="mt" block variant="secondary" (click)="again()">Sell another</button>
      } @else {
        @if (loading()) {
          <p class="subtle">Loading what is on sale…</p>
        } @else if (failed(); as message) {
          <p class="wrong">{{ message }}</p>
          <button mfButton block variant="secondary" (click)="load()">Try again</button>
        } @else {
          <ul class="tiers">
            @for (tier of tiers(); track tier.id) {
              <li>
                <mf-card quiet>
                  <div class="row">
                    <span class="what">
                      <span class="name">{{ tier.name }}</span>
                      <span class="subtle">
                        {{ money(tier.price) }}
                        @if (tier.remaining !== null) {
                          · {{ tier.remaining }} left
                        }
                      </span>
                    </span>

                    @if (tier.sold_out) {
                      <mf-badge tone="neutral">Gone</mf-badge>
                    } @else {
                      <span class="stepper">
                        <button
                          type="button"
                          [attr.aria-label]="'One fewer ' + tier.name"
                          [disabled]="count(tier.id) === 0"
                          (click)="adjust(tier, -1)"
                        >
                          −
                        </button>
                        <span class="count" aria-live="polite">{{ count(tier.id) }}</span>
                        <button
                          type="button"
                          [attr.aria-label]="'One more ' + tier.name"
                          [disabled]="atCeiling(tier)"
                          (click)="adjust(tier, 1)"
                        >
                          +
                        </button>
                      </span>
                    }
                  </div>
                </mf-card>
              </li>
            }
          </ul>

          <mf-segmented
            class="mt"
            ariaLabel="How they paid"
            [segments]="methods()"
            [(value)]="method"
          />

          @if (contact()) {
            <mf-field class="mt" label="Name">
              <input name="doorName" autocomplete="off" [ngModel]="name()" (ngModelChange)="name.set($event)" />
            </mf-field>
            <mf-field label="Email">
              <input
                name="doorEmail"
                type="email"
                inputmode="email"
                autocomplete="off"
                [ngModel]="email()"
                (ngModelChange)="email.set($event)"
              />
            </mf-field>
          } @else {
            <button mfButton class="mt" variant="ghost" size="sm" (click)="contact.set(true)">
              Email them the ticket
            </button>
          }

          <!-- The number said out loud, priced by the server. A phone adding
               up tiers itself lands a cent out on the tax often enough, and a
               cent is somebody holding coins while a screen disagrees. -->
          <p class="total" aria-live="polite">
            @if (total(); as amount) {
              {{ money(amount) }}
            } @else {
              —
            }
          </p>

          @if (wrong(); as message) {
            <p class="wrong">{{ message }}</p>
          }

          <button
            mfButton
            block
            size="lg"
            [loading]="selling()"
            [disabled]="chosen() === 0 || selling() || total() === null"
            (click)="sell()"
          >
            Take payment
          </button>
        }
      }
    </mf-sheet>
  `,
  styles: `
    .tiers,
    .codes {
      display: grid;
      gap: var(--space-2);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .row {
      display: flex;
      align-items: center;
      gap: var(--space-4);
    }

    .what {
      flex: 1;
      min-width: 0;
      display: grid;
    }

    .name {
      font-weight: var(--font-weight-semibold);
      color: var(--text);
    }

    .subtle {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    /* Finger-sized, because this is used one-handed in the dark. */
    .stepper {
      display: flex;
      align-items: center;
      gap: var(--space-1);
      padding: 3px;
      border-radius: var(--radius-md);
      background: var(--surface-inset);
    }

    .stepper button {
      width: 44px;
      height: 44px;
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      background: var(--surface-raised);
      color: var(--text);
      font-size: var(--font-size-lg);
    }

    .stepper button:disabled {
      opacity: 0.4;
    }

    .count {
      min-width: 2.5rem;
      text-align: center;
      font-weight: var(--font-weight-semibold);
      font-variant-numeric: tabular-nums;
    }

    .total {
      margin: var(--space-5) 0 var(--space-3);
      text-align: center;
      font-size: var(--font-size-3xl);
      font-weight: var(--font-weight-bold);
      font-variant-numeric: tabular-nums;
      color: var(--text);
    }

    .done {
      margin: 0;
      font-size: var(--font-size-xl);
      font-weight: var(--font-weight-bold);
      color: var(--success);
    }

    .codes .code {
      font-family: var(--font-family-mono);
      font-size: var(--font-size-lg);
      letter-spacing: 0.08em;
    }

    .codes li {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: var(--space-3);
      padding: var(--space-3) 0;
      border-bottom: 1px solid var(--border-subtle);
    }

    .wrong {
      margin: var(--space-3) 0;
      color: var(--danger-text);
      font-size: var(--font-size-sm);
    }

    .mt {
      margin-top: var(--space-4);
    }
  `,
})
export class DoorSell {
  readonly open = input(false);
  readonly eventId = input.required<string>();
  readonly token = input<string | null>(null);

  /** Closed, and whether anything was sold while it was open. */
  readonly closed = output<boolean>();

  /** A code to put through the door, straight after selling it. */
  readonly admitted = output<string>();

  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);

  readonly sellable = signal<Sellable | null>(null);
  readonly loading = signal(false);
  readonly failed = signal<string | null>(null);

  readonly basket = signal<Record<string, number>>({});
  readonly method = signal<string>('cash');
  readonly contact = signal(false);
  readonly name = signal('');
  readonly email = signal('');

  readonly total = signal<{ amount: number; currency: string } | null>(null);
  readonly selling = signal(false);
  readonly wrong = signal<string | null>(null);
  readonly sold = signal<DoorSale | null>(null);

  private soldAnything = false;

  readonly tiers = computed(() => this.sellable()?.ticket_types ?? []);

  readonly methods = computed<MfSegment[]>(() =>
    (this.sellable()?.methods ?? ['cash']).map((method) => ({
      value: method,
      label: this.methodLabel(method),
    })),
  );

  readonly chosen = computed(() =>
    Object.values(this.basket()).reduce((sum, quantity) => sum + quantity, 0),
  );

  money = formatMoney;

  constructor() {
    /*
     * Loaded when the sheet opens rather than when the door screen does.
     *
     * What is left changes all night, and the number somebody reads out
     * loud has to be the number as it is at that moment — not as it was
     * when a phone was propped against a wall three hours ago.
     */
    effect(() => {
      if (this.open() && this.sellable() === null && ! this.loading()) void this.load();
    });
  }

  methodLabel(method: string): string {
    return method === 'cash' ? 'Cash' : method === 'card' ? 'Card' : 'Transfer';
  }

  count(id: string): number {
    return this.basket()[id] ?? 0;
  }

  atCeiling(tier: Sellable['ticket_types'][number]): boolean {
    return tier.remaining !== null && this.count(tier.id) >= tier.remaining;
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.failed.set(null);

    try {
      this.sellable.set(await this.api.sellable(this.eventId(), this.token() ?? undefined));
    } catch (error) {
      this.failed.set(
        error instanceof ApiError && error.status === 0
          ? 'No signal. Selling needs a connection — scanning does not.'
          : error instanceof Error
            ? error.message
            : 'Could not load what is on sale.',
      );
    } finally {
      this.loading.set(false);
    }
  }

  adjust(tier: Sellable['ticket_types'][number], delta: number): void {
    const next = Math.max(0, this.count(tier.id) + delta);

    this.basket.set({ ...this.basket(), [tier.id]: next });
    void this.reprice();
  }

  /** Ask the server what to say out loud. */
  private async reprice(): Promise<void> {
    this.wrong.set(null);

    const items = this.items();

    if (items.length === 0) {
      this.total.set(null);

      return;
    }

    try {
      const quote = await this.api.doorQuote(this.eventId(), items, this.token() ?? undefined);

      this.total.set(quote.total);
    } catch (error) {
      this.total.set(null);
      this.wrong.set(error instanceof Error ? error.message : 'That could not be priced.');
    }
  }

  async sell(): Promise<void> {
    if (this.chosen() === 0 || this.selling()) return;

    this.selling.set(true);
    this.wrong.set(null);

    try {
      const sale = await this.api.sellAtDoor(
        this.eventId(),
        {
          items: this.items(),
          method: this.method() as DoorPaymentMethod,
          name: this.name().trim() || undefined,
          email: this.email().trim() || undefined,
        },
        this.token() ?? undefined,
      );

      this.soldAnything = true;
      this.sold.set(sale);
      this.toasts.show(`${this.money(sale.total)} taken.`, 'success');
    } catch (error) {
      this.wrong.set(
        error instanceof ApiError && error.status === 0
          ? 'No signal. Nothing has been sold — try again when it is back.'
          : error instanceof Error
            ? error.message
            : 'That sale did not go through.',
      );
    } finally {
      this.selling.set(false);
    }
  }

  /** Straight to the scanner with the code that was just minted. */
  admit(sale: DoorSale): void {
    const code = sale.tickets[0]?.code;

    if (code) this.admitted.emit(code);

    this.close();
  }

  again(): void {
    this.sold.set(null);
    this.basket.set({});
    this.total.set(null);
    this.name.set('');
    this.email.set('');
    this.contact.set(false);
  }

  close(): void {
    const sold = this.soldAnything;

    this.soldAnything = false;
    this.again();
    this.closed.emit(sold);
  }

  private items(): { ticket_type_id: string; quantity: number }[] {
    return Object.entries(this.basket())
      .filter(([, quantity]) => quantity > 0)
      .map(([ticket_type_id, quantity]) => ({ ticket_type_id, quantity }));
  }
}
