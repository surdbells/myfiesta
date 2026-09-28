import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Component, DOCUMENT, RESPONSE_INIT } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Meta } from '@angular/platform-browser';
import { Router, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { of, throwError } from 'rxjs';
import { Api, NewOrder } from '../../core/api';
import { API_BASE_URL } from '../../core/api-base';
import { EventDetail, Money, Quote } from '../../core/api.types';
import { CheckoutStore } from '../../core/checkout-store';
import { Checkout } from './checkout';

@Component({ template: '' })
class Elsewhere {}

const cad = (amount: number): Money => ({ amount, currency: 'CAD' });

const EVENT = {
  slug: 'afro',
  title: 'Afro Night',
  currency: 'CAD',
  poster_url: null,
  organizer: { name: 'Lagos Nights' },
  questions: [],
} as unknown as EventDetail;

const QUOTE: Quote = {
  lines: [
    {
      kind: 'ticket',
      ticket_type_id: 'general',
      add_on_id: null,
      name: 'General',
      quantity: 1,
      unit_price: cad(2500),
      line_total: cad(2500),
      discount: cad(0),
    },
  ],
  subtotal: cad(2500),
  discount: cad(0),
  tax: cad(0),
  service_charge: cad(200),
  net_revenue: cad(2500),
  total: cad(2700),
  tax_inclusive: false,
  tax_label: null,
  tax_lines: [],
  service_charge_tax: cad(0),
  code_applied: null,
  access_code_applied: null,
  code_applies_to: null,
  requires_payment: true,
};

/**
 * The bill before payment, with each tax on a line of its own.
 *
 * It used to say "GST + QST" against one figure and fold the service fee's
 * own tax into the fee, unsaid. The receipt that follows the payment shows
 * every tax with its rate; this page now reads the same, line for line, and
 * the lines still add up to the total.
 */
describe('Checkout, the bill', () => {
  const ngn = (amount: number): Money => ({ amount, currency: 'NGN' });

  /** Two 100.00 tickets in Montréal, with QST collected and the service fee taxed. */
  const QUEBEC: Quote = {
    ...QUOTE,
    subtotal: cad(20000),
    tax: cad(2995),
    service_charge: cad(1840),
    net_revenue: cad(20000),
    total: cad(24835),
    tax_label: 'GST + QST',
    tax_lines: [
      { name: 'GST', rate: '5', on: 'tickets', included: false, amount: cad(1000) },
      { name: 'QST', rate: '9.975', on: 'tickets', included: false, amount: cad(1995) },
      { name: 'GST', rate: '5', on: 'service_charge', included: false, amount: cad(80) },
      { name: 'QST', rate: '9.975', on: 'service_charge', included: false, amount: cad(160) },
    ],
    service_charge_tax: cad(240),
  };

  /** Two ₦1,075 tickets in Lagos: VAT inside the prices, and inside the fee. */
  const LAGOS: Quote = {
    ...QUOTE,
    subtotal: ngn(215000),
    tax: ngn(15000),
    service_charge: ngn(16000),
    net_revenue: ngn(200000),
    total: ngn(231000),
    tax_inclusive: true,
    tax_label: 'VAT',
    tax_lines: [
      { name: 'VAT', rate: '7.5', on: 'tickets', included: true, amount: ngn(15000) },
      { name: 'VAT', rate: '7.5', on: 'service_charge', included: true, amount: ngn(1116) },
    ],
    service_charge_tax: ngn(1116),
  };

  async function bill(quote: Quote): Promise<[string, string][]> {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: ':slug/checkout', component: Checkout },
          { path: ':slug/tickets', component: Elsewhere },
        ]),
        { provide: Api, useValue: { event: () => of({ data: EVENT }), quote: () => of(quote) } },
      ],
    });

    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/afro/checkout', Checkout);
    harness.detectChanges();

    const list = harness.routeNativeElement!.querySelector('dl')!;
    const terms = Array.from(list.querySelectorAll('dt')).map((dt) => dt.textContent!.trim());
    const figures = Array.from(list.querySelectorAll('dd')).map((dd) => dd.textContent!.trim());

    return terms.map((term, i) => [term, figures[i]]);
  }

  beforeEach(() =>
    sessionStorage.setItem('myfiesta.basket.afro', JSON.stringify({ items: { general: 2 }, addOns: {}, code: '' })),
  );

  afterEach(() => sessionStorage.clear());

  it('shows GST and QST apart, and the tax on the service fee beneath the fee', async () => {
    expect(await bill(QUEBEC)).toEqual([
      ['Subtotal', '$200.00'],
      ['GST 5%', '$10.00'],
      ['QST 9.975%', '$19.95'],
      ['Service fee', '$16.00'],
      ['GST 5% on the service fee', '$0.80'],
      ['QST 9.975% on the service fee', '$1.60'],
      ['Total', '$248.35'],
    ]);
  });

  it('keeps VAT inside the prices and the fee, and says so', async () => {
    expect(await bill(LAGOS)).toEqual([
      ['Subtotal', '₦2,150'],
      ['VAT 7.5%, included', '₦150'],
      ['Service fee', '₦160'],
      ['VAT 7.5% on the service fee, included', '₦11.16'],
      ['Total', '₦2,310'],
    ]);
  });

  it('still shows one tax line for a quote that did not itemise its taxes', async () => {
    expect(await bill({ ...QUEBEC, tax_lines: [], service_charge: cad(1600), service_charge_tax: cad(0), total: cad(24595) })).toEqual([
      ['Subtotal', '$200.00'],
      ['GST + QST', '$29.95'],
      ['Service fee', '$16.00'],
      ['Total', '$245.95'],
    ]);
  });

  /*
   * The server lists a rate whatever it was charged on, so a basket that
   * comes to nothing in Toronto still carries HST against it. The bill never
   * showed a tax of $0.00, and a free basket should not start to.
   */
  it('leaves off a tax that came to nothing on the tickets', async () => {
    expect(
      await bill({
        ...QUOTE,
        subtotal: cad(5000),
        discount: cad(5000),
        tax: cad(0),
        service_charge: cad(0),
        net_revenue: cad(0),
        total: cad(0),
        tax_label: 'HST',
        tax_lines: [{ name: 'HST', rate: '13', on: 'tickets', included: false, amount: cad(0) }],
        requires_payment: false,
      }),
    ).toEqual([
      ['Subtotal', '$50.00'],
      ['Discount', '−$50.00'],
      ['Total', '$0.00'],
    ]);
  });

  it('leaves off a tax that came to nothing on the service fee', async () => {
    // A 25¢ ticket: 13% of the 2¢ fee rounds to nothing.
    expect(
      await bill({
        ...QUOTE,
        subtotal: cad(25),
        tax: cad(3),
        service_charge: cad(2),
        net_revenue: cad(25),
        total: cad(30),
        tax_label: 'HST',
        tax_lines: [
          { name: 'HST', rate: '13', on: 'tickets', included: false, amount: cad(3) },
          { name: 'HST', rate: '13', on: 'service_charge', included: false, amount: cad(0) },
        ],
      }),
    ).toEqual([
      ['Subtotal', '$0.25'],
      ['HST 13%', '$0.03'],
      ['Service fee', '$0.02'],
      ['Total', '$0.30'],
    ]);
  });
});

