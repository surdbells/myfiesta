import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { UiBulkBar } from '@myfiesta/ui';

/**
 * The count over the ticked rows, said as a count.
 *
 * It read "1 guests selected": the noun was the plural whatever the number.
 */
describe('UiBulkBar', () => {
  function said(count: number, noun: string, one?: string): string {
    const fixture = TestBed.createComponent(UiBulkBar);
    fixture.componentRef.setInput('count', count);
    fixture.componentRef.setInput('noun', noun);
    if (one) fixture.componentRef.setInput('one', one);
    fixture.detectChanges();

    const bar = (fixture.nativeElement as HTMLElement).querySelector('.bulk')!;

    expect(bar.getAttribute('aria-label')).toBe(bar.querySelector('.bulk__count')!.textContent!.replace(/\s+/g, ' ').trim());

    return bar.getAttribute('aria-label')!;
  }

  it('says one of a thing in the singular', () => {
    expect(said(1, 'guests')).toBe('1 guest selected');
    expect(said(1, 'orders')).toBe('1 order selected');
    expect(said(1, 'codes')).toBe('1 code selected');
  });

  it('says several in the plural', () => {
    expect(said(2, 'guests')).toBe('2 guests selected');
  });

  it('takes the singular it is given for a noun that is not a plural in "s"', () => {
    expect(said(1, 'people', 'person')).toBe('1 person selected');
    expect(said(3, 'people', 'person')).toBe('3 people selected');
  });

  it('is not there when nothing is ticked', () => {
    const fixture = TestBed.createComponent(UiBulkBar);
    fixture.componentRef.setInput('count', 0);
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).querySelector('.bulk')).toBeNull();
  });
});
