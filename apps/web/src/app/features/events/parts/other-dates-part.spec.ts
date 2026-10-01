import { TestBed } from '@angular/core/testing';
import { EventDetail } from '../../../core/api.types';
import { OtherDatesPart } from './other-dates-part';

/**
 * Placed on the event page before the scheduling feature fills it, so it
 * must draw nothing and add no box there until it does.
 */
describe('OtherDatesPart, before it is filled', () => {
  it('draws nothing, and adds no box to the rail', () => {
    const fixture = TestBed.createComponent(OtherDatesPart);
    fixture.componentRef.setInput('event', { slug: 'afro', other_dates: null } as unknown as EventDetail);
    fixture.detectChanges();

    const host = fixture.nativeElement as HTMLElement;
    expect(host.textContent?.trim()).toBe('');
    expect(host.children.length).toBe(0);
    expect(host.classList).toContain('contents');
  });
});
