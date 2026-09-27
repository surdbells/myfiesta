import { TestBed } from '@angular/core/testing';
import { Money, Receipt } from '../../core/api.types';
import { ReceiptSection } from './receipt';

const cad = (amount: number): Money => ({ amount, currency: 'CAD' });
const ngn = (amount: number): Money => ({ amount, currency: 'NGN' });

/** A Montréal order with QST collected and the service charge taxed. */
const QUEBEC: Receipt = {
  reference: 'CGR9WGDQ',
  issued_at: '2026-09-27T03:30:00+00:00',
  currency: 'CAD',
  seller_of_record: 'organizer',
  seller: { name: 'Lagos Nights', address: null, registrations: [] },
  service: {
    name: 'Fiesta Tickets Inc.',
    address: '1 Front St W, Toronto ON',
    registrations: [
      { label: 'GST/HST', number: '123456789 RT0001' },
      { label: 'QST', number: '1234567890 TQ0001' },
    ],
  },
  organizer: null,
  lines: [{ name: 'General', quantity: 2, unit_price: cad(10000), discount: cad(0), amount: cad(20000) }],
  subtotal: cad(20000),
  discount: cad(0),
  taxes: [
    { name: 'GST', rate: '5', on: 'tickets', included: false, amount: cad(1000) },
    { name: 'QST', rate: '9.975', on: 'tickets', included: false, amount: cad(1995) },
    { name: 'GST', rate: '5', on: 'service_charge', included: false, amount: cad(80) },
    { name: 'QST', rate: '9.975', on: 'service_charge', included: false, amount: cad(160) },
  ],
  service_charge: cad(1600),
  total: cad(24835),
  tax_included: false,
  refunded: cad(0),
};

/** A Lagos order sold by the platform, VAT inside every price. */
const LAGOS: Receipt = {
  ...QUEBEC,
  currency: 'NGN',
  seller_of_record: 'platform',
  seller: { name: 'Fiesta Tickets Ltd.', address: null, registrations: [{ label: 'VAT (TIN)', number: '12345678-0001' }] },
  service: null,
  organizer: 'Lagos Nights',
  lines: [{ name: 'General', quantity: 2, unit_price: ngn(107500), discount: ngn(0), amount: ngn(215000) }],
  subtotal: ngn(215000),
  discount: ngn(0),
  taxes: [
    { name: 'VAT', rate: '7.5', on: 'tickets', included: true, amount: ngn(15000) },
    { name: 'VAT', rate: '7.5', on: 'service_charge', included: true, amount: ngn(1116) },
  ],
  service_charge: ngn(16000),
  total: ngn(231000),
  tax_included: true,
  refunded: ngn(0),
};

function render(receipt: Receipt, timezone = 'America/Toronto'): HTMLElement {
  const fixture = TestBed.createComponent(ReceiptSection);
  fixture.componentRef.setInput('receipt', receipt);
  fixture.componentRef.setInput('timezone', timezone);
  fixture.detectChanges();

  return fixture.nativeElement as HTMLElement;
}

function rows(el: HTMLElement): string[] {
  return Array.from(el.querySelectorAll('tr')).map((tr) =>
    Array.from(tr.children)
      .map((cell) => (cell.textContent ?? '').replace(/\s+/g, ' ').trim())
      .join(' '),
  );
}

describe('The receipt under the tickets', () => {
  it('shows each tax on its own line with its rate, and the service charge and its tax apart', () => {
    const el = render(QUEBEC);

    expect(rows(el)).toEqual([
      '2 × General $200.00',
      'GST 5% $10.00',
      'QST 9.975% $19.95',
      'Service charge $16.00',
      'GST 5% on the service charge $0.80',
      'QST 9.975% on the service charge $1.60',
      'Total $248.35',
    ]);
  });

  it('names who sold the tickets, who sold the service, and the numbers they file under', () => {
    const text = (render(QUEBEC).textContent ?? '').replace(/\s+/g, ' ');

    expect(text).toContain('Tickets sold by Lagos Nights.');
    expect(text).toContain('Service charge by Fiesta Tickets Inc.');
    expect(text).toContain('1 Front St W, Toronto ON · GST/HST 123456789 RT0001 · QST 1234567890 TQ0001');
  });

  it('gives the date the order was placed where the event is', () => {
    // 03:30 UTC on the 27th is still the 26th in Toronto.
    expect(render(QUEBEC).querySelector('.issued')?.textContent).toContain('September 26, 2026');
  });

  it('marks tax inside the price as included, and names the platform as seller for the organizer', () => {
    const el = render(LAGOS, 'Africa/Lagos');
    const text = (el.textContent ?? '').replace(/\s+/g, ' ');

    expect(rows(el)).toContain('VAT 7.5%, included ₦150');
    expect(rows(el)).toContain('VAT 7.5%, included on the service charge ₦11.16');
    expect(text).toContain('Sold by Fiesta Tickets Ltd. on behalf of Lagos Nights.');
    expect(text).toContain('VAT (TIN) 12345678-0001');
    expect(text).not.toContain('Service charge by');
  });

  it('says what has been given back since', () => {
    const el = render({ ...QUEBEC, refunded: cad(12100) });

    expect(rows(el)).toContain('Refunded since −$121.00');
  });
});
