import { TestBed } from '@angular/core/testing';
import { EventDetail } from '../../core/api.types';
import { NotifyOnSalePart } from './notify-on-sale-part';

/**
 * Placed on the event page and the ticket page before the waitlist feature
 * fills it, so it must draw nothing and add no box in either until it does.
 */
describe('NotifyOnSalePart, before it is filled', () => {
  for (const place of ['event', 'tickets'] as const) {
    it(`draws nothing, and adds no box, on the ${place} page`, () => {
      const fixture = TestBed.createComponent(NotifyOnSalePart);
      fixture.componentRef.setInput('event', { slug: 'afro', notify_on_sale: true } as unknown as EventDetail);
      fixture.componentRef.setInput('place', place);
      fixture.detectChanges();

      const host = fixture.nativeElement as HTMLElement;
      expect(host.textContent?.trim()).toBe('');
      expect(host.children.length).toBe(0);
      expect(host.classList).toContain('contents');
    });
  }
});
