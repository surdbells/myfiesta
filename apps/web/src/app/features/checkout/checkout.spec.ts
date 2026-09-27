import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Component, DOCUMENT, RESPONSE_INIT } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Meta } from '@angular/platform-browser';
import { Router, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { of } from 'rxjs';
import { Api, NewOrder } from '../../core/api';
import { API_BASE_URL } from '../../core/api-base';
import { EventDetail, Money, Quote } from '../../core/api.types';
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
  code_applied: null,
  access_code_applied: null,
  code_applies_to: null,
  requires_payment: true,
};

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
