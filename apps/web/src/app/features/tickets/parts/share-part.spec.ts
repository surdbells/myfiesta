import { TestBed } from '@angular/core/testing';
import { TicketAccess } from '../../../core/api.types';
import { SharePart } from './share-part';

/**
 * Placed on the tickets page before the friend-discount feature fills it, so
 * it must draw nothing and add no box there until it does.
 */
describe('SharePart, before it is filled', () => {
  it('draws nothing, and adds no box to the page', () => {
    const fixture = TestBed.createComponent(SharePart);
    fixture.componentRef.setInput('order', { reference: 'MF-7Q2K', tickets: [] } as unknown as TicketAccess);
    fixture.detectChanges();

    const host = fixture.nativeElement as HTMLElement;
    expect(host.textContent?.trim()).toBe('');
    expect(host.children.length).toBe(0);
    expect(host.classList).toContain('contents');
  });
});
