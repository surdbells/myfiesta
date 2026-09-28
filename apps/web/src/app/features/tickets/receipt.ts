import { Component, computed, input } from '@angular/core';
import { Money, Receipt, ReceiptParty, ReceiptTax } from '../../core/api.types';
import { formatMoney } from '../../core/money';

/**
 * What was paid, to whom, and each tax on it.
 *
 * Under the tickets and folded shut, because at a door nobody wants to scroll
 * past it — but it is the same receipt the email carries, line for line, for
 * the buyer who needs it for an expense claim or an accountant. Everything on
 * it is the server's: the rates, the names and the registration numbers are
 * the ones the order was charged under, not whatever they are today.
 *
 * No ticket codes. The QR above is the ticket; a receipt gets forwarded.
 */
@Component({
  selector: 'app-receipt',
  template: `
    @let r = receipt();
    <details class="receipt mt-8 rounded-(--radius-card) border border-border-subtle bg-surface-raised p-5 text-sm shadow-(--shadow-card)">
      <summary class="flex cursor-pointer items-center justify-between gap-3 font-semibold">
        <span>Receipt</span>
        <span class="tabular-nums">{{ money(r.total) }}</span>
      </summary>

      <p class="issued mt-3 text-text-muted">Order {{ r.reference }} · {{ issued() }}</p>

      <table class="mt-3 w-full border-collapse tabular-nums">
        <tbody>
          @for (line of r.lines; track $index) {
            <tr>
              <th scope="row" class="py-1 pr-3 text-left font-normal">{{ line.quantity }} × {{ line.name }}</th>
              <td class="py-1 text-right">{{ money(line.amount) }}</td>
            </tr>
          }
          @if (r.discount.amount > 0) {
            <tr>
              <th scope="row" class="py-1 pr-3 text-left font-normal">Discount</th>
              <td class="py-1 text-right">−{{ money(r.discount) }}</td>
            </tr>
          }
          @for (tax of ticketTaxes(); track $index) {
            <tr class="tax">
              <th scope="row" class="py-1 pr-3 text-left font-normal">{{ taxLabel(tax) }}</th>
              <td class="py-1 text-right">{{ money(tax.amount) }}</td>
            </tr>
          }
          @if (r.service_charge.amount > 0) {
            <tr>
              <th scope="row" class="py-1 pr-3 text-left font-normal">Service charge</th>
              <td class="py-1 text-right">{{ money(r.service_charge) }}</td>
            </tr>
          }
          @for (tax of chargeTaxes(); track $index) {
            <tr class="tax">
              <th scope="row" class="py-1 pr-3 text-left font-normal">{{ taxLabel(tax) }} on the service charge</th>
              <td class="py-1 text-right">{{ money(tax.amount) }}</td>
            </tr>
          }
        </tbody>
        <tfoot>
          <tr class="border-t border-border">
            <th scope="row" class="pt-2 pr-3 text-left font-semibold">Total</th>
            <td class="total pt-2 text-right font-semibold">{{ money(r.total) }}</td>
          </tr>
          @if (r.refunded.amount > 0) {
            <tr>
              <th scope="row" class="py-1 pr-3 text-left font-normal text-text-muted">Refunded since</th>
              <td class="py-1 text-right text-text-muted">−{{ money(r.refunded) }}</td>
            </tr>
          }
        </tfoot>
      </table>

      <div class="parties mt-4 grid gap-2 text-text-muted">
        @if (r.seller_of_record === 'platform') {
          <p class="m-0">
            Sold by <strong class="text-text">{{ r.seller.name }}</strong>{{ r.organizer ? ' on behalf of ' + r.organizer : '' }}.
          </p>
        } @else {
          <p class="m-0">Tickets sold by <strong class="text-text">{{ r.seller.name }}</strong>.</p>
        }
        @if (details(r.seller); as line) {
          <p class="m-0 text-xs">{{ line }}</p>
        }
        @if (r.service; as service) {
          <p class="m-0">Service charge by <strong class="text-text">{{ service.name }}</strong>.</p>
          @if (details(service); as line) {
            <p class="m-0 text-xs">{{ line }}</p>
          }
        }
      </div>
    </details>
  `,
})
export class ReceiptSection {
  readonly receipt = input.required<Receipt>();
  /** The event's zone, so the date is the one the order was placed on there. */
  readonly timezone = input.required<string>();

  readonly ticketTaxes = computed(() => this.receipt().taxes.filter((t) => t.on === 'tickets'));
  readonly chargeTaxes = computed(() => this.receipt().taxes.filter((t) => t.on === 'service_charge'));

  /** A fixed locale, like every date and price on this site, so server and browser agree. */
  readonly issued = computed(() =>
    new Intl.DateTimeFormat('en-CA', { dateStyle: 'long', timeZone: this.timezone() }).format(
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
