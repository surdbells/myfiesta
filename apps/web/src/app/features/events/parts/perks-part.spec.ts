import { TestBed } from '@angular/core/testing';
import { EventDetail } from '../../../core/api.types';
import { PerksPart } from './perks-part';

/**
 * Placed on the event page before the points feature fills it, so it must
 * draw nothing and add no box there until it does.
 */
describe('PerksPart, before it is filled', () => {
  it('draws nothing, and adds no box to the column', () => {
    const fixture = TestBed.createComponent(PerksPart);
    fixture.componentRef.setInput('event', { slug: 'afro', perks: [] } as unknown as EventDetail);
    fixture.detectChanges();

    const host = fixture.nativeElement as HTMLElement;
    expect(host.textContent?.trim()).toBe('');
    expect(host.children.length).toBe(0);
    expect(host.classList).toContain('contents');
  });
});
