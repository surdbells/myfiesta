import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { UiPagination } from '@myfiesta/ui';

/** "1 of 1 order", not "1–1 of 1 orders". */
describe('UiPagination', () => {
  function range(page: number, perPage: number, total: number, noun = 'orders', singular: string | null = null): string {
    const fixture = TestBed.createComponent(UiPagination);
    fixture.componentRef.setInput('page', page);
    fixture.componentRef.setInput('perPage', perPage);
    fixture.componentRef.setInput('total', total);
    fixture.componentRef.setInput('noun', noun);
    fixture.componentRef.setInput('singular', singular);
    fixture.detectChanges();

    return ((fixture.nativeElement as HTMLElement).querySelector('.pager__range')?.textContent ?? '').replace(/\s+/g, ' ').trim();
  }

  it('says one of one in the singular', () => {
    expect(range(1, 25, 1)).toBe('1 of 1 order');
  });

  it('keeps the range and the plural for more', () => {
    expect(range(1, 25, 312)).toBe('1–25 of 312 orders');
    expect(range(13, 25, 312)).toBe('301–312 of 312 orders');
  });

  it('says a lone last row once', () => {
    expect(range(2, 25, 26)).toBe('26 of 26 orders');
  });

  it('takes a singular it is given', () => {
    expect(range(1, 25, 1, 'people', 'person')).toBe('1 of 1 person');
  });
});
