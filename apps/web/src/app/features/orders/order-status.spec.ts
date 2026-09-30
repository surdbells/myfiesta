import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { API_BASE_URL } from '../../core/api-base';
import { OrderStatus as OrderStatusModel } from '../../core/api.types';
import { OrderStatus } from './order-status';

/**
 * The page a buyer lands on once the order is placed.
 *
 * A free night's order read "Payment ✓" in the steps and "Paid $0.00" in the
 * summary: a payment that never happened, confirmed.
 */
describe('OrderStatus', () => {
  let http: HttpTestingController;

  const ORDER: OrderStatusModel = {
    reference: 'MF-FREE01',
    status: 'paid',
    total: { amount: 0, currency: 'CAD' },
    ticket_count: 1,
    event: {
      slug: 'afro',
      title: 'Afro Night',
      starts_at: '2026-10-03T22:00:00Z',
      timezone: 'America/Toronto',
      calendar: { ics_url: 'https://api.myfiesta.test/afro.ics', google_url: 'https://calendar.google.com/x' },
    },
  };

  async function open(order: OrderStatusModel): Promise<string> {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(`/orders/${order.reference}`, OrderStatus);

    http.expectOne(`https://api.myfiesta.test/api/orders/${order.reference}`).flush(order);
    harness.detectChanges();

    return (harness.routeNativeElement?.textContent ?? '').replace(/\s+/g, ' ');
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'orders/:reference', component: OrderStatus }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('says a free order was free, and never that it was paid', async () => {
    const text = await open(ORDER);

    expect(text).toContain('Your details');
    expect(text).toMatch(/Price\s*Free/);
    expect(text).not.toContain('Payment');
    expect(text).not.toContain('Paid');
    expect(text).not.toContain('$0.00');
  });

  it('keeps the payment and its amount on an order that cost something', async () => {
    const text = await open({ ...ORDER, reference: 'MF-PAID01', total: { amount: 5650, currency: 'CAD' } });

    expect(text).toContain('Payment');
    expect(text).toMatch(/Paid\s*\$56\.50/);
  });
});
