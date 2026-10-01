import { TestBed } from '@angular/core/testing';
import { HeldTicket } from '../../../core/api.types';
import { TransferPart } from './transfer-part';

/**
 * Placed on each ticket before the transfer feature fills it, so it must
 * draw nothing, and leave the page's own links free, until it does.
 */
describe('TransferPart, before it is filled', () => {
  it('draws nothing, and claims no request on the page', () => {
    const fixture = TestBed.createComponent(TransferPart);
    fixture.componentRef.setInput('token', 'tok-1');
    fixture.componentRef.setInput('ticket', { id: 't-1', transferable: null } as unknown as HeldTicket);
    fixture.detectChanges();

    const host = fixture.nativeElement as HTMLElement;
    expect(host.textContent?.trim()).toBe('');
    expect(host.children.length).toBe(0);
    expect(host.classList).toContain('contents');
    expect(fixture.componentInstance.working()).toBeNull();
  });
});
