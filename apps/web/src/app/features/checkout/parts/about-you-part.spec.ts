import { TestBed } from '@angular/core/testing';
import { EventDetail } from '../../../core/api.types';
import { AboutYouPart } from './about-you-part';

/**
 * Placed in checkout before the audience feature fills it, so it must draw
 * nothing, and give checkout nothing to send, until it does.
 */
describe('AboutYouPart, before it is filled', () => {
  it('draws nothing, and has nothing to send with the order', () => {
    const fixture = TestBed.createComponent(AboutYouPart);
    fixture.componentRef.setInput('event', { slug: 'afro' } as unknown as EventDetail);
    fixture.detectChanges();

    const host = fixture.nativeElement as HTMLElement;
    expect(host.textContent?.trim()).toBe('');
    expect(host.children.length).toBe(0);
    expect(host.classList).toContain('contents');
    expect(fixture.componentInstance.answers()).toBeNull();
  });
});
