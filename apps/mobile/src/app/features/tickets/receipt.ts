import { Component, computed, input } from '@angular/core';
import type { Receipt, ReceiptParty, ReceiptTax } from '@myfiesta/api-types';
import { formatMoney, type Money } from '../../core/money';
import { MfCard } from '../../ui';

/**
 * What was paid, to whom, and each tax on it.
 *
 * Below everything a door asks about, and folded shut, because at the front of
 * a queue it is in the way. It is the same receipt the email carries, for the
 * buyer who needs it for an expense claim: the rates, names and registration
 * numbers are the ones the order was charged under, not whatever they are
 * today.
 *
 * Only on tickets this account bought. What somebody paid is not shown to the
 * person they passed a ticket to, and there are no ticket codes on it.
 */
@Component({
  selector: 'mf-receipt',
  imports: [MfCard],
  template: `
    @let r = receipt();
    <mf-card class="receipt">
      <details>
        <summary>
          <span>Receipt</span>
          <span class="tabular">{{ money(r.total) }}</span>
        </summary>

        <p class="issued subtle">Order {{ r.reference }} · {{ issued() }}</p>

        <table>
          <tbody>
            @for (line of r.lines; track $index) {
              <tr>
                <th scope="row">{{ line.quantity }} × {{ line.name }}</th>
                <td>{{ money(line.amount) }}</td>
              </tr>
            }
            @if (r.discount.amount > 0) {
              <tr>
                <th scope="row">Discount</th>
                <td>−{{ money(r.discount) }}</td>
              </tr>
            }
            @for (tax of ticketTaxes(); track $index) {
              <tr>
                <th scope="row">{{ taxLabel(tax) }}</th>
                <td>{{ money(tax.amount) }}</td>
              </tr>
            }
            @if (r.service_charge.amount > 0) {
              <tr>
                <th scope="row">Service charge</th>
                <td>{{ money(r.service_charge) }}</td>
              </tr>
            }
            @for (tax of chargeTaxes(); track $index) {
              <tr>
                <th scope="row">{{ taxLabel(tax) }} on the service charge</th>
                <td>{{ money(tax.amount) }}</td>
              </tr>
            }
          </tbody>
          <tfoot>
            <tr class="total">
              <th scope="row">Total</th>
              <td>{{ money(r.total) }}</td>
            </tr>
            @if (r.refunded.amount > 0) {
              <tr class="subtle">
                <th scope="row">Refunded since</th>
                <td>−{{ money(r.refunded) }}</td>
              </tr>
            }
          </tfoot>
        </table>

        <div class="parties subtle">
          @if (r.seller_of_record === 'platform') {
            <p>Sold by <strong>{{ r.seller.name }}</strong>{{ r.organizer ? ' on behalf of ' + r.organizer : '' }}.</p>
          } @else {
            <p>Tickets sold by <strong>{{ r.seller.name }}</strong>.</p>
          }
          @if (details(r.seller); as line) {
            <p class="small">{{ line }}</p>
          }
          @if (r.service; as service) {
            <p>Service charge by <strong>{{ service.name }}</strong>.</p>
            @if (details(service); as line) {
              <p class="small">{{ line }}</p>
            }
          }
        </div>
      </details>
    </mf-card>
  `,
  styles: `
    .receipt {
      margin-top: var(--space-4);
    }

    summary {
      display: flex;
      justify-content: space-between;
      gap: var(--space-3);
      font-weight: var(--font-weight-semibold);
      cursor: pointer;
    }

    .issued {
      margin: var(--space-3) 0 0;
      font-size: var(--font-size-sm);
    }

    table {
      width: 100%;
      margin-top: var(--space-3);
      border-collapse: collapse;
      font-size: var(--font-size-sm);
      font-variant-numeric: tabular-nums;
    }

    th {
      padding: var(--space-1) var(--space-3) var(--space-1) 0;
      font-weight: normal;
      text-align: left;
    }

    td {
      padding: var(--space-1) 0;
      text-align: right;
      white-space: nowrap;
    }

    .total th,
    .total td {
      padding-top: var(--space-2);
      border-top: 1px solid var(--border-subtle);
      font-weight: var(--font-weight-semibold);
    }

    .parties {
      display: grid;
      gap: var(--space-2);
      margin-top: var(--space-4);
      font-size: var(--font-size-sm);
    }

    .parties p {
      margin: 0;
    }

    .parties strong {
      color: var(--text);
    }

    .small {
      font-size: var(--font-size-xs);
    }
  `,
})
export class MfReceipt {
  readonly receipt = input.required<Receipt>();
  /** The event's zone, so the date is the one the order was placed on there. */
  readonly timezone = input.required<string>();

  readonly ticketTaxes = computed(() => this.receipt().taxes.filter((t) => t.on === 'tickets'));
  readonly chargeTaxes = computed(() => this.receipt().taxes.filter((t) => t.on === 'service_charge'));

  readonly issued = computed(() =>
    new Intl.DateTimeFormat(undefined, { dateStyle: 'long', timeZone: this.timezone() }).format(
      new Date(this.receipt().issued_at),
    ),
  );

  money(value: Money): string {
    return formatMoney(value);
  }

  /** "GST 5%", "QST 9.975%", "VAT 7.5%, included". */
  taxLabel(tax: ReceiptTax): string {
    return `${tax.name} ${tax.rate}%${tax.included ? ', included' : ''}`;
  }

  /** Where they are and the numbers their taxes are filed under, on one line. */
  details(party: ReceiptParty): string | null {
    const parts = [party.address, ...party.registrations.map((r) => `${r.label} ${r.number}`)].filter(
      (part): part is string => !!part,
    );

    return parts.length > 0 ? parts.join(' · ') : null;
  }
}
