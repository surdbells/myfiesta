import { TestBed } from '@angular/core/testing';
import type { Receipt } from '@myfiesta/api-types';
import { formatMoney } from '../../core/money';
import { MfReceipt } from './receipt';

const cad = (amount: number) => ({ amount, currency: 'CAD' as const });

/** Sold by the platform in Montréal, QST collected, the service charge taxed. */
const RECEIPT: Receipt = {
  reference: 'CGR9WGDQ',
  issued_at: '2026-09-27T15:00:00+00:00',
  currency: 'CAD',
  seller_of_record: 'platform',
  seller: {
    name: 'Fiesta Tickets Inc.',
    address: null,
    registrations: [
      { label: 'GST/HST', number: '123456789 RT0001' },
      { label: 'QST', number: '1234567890 TQ0001' },
    ],
  },
  service: null,
  organizer: 'Lagos Nights',
  lines: [{ name: 'General', quantity: 1, unit_price: cad(10000), discount: cad(0), amount: cad(10000) }],
  subtotal: cad(10000),
  discount: cad(0),
  taxes: [
    { name: 'GST', rate: '5', on: 'tickets', included: false, amount: cad(500) },
    { name: 'QST', rate: '9.975', on: 'tickets', included: false, amount: cad(998) },
    { name: 'GST', rate: '5', on: 'service_charge', included: false, amount: cad(40) },
    { name: 'QST', rate: '9.975', on: 'service_charge', included: false, amount: cad(80) },
  ],
  service_charge: cad(800),
  total: cad(12418),
  tax_included: false,
  refunded: cad(0),
};

function render(receipt: Receipt): HTMLElement {
  const fixture = TestBed.createComponent(MfReceipt);
  fixture.componentRef.setInput('receipt', receipt);
  fixture.componentRef.setInput('timezone', 'America/Toronto');
  fixture.detectChanges();

  return fixture.nativeElement as HTMLElement;
}

function rows(el: HTMLElement): string[] {
  return Array.from(el.querySelectorAll('tr')).map((tr) =>
    Array.from(tr.children)
      .map((cell) => (cell.textContent ?? '').replace(/\s+/g, ' ').trim())
      .join(' | '),
  );
}

describe('The receipt on a ticket', () => {
  it('lists each tax with its rate, and the service charge with its own', () => {
    expect(rows(render(RECEIPT))).toEqual([
      `1 × General | ${formatMoney(cad(10000))}`,
      `GST 5% | ${formatMoney(cad(500))}`,
      `QST 9.975% | ${formatMoney(cad(998))}`,
      `Service charge | ${formatMoney(cad(800))}`,
      `GST 5% on the service charge | ${formatMoney(cad(40))}`,
      `QST 9.975% on the service charge | ${formatMoney(cad(80))}`,
      `Total | ${formatMoney(cad(12418))}`,
    ]);
  });

  it('names the platform as seller for the organizer, with its numbers', () => {
    const text = (render(RECEIPT).textContent ?? '').replace(/\s+/g, ' ');

    expect(text).toContain('Sold by Fiesta Tickets Inc. on behalf of Lagos Nights.');
    expect(text).toContain('GST/HST 123456789 RT0001 · QST 1234567890 TQ0001');
    expect(text).toContain('Order CGR9WGDQ');
  });

  it('with the organizer as seller, names who sold the service separately', () => {
    const text = (
      render({
        ...RECEIPT,
        seller_of_record: 'organizer',
        seller: { name: 'Lagos Nights', address: null, registrations: [] },
        service: { name: 'Fiesta Tickets Inc.', address: null, registrations: [] },
        organizer: null,
      }).textContent ?? ''
    ).replace(/\s+/g, ' ');

    expect(text).toContain('Tickets sold by Lagos Nights.');
    expect(text).toContain('Service charge by Fiesta Tickets Inc.');
  });
});
