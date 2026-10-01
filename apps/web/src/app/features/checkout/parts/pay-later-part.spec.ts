import { TestBed } from '@angular/core/testing';
import { EventDetail, Quote } from '../../../core/api.types';
import { PayLaterPart } from './pay-later-part';

/**
 * Placed in checkout before the pay-later feature fills it, so it must draw
 * nothing and add no box there until it does.
 */
describe('PayLaterPart, before it is filled', () => {
  it('draws nothing, and adds no box to the form', () => {
    const fixture = TestBed.createComponent(PayLaterPart);
    fixture.componentRef.setInput('event', { slug: 'afro', pay_later: null } as unknown as EventDetail);
    fixture.componentRef.setInput('quote', { requires_payment: true } as unknown as Quote);
    fixture.detectChanges();

    const host = fixture.nativeElement as HTMLElement;
    expect(host.textContent?.trim()).toBe('');
    expect(host.children.length).toBe(0);
    expect(host.classList).toContain('contents');
  });
});
