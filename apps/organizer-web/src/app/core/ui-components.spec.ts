import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { UiField, UiPagination, UiSortHeader, type Sort } from '@myfiesta/ui';

/**
 * The component library's contracts, tested where they are invisible.
 *
 * Everything here is something that looks right on screen whether or not it
 * works: an input that is visually beside its label but not associated with
 * it, an error rendered in red that a screen reader never announces, a range
 * that reads "301–325 of 312" only on the last page. None of it is caught by
 * looking, which is why it is caught here.
 */

@Component({
  imports: [UiField],
  template: `
    <ui-field label="Email" [hint]="hint()" [error]="error()" [required]="required()">
      <input type="email" />
    </ui-field>
  `,
})
class FieldHost {
  readonly hint = signal<string | null>('We send tickets here.');
  readonly error = signal<string | null>(null);
  readonly required = signal(false);
}

describe('UiField', () => {
  function mount() {
    const fixture = TestBed.createComponent(FieldHost);
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;

    return {
      fixture,
      host: fixture.componentInstance,
      label: element.querySelector('label')!,
      input: element.querySelector('input')!,
      element,
    };
  }

  it('associates the label with the projected control', () => {
    const { label, input } = mount();

    expect(input.id).not.toBe('');
    expect(label.getAttribute('for')).toBe(input.id);
  });

  it('points the control at its hint', () => {
    const { input, element } = mount();

    const describedBy = input.getAttribute('aria-describedby');

    expect(describedBy).not.toBeNull();
    expect(element.querySelector(`#${describedBy}`)?.textContent).toContain(
      'We send tickets here.',
    );
  });

  it('describes the error instead of the hint once something is wrong', () => {
    const { fixture, host, input, element } = mount();

    host.error.set('Enter an email address.');
    fixture.detectChanges();

    const describedBy = input.getAttribute('aria-describedby')!;

    expect(input.getAttribute('aria-invalid')).toBe('true');
    expect(element.querySelector(`#${describedBy}`)?.textContent).toContain(
      'Enter an email address.',
    );
    // The hint is gone, not stacked beneath. Two descriptions where one is
    // stale is worse than one.
    expect(element.textContent).not.toContain('We send tickets here.');
  });

  it('clears invalid when the error clears', () => {
    const { fixture, host, input } = mount();

    host.error.set('Enter an email address.');
    fixture.detectChanges();
    host.error.set(null);
    fixture.detectChanges();

    expect(input.hasAttribute('aria-invalid')).toBe(false);
  });

  it('announces required, which the asterisk alone does not', () => {
    const { fixture, host, input } = mount();

    host.required.set(true);
    fixture.detectChanges();

    expect(input.getAttribute('aria-required')).toBe('true');
  });
});

@Component({
  imports: [UiPagination],
  template: `
    <ui-pagination
      [page]="page()"
      [perPage]="25"
      [total]="312"
      noun="attendees"
      (changed)="page.set($event)"
    />
  `,
})
class PagerHost {
  readonly page = signal(1);
}

describe('UiPagination', () => {
  function mount(page: number) {
    const fixture = TestBed.createComponent(PagerHost);
    fixture.componentInstance.page.set(page);
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;

    return {
      fixture,
      text: element.textContent?.replace(/\s+/g, ' ') ?? '',
      buttons: Array.from(element.querySelectorAll('button')),
    };
  }

  it('reads the range for the page you are on', () => {
    expect(mount(1).text).toContain('1–25 of 312 attendees');
    expect(mount(2).text).toContain('26–50 of 312');
  });

  it('does not run the last page past the total', () => {
    // 13 × 25 is 325. The short last page is where this goes wrong.
    expect(mount(13).text).toContain('301–312 of 312');
  });

  it('cannot go back from the first page or forward from the last', () => {
    expect(mount(1).buttons[0].disabled).toBe(true);
    expect(mount(1).buttons[1].disabled).toBe(false);
    expect(mount(13).buttons[1].disabled).toBe(true);
  });
});

@Component({
  imports: [UiSortHeader],
  template: `
    <table>
      <thead>
        <tr>
          <th uiSort="name" label="Name" [sort]="sort()" (sorted)="sort.set($event)"></th>
          <th uiSort="created_at" label="Bought" [sort]="sort()" (sorted)="sort.set($event)"></th>
        </tr>
      </thead>
    </table>
  `,
})
class SortHost {
  readonly sort = signal<Sort | null>(null);
}

describe('UiSortHeader', () => {
  it('reports its direction through aria-sort, not just an arrow', () => {
    const fixture = TestBed.createComponent(SortHost);
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    const [name, bought] = Array.from(element.querySelectorAll('th'));

    expect(name.getAttribute('aria-sort')).toBe('none');

    name.querySelector('button')!.click();
    fixture.detectChanges();

    expect(fixture.componentInstance.sort()).toEqual({ column: 'name', direction: 'asc' });
    expect(name.getAttribute('aria-sort')).toBe('ascending');
    // The other column has to be told it is no longer sorted, or two headings
    // both claim to be the sort.
    expect(bought.getAttribute('aria-sort')).toBe('none');

    name.querySelector('button')!.click();
    fixture.detectChanges();

    expect(name.getAttribute('aria-sort')).toBe('descending');
  });

  it('starts a newly chosen column ascending rather than inheriting the last direction', () => {
    const fixture = TestBed.createComponent(SortHost);
    fixture.componentInstance.sort.set({ column: 'name', direction: 'desc' });
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    const bought = Array.from(element.querySelectorAll('th'))[1];

    bought.querySelector('button')!.click();
    fixture.detectChanges();

    expect(fixture.componentInstance.sort()).toEqual({
      column: 'created_at',
      direction: 'asc',
    });
  });
});