/**
 * The links on the checkout that lead away from it.
 *
 * Inside a venue's frame, leaving the checkout loses it: the frame is the
 * whole of our page the buyer has, and the basket and the answers typed so far
 * go with it. So those links open a tab of their own there. They used to bind
 * the target attribute, which RouterLink draws over from its own input and
 * never reads: the link had no target, RouterLink took the click and moved the
 * frame to /refunds, and the buyer's checkout was gone.
 */
describe('Checkout, the links away from it', () => {
  async function open(url: string): Promise<RouterTestingHarness> {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(url, Checkout);
    harness.detectChanges();

    return harness;
  }

  function link(harness: RouterTestingHarness, text: string): HTMLAnchorElement {
    const found = Array.from(harness.routeNativeElement!.querySelectorAll('a')).find(
      (a) => a.textContent?.trim() === text,
    );

    expect(found, `a link reading "${text}"`).toBeDefined();

    return found!;
  }

  /**
   * Clicks like a buyer would, and says whether the app took the click over.
   * The browser's own following of the link is stopped afterwards, so the
   * test's document stays where it is.
   */
  function click(harness: RouterTestingHarness, anchor: HTMLAnchorElement): boolean {
    let takenOver = false;
    const outer = harness.routeNativeElement!;
    const note = (event: Event) => {
      takenOver = event.defaultPrevented;
      event.preventDefault();
    };

    outer.addEventListener('click', note);
    anchor.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
    outer.removeEventListener('click', note);

    return takenOver;
  }

  beforeEach(() => {
    sessionStorage.setItem('myfiesta.basket.afro', JSON.stringify({ items: { general: 1 }, addOns: {}, code: '' }));

    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'refunds', component: Elsewhere },
          { path: 'contact', component: Elsewhere },
          { path: 'embed/:slug/checkout', component: Checkout },
          { path: 'embed/:slug', component: Elsewhere },
          { path: ':slug/checkout', component: Checkout },
          { path: ':slug/tickets', component: Elsewhere },
        ]),
        { provide: Api, useValue: { event: () => of({ data: EVENT }), quote: () => of(QUOTE) } },
      ],
    });
  });

  afterEach(() => sessionStorage.clear());

  for (const text of ['How refunds work', 'contact us']) {
    it(`opens "${text}" in a new tab from inside a frame, and leaves the checkout where it was`, async () => {
      const harness = await open('/embed/afro/checkout');
      const anchor = link(harness, text);

      expect(anchor.getAttribute('target')).toBe('_blank');
      expect(click(harness, anchor)).toBe(false);

      await harness.fixture.whenStable();
      expect(TestBed.inject(Router).url).toBe('/embed/afro/checkout');
    });
  }

  it('follows the link in place on the site itself', async () => {
    const harness = await open('/afro/checkout');
    const anchor = link(harness, 'How refunds work');

    expect(anchor.hasAttribute('target')).toBe(false);
    expect(click(harness, anchor)).toBe(true);

    await harness.fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/refunds');
  });
});

