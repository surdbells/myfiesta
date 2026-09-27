import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { of } from 'rxjs';
import { Api } from '../../core/api';
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
