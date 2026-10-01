import { TestBed } from '@angular/core/testing';
import { EventDetail, Quote } from '../../../core/api.types';
import { PayLaterQuote } from '../../../core/types/pay';
import { PayLaterPart } from './pay-later-part';

function render(payLater: PayLaterQuote | null): HTMLElement {
  const fixture = TestBed.createComponent(PayLaterPart);
  fixture.componentRef.setInput('event', { slug: 'afro', pay_later: { providers: ['klarna', 'affirm'] } } as unknown as EventDetail);
  fixture.componentRef.setInput('quote', { requires_payment: true, pay_later: payLater } as unknown as Quote);
  fixture.detectChanges();

  return fixture.nativeElement as HTMLElement;
}

/**
 * Under what checkout says about the payment page: who this basket can be
 * paid over time with, and where to choose it. Never an instalment amount.
 */
describe('PayLaterPart', () => {
  it('names both lenders when both take the basket, and says where to choose', () => {
    const host = render({ eligible: true, providers: ['klarna', 'affirm'] });

    expect(host.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      'Pay over time with Klarna or Affirm — choose it on the payment page.',
    );
    expect(host.classList).toContain('contents');
  });

  it('names only the lender that takes an order this size', () => {
    const host = render({ eligible: true, providers: ['affirm'] });

    expect(host.textContent).toContain('Pay over time with Affirm');
    expect(host.textContent).not.toContain('Klarna');
  });

  it('never quotes an instalment amount', () => {
    const host = render({ eligible: true, providers: ['klarna', 'affirm'] });

    expect(host.textContent).not.toMatch(/\$|\d/);
  });

  it('draws nothing, and adds no box, when no lender takes the basket', () => {
    const host = render({ eligible: false, providers: [] });

    expect(host.textContent?.trim()).toBe('');
    expect(host.children.length).toBe(0);
  });

  it('draws nothing where the night does not offer paying later', () => {
    const host = render(null);

    expect(host.textContent?.trim()).toBe('');
    expect(host.children.length).toBe(0);
  });
});