/**
 * Buying agrees to the terms, the privacy policy and the refund policy — and
 * only when the buyer ticks the box.
 *
 * The box used to be here and go nowhere: it was never sent, so nothing
 * recorded that anybody had agreed to anything. The server now refuses an
 * order without it, and this page sends what the box actually says.
 */
describe('Checkout, agreeing to the terms', () => {
  let placed: NewOrder[];

  async function open(url = '/afro/checkout') {
    const harness = await RouterTestingHarness.create();
    const page = await harness.navigateByUrl(url, Checkout);
    harness.detectChanges();

    const root = () => harness.routeNativeElement as HTMLElement;

    return {
      harness,
      page,
      box: () => root().querySelector<HTMLInputElement>('input[name="agreed"]')!,
      pay: () => Array.from(root().querySelectorAll('button')).find((b) => b.textContent?.includes('Continue to payment'))!,
      link: (text: string) => Array.from(root().querySelectorAll('a')).find((a) => a.textContent?.trim() === text),
    };
  }

  function fillIn(page: Checkout): void {
    page.first.set('Ada');
    page.email.set('ada@example.com');
    page.confirm.set('ada@example.com');
  }

  beforeEach(() => {
    placed = [];
    sessionStorage.setItem('myfiesta.basket.afro', JSON.stringify({ items: { general: 1 }, addOns: {}, code: '' }));

    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'terms', component: Elsewhere },
          { path: 'privacy', component: Elsewhere },
          { path: 'refunds', component: Elsewhere },
          { path: 'order/:reference', component: Elsewhere },
          { path: 'embed/:slug/checkout', component: Checkout },
          { path: ':slug/checkout', component: Checkout },
          { path: ':slug/tickets', component: Elsewhere },
        ]),
        {
          provide: Api,
          useValue: {
            event: () => of({ data: EVENT }),
            quote: () => of({ ...QUOTE, requires_payment: true }),
            order: (_slug: string, order: NewOrder) => {
              placed.push(order);

              return of({ reference: 'ABCD2345', status: 'paid', payment: null });
            },
          },
        },
      ],
    });
  });

  afterEach(() => sessionStorage.clear());

  it('starts unticked, and the order cannot be placed until it is ticked', async () => {
    const { harness, page, box, pay } = await open();
    fillIn(page);
    harness.detectChanges();
    await harness.fixture.whenStable();

    expect(box().checked).toBe(false);
    expect(page.ready()).toBe(false);
    expect(pay().disabled).toBe(true);

    page.placeOrder();
    expect(placed).toEqual([]);
  });

  for (const url of ['/afro/checkout', '/embed/afro/checkout']) {
    it(`links to the terms, privacy and refund pages in a tab of their own (${url})`, async () => {
      const { harness, link } = await open(url);

      for (const [text, path] of [
        ['terms', '/terms'],
        ['privacy policy', '/privacy'],
        ['refund policy', '/refunds'],
      ]) {
        const anchor = link(text);

        expect(anchor, `a link reading "${text}"`).toBeDefined();
        expect(anchor!.getAttribute('href')).toBe(path);
        // Reading the refund policy must not cost the buyer the form.
        expect(anchor!.getAttribute('target')).toBe('_blank');
      }

      await harness.fixture.whenStable();
      expect(TestBed.inject(Router).url).toBe(url);
    });
  }

  it('sends the agreement with the order once the box is ticked', async () => {
    const { harness, page, box, pay } = await open();
    fillIn(page);
    harness.detectChanges();

    box().click();
    harness.detectChanges();
    await harness.fixture.whenStable();

    expect(page.agreed()).toBe(true);
    expect(pay().disabled).toBe(false);

    page.placeOrder();

    expect(placed).toHaveLength(1);
    expect(placed[0].accept_terms).toBe(true);
  });

  // The words themselves are held to the API's copy of the policy by
  // LegalCopiesTest; this is that they are on the page, by the box.
  it('says the refund policy in one sentence beside the box', async () => {
    const { harness } = await open();
    const summary = (harness.routeNativeElement as HTMLElement).querySelector('.refund-summary');

    expect(summary?.textContent?.trim()).toBe(
      'Refunds are up to the organizer, except that you are owed one if the event is cancelled.',
    );
  });
});

