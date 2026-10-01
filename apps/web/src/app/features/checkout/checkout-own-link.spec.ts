import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { of, throwError } from 'rxjs';
import { Api, NewOrder } from '../../core/api';
import { EventDetail, Money, Quote } from '../../core/api.types';
import { CheckoutStore } from '../../core/checkout-store';
import { OWN_LINK_REFUSED } from '../share/share';
import { Checkout } from './checkout';

@Component({ template: '' })
class Elsewhere {}

const cad = (amount: number): Money => ({ amount, currency: 'CAD' });

const EVENT = { slug: 'afro', title: 'Afro Night', currency: 'CAD', questions: [] } as unknown as EventDetail;

const FULL: Quote = {
  lines: [],
  subtotal: cad(10000),
  discount: cad(0),
  tax: cad(0),
  service_charge: cad(800),
  net_revenue: cad(10000),
  total: cad(10800),
  tax_inclusive: false,
  tax_label: null,
  tax_lines: [],
  service_charge_tax: cad(0),
  code_applied: null,
  access_code_applied: null,
  code_applies_to: null,
  requires_payment: true,
  pay_later: null,
  friend_discount: null,
};

const WITH_FRIEND: Quote = {
  ...FULL,
  discount: cad(1500),
  net_revenue: cad(8500),
  total: cad(9180),
  friend_discount: { discount_bps: 1500, amount: cad(1500) },
};

/**
 * Somebody opening their own friend's link.
 *
 * The page showed them a friend's discount and every order was refused over
 * it ("That's your own link"), with the link kept in the basket, so a reload
 * or another try was refused the same way. Now the refusal takes the link
 * off, prices the basket again, and the next press goes through at the full
 * price.
 */
describe('Checkout, through your own friend’s link', () => {
  let placed: NewOrder[];
  let quotedWith: (string | undefined)[];

  beforeEach(() => {
    placed = [];
    quotedWith = [];
    sessionStorage.setItem(
      'myfiesta.basket.afro',
      JSON.stringify({ items: { general: 1 }, addOns: {}, code: '', ref: 'fabcdefgh23' }),
    );

    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'order/:reference', component: Elsewhere },
          { path: ':slug/checkout', component: Checkout },
          { path: ':slug/tickets', component: Elsewhere },
        ]),
        {
          provide: Api,
          useValue: {
            event: () => of({ data: EVENT }),
            quote: (_slug: string, request: { ref?: string }) => {
              quotedWith.push(request.ref);

              return of(request.ref ? WITH_FRIEND : FULL);
            },
            order: (_slug: string, order: NewOrder) => {
              placed.push(order);

              return order.ref
                ? throwError(() => ({
                    status: 422,
                    error: { message: 'That’s your own link. Send it to a friend.', reason: 'own_share_link' },
                  }))
                : of({ reference: 'PAID2345', status: 'paid', payment: null });
            },
          },
        },
      ],
    });
  });

  afterEach(() => sessionStorage.clear());

  async function open() {
    const harness = await RouterTestingHarness.create();
    const page = await harness.navigateByUrl('/afro/checkout', Checkout);
    page.first.set('Ada');
    page.email.set('ada@example.com');
    page.confirm.set('ada@example.com');
    page.agreed.set(true);
    harness.detectChanges();

    return { harness, page };
  }

  it('takes the link off, prices it again and says so', async () => {
    const { harness, page } = await open();
    expect(page.quote()?.friend_discount).not.toBeNull();

    page.placeOrder();
    harness.detectChanges();

    expect(page.orderError()).toBe(OWN_LINK_REFUSED);
    expect(page.quote()?.total.amount).toBe(10800);
    expect(page.quote()?.friend_discount).toBeNull();
    expect(quotedWith).toEqual(['fabcdefgh23', undefined]);
    expect(page.placing()).toBe(false);
  });

  it('goes through on the next press, at the full price', async () => {
    const { page } = await open();

    page.placeOrder();
    page.placeOrder();

    expect(placed.map((order) => order.ref)).toEqual(['fabcdefgh23', undefined]);
  });

  it('forgets the link across a reload too', async () => {
    const { page } = await open();
    page.placeOrder();

    const reloaded = new CheckoutStore();
    reloaded.loadFor('afro');

    expect(reloaded.ref()).toBeNull();
    expect(reloaded.lines()).toEqual([{ ticket_type_id: 'general', quantity: 1 }]);
  });

  it('keeps the link for any other refusal', async () => {
    TestBed.overrideProvider(Api, {
      useValue: {
        event: () => of({ data: EVENT }),
        quote: () => of(WITH_FRIEND),
        order: () => throwError(() => ({ status: 422, error: { message: 'General has sold out.' } })),
      },
    });
    const { page } = await open();

    page.placeOrder();

    expect(page.orderError()).toBe('General has sold out.');
    expect(TestBed.inject(CheckoutStore).ref()).toBe('fabcdefgh23');
  });
});
