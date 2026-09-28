import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { type Availability, MfAvailability } from './availability';

/**
 * The words on the badge, and when there are none.
 *
 * The same rule the site uses (packages/shared/availability); these pin what
 * the phone draws from it, so a change to the words is a change on purpose.
 */
describe('Availability badge', () => {
  function draw(value: Availability | null, exact = false): HTMLElement {
    const fixture = TestBed.createComponent(MfAvailability);
    fixture.componentRef.setInput('value', value);
    fixture.componentRef.setInput('exact', exact);
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it('says nothing about a night with plenty left, or none to count', () => {
    expect(draw({ state: 'available', left: null }).textContent?.trim()).toBe('');
    expect(draw({ state: 'unlimited', left: null }).textContent?.trim()).toBe('');
    expect(draw(null).textContent?.trim()).toBe('');
  });

  it('says a card is almost sold out without a number, whatever the API named', () => {
    const badge = draw({ state: 'almost_sold_out', left: 4 });

    expect(badge.textContent?.trim()).toBe('Almost sold out');
    expect(badge.querySelector('[data-tone]')?.getAttribute('data-tone')).toBe('scarce');
  });

  it('names the count beside a ticket, once the API has named it', () => {
    expect(draw({ state: 'almost_sold_out', left: 4 }, true).textContent?.trim()).toBe('Only 4 left');
    // Above the admin's setting the API sends no number, and none is made up.
    expect(draw({ state: 'almost_sold_out', left: null }, true).textContent?.trim()).toBe('Almost sold out');
  });

  it('says sold out in the sold tone, and never a count', () => {
    const badge = draw({ state: 'sold_out', left: null }, true);

    expect(badge.textContent?.trim()).toBe('Sold out');
    expect(badge.querySelector('[data-tone]')?.getAttribute('data-tone')).toBe('sold');
  });

  it('says sales closed, never sold out, when sales stopped with places left', () => {
    const badge = draw({ state: 'closed', left: null }, true);

    expect(badge.textContent?.trim()).toBe('Sales closed');
    expect(badge.querySelector('[data-tone]')?.getAttribute('data-tone')).toBe('sold');
  });
});