/**
 * A checkout for an event that is not there.
 *
 * It used to sit on "Loading…" for ever and answer 200. It now answers the
 * way the event page does: 404 when the API says there is no such event, and
 * 503 — come back later — when the API did not answer. On the server there is
 * never a basket, which is when it matters most.
 */
describe('Checkout, when there is no event', () => {
  let response: ResponseInit;
  let http: HttpTestingController;

  async function open(url: string): Promise<RouterTestingHarness> {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(url, Checkout);

    return harness;
  }

  beforeEach(() => {
    response = { status: 200 };

    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'embed/:slug/checkout', component: Checkout },
          { path: 'embed/:slug', component: Elsewhere },
          { path: ':slug/checkout', component: Checkout },
          { path: ':slug/tickets', component: Elsewhere },
        ]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
        { provide: RESPONSE_INIT, useValue: response },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
    sessionStorage.clear();
  });

  for (const url of ['/gone/checkout', '/embed/gone/checkout']) {
    it(`answers 404 with nothing chosen, as on the server (${url})`, async () => {
      const harness = await open(url);

      http
        .expectOne('https://api.myfiesta.test/api/events/gone')
        .flush({ message: 'Event not found.' }, { status: 404, statusText: 'Not Found' });
      harness.detectChanges();
      await harness.fixture.whenStable();

      expect(response.status).toBe(404);
      expect(TestBed.inject(Meta).getTag('name="robots"')?.content).toBe('noindex');
      expect(harness.routeNativeElement?.textContent).toContain('Event not found');
      // Not sent on to a ticket page for an event that does not exist.
      expect(TestBed.inject(Router).url).toBe(url);
    });
  }

  it('answers 404 with a basket left over from before the event went', async () => {
    sessionStorage.setItem('myfiesta.basket.gone', JSON.stringify({ items: { general: 1 }, addOns: {}, code: '' }));

    const harness = await open('/gone/checkout');

    http
      .expectOne('https://api.myfiesta.test/api/events/gone')
      .flush({ message: 'Event not found.' }, { status: 404, statusText: 'Not Found' });
    http
      .expectOne('https://api.myfiesta.test/api/events/gone/quote')
      .flush({ message: 'Event not found.' }, { status: 404, statusText: 'Not Found' });
    harness.detectChanges();

    expect(response.status).toBe(404);
    expect(harness.routeNativeElement?.textContent).toContain('Event not found');
    expect(harness.routeNativeElement?.textContent).not.toContain('Loading');
  });

  it('answers 503, not 404, when the API did not answer', async () => {
    const harness = await open('/afrobeats-rooftop/checkout');

    http
      .expectOne('https://api.myfiesta.test/api/events/afrobeats-rooftop')
      .flush({ message: 'Server Error' }, { status: 500, statusText: 'Server Error' });
    harness.detectChanges();

    expect(response.status).toBe(503);
    expect(harness.routeNativeElement?.textContent).toContain('could not be loaded');
    expect(TestBed.inject(DOCUMENT).title).toBe('Event unavailable — myFiesta');
  });

  it('still sends somebody with nothing chosen back to the choosing, for an event that is there', async () => {
    const harness = await open('/afro/checkout');

    http.expectOne('https://api.myfiesta.test/api/events/afro').flush({ data: EVENT });
    await harness.fixture.whenStable();

    expect(TestBed.inject(Router).url).toBe('/afro/tickets');
    expect(response.status).toBe(200);
  });
});

