import { TestBed } from '@angular/core/testing';
import { EventDetail, Quote } from '../../../core/api.types';
import { FriendDiscountPart } from './friend-discount-part';

/**
 * The "Friend's discount" line in checkout's summary: the quote's figures,
 * and nothing at all when no friend's link priced the basket.
 */
describe('FriendDiscountPart', () => {
  function render(quote: Partial<Quote>): HTMLElement {
    const fixture = TestBed.createComponent(FriendDiscountPart);
    fixture.componentRef.setInput('event', { slug: 'afro', share_offer: { discount_bps: 1500 } } as unknown as EventDetail);
    fixture.componentRef.setInput('quote', { code_applied: null, friend_discount: null, ...quote } as unknown as Quote);
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it('draws nothing, and adds no box to the summary, without a friend’s discount', () => {
    const host = render({ code_applied: 'SAVE5' });

    expect(host.textContent?.trim()).toBe('');
    expect(host.children.length).toBe(0);
    expect(host.classList).toContain('contents');
  });

  it('names the friend’s discount and what it took off', () => {
    const host = render({ friend_discount: { discount_bps: 1500, amount: { amount: 3000, currency: 'CAD' } } });
    const line = host.querySelector('.friend-discount')?.textContent?.replace(/\s+/g, ' ') ?? '';

    expect(line).toContain('Friend’s discount, 15% off');
    expect(line).toContain('−$30.00');
    expect(line).toContain('A code you type instead replaces it.');
  });
});
