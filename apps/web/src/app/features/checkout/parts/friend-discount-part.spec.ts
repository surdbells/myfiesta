import { TestBed } from '@angular/core/testing';
import { EventDetail, Quote } from '../../../core/api.types';
import { FriendDiscountPart } from './friend-discount-part';

/**
 * Placed in checkout's summary before the friend-discount feature fills it,
 * so it must draw nothing and add no box there until it does.
 */
describe('FriendDiscountPart, before it is filled', () => {
  it('draws nothing, and adds no box to the summary', () => {
    const fixture = TestBed.createComponent(FriendDiscountPart);
    fixture.componentRef.setInput('event', { slug: 'afro', share_offer: null } as unknown as EventDetail);
    fixture.componentRef.setInput('quote', { code_applied: null } as unknown as Quote);
    fixture.detectChanges();

    const host = fixture.nativeElement as HTMLElement;
    expect(host.textContent?.trim()).toBe('');
    expect(host.children.length).toBe(0);
    expect(host.classList).toContain('contents');
  });
});