/**
 * Pressing again after the payment page could not be opened.
 *
 * Each press used to place an order of its own, each with its own hold: a
 * buyer retrying through a processor outage held the same places two, three
 * times over. The order the 502 names is sent back with the next press.
 */
describe('Checkout, when the payment page could not be opened', () => {
  let placed: NewOrder[];
  let answers: (() => ReturnType<Api['order']>)[];

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

  const outage = () =>
    throwError(() => ({
      status: 502,
      error: { message: 'We could not reach the payment provider.', reference: 'FAIL2345' },
    }));

  beforeEach(() => {
    placed = [];
    answers = [];
    sessionStorage.setItem('myfiesta.basket.afro', JSON.stringify({ items: { general: 1 }, addOns: {}, code: '' }));

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
            quote: () => of({ ...QUOTE, total: cad(0), requires_payment: false }),
            order: (_slug: string, order: NewOrder) => {
              placed.push(order);

              return (answers.shift() ?? (() => of({ reference: 'PAID2345', status: 'paid', payment: null })))();
            },
          },
        },
      ],
    });
  });

  afterEach(() => sessionStorage.clear());

  it('names the order the failure left behind when it is pressed again', async () => {
    answers = [outage];
    const { harness, page } = await open();

    page.placeOrder();
    harness.detectChanges();
    expect(page.orderError()).toContain('could not reach');

    page.placeOrder();

    expect(placed).toHaveLength(2);
    expect(placed[0].retry_of).toBeUndefined();
    expect(placed[1].retry_of).toBe('FAIL2345');
  });

  it('remembers it across a reload, with the basket', async () => {
    answers = [outage];
    const { page } = await open();
    page.placeOrder();

    // What a reloaded page reads its basket from.
    const reloaded = new CheckoutStore();
    reloaded.loadFor('afro');

    expect(reloaded.unpaid()).toBe('FAIL2345');
    expect(reloaded.lines()).toEqual([{ ticket_type_id: 'general', quantity: 1 }]);
  });

  it('forgets it once an order goes through', async () => {
    const { page } = await open();
    page.placeOrder();

    expect(JSON.parse(sessionStorage.getItem('myfiesta.basket.afro') ?? 'null')).toBeNull();
  });
});

/**
 * Nothing to pay.
 *
 * A $0 checkout still told the buyer they would pay on Stripe's page, which
 * is a step a free order never reaches.
 */
describe('Checkout, for free tickets', () => {
  async function open(quote: Quote) {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: ':slug/checkout', component: Checkout },
          { path: ':slug/tickets', component: Elsewhere },
        ]),
        { provide: Api, useValue: { event: () => of({ data: EVENT }), quote: () => of(quote) } },
      ],
    });

    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/afro/checkout', Checkout);
    harness.detectChanges();

    return harness.routeNativeElement as HTMLElement;
  }

  beforeEach(() =>
    sessionStorage.setItem('myfiesta.basket.afro', JSON.stringify({ items: { general: 1 }, addOns: {}, code: '' })),
  );

  afterEach(() => sessionStorage.clear());

  it('says nothing about a payment page when nothing is charged', async () => {
    const page = await open({ ...QUOTE, total: cad(0), requires_payment: false });

    expect(page.querySelector('h2.payment')).toBeNull();
    expect(page.textContent).not.toContain('secure page');
    expect(page.textContent).toContain('Reserve free tickets');
  });

  it('still explains where the payment happens when there is one', async () => {
    const page = await open(QUOTE);

    expect(page.querySelector('h2.payment')?.textContent?.trim()).toBe('Payment');
    expect(page.textContent).toContain("You pay on Stripe's secure page.");
  });

  it('ends the form with the way to pay, for a phone, where the summary sits above it', async () => {
    const page = await open(QUOTE);
    const form = page.querySelector('form')!;
    const last = form.querySelector('button.place-order');

    expect(last?.textContent).toContain('Continue to payment');
    expect(last?.textContent).toContain('$27.00');
    // After every field, and after the box that has to be ticked.
    expect(form.querySelector('input[name="agreed"]')!.compareDocumentPosition(last!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });
});
