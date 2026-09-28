import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { FormsModule } from '@angular/forms';
import { Router, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
  Selection,
  UiColumnMenu,
  UiSelect,
  createListState,
  parseAmount,
  type SelectOption,
} from '@myfiesta/ui';

/**
 * The state behind every filtered table.
 *
 * What is pinned is the part a reader notices only when it is wrong: a link
 * that opens the list it was copied from, a back button that brings the
 * filters back, a page that resets when the filter changes (page 7 of a list
 * that now has one page is an empty table), a column preference that survives
 * a reload but never travels in a link, and a saved view that cannot smuggle
 * in a filter the list does not have.
 */

@Component({ template: '' })
class OrdersScreen {
  readonly list = createListState({
    list: 'orders-spec',
    filters: {
      q: { kind: 'text' },
      status: { kind: 'many' },
      event_id: { kind: 'one' },
      min_total: { kind: 'int' },
      from: { kind: 'day' },
    },
    sort: { column: 'paid_at', direction: 'desc' },
    columns: [
      { id: 'buyer', label: 'Buyer', required: true },
      { id: 'event', label: 'Event' },
      { id: 'reference', label: 'Reference' },
      { id: 'signals', label: 'Signals', hidden: true },
    ],
  });
}

async function open(url: string) {
  TestBed.configureTestingModule({
    providers: [provideRouter([{ path: 'orders', component: OrdersScreen }])],
  });

  const harness = await RouterTestingHarness.create();
  const screen = await harness.navigateByUrl(url, OrdersScreen);
  const router = TestBed.inject(Router);

  const settle = async () => {
    harness.detectChanges();
    await harness.fixture.whenStable();
  };

  return { harness, screen, list: screen.list, router, settle };
}

describe('createListState', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => localStorage.clear());

  it('opens the list a link describes', async () => {
    const { list } = await open('/orders?q=ada&status=paid&status=refunded&event_id=e1&min_total=5000&from=2026-09-01&sort=total&dir=asc&page=3');

    expect(list.get('q')).toBe('ada');
    expect(list.get('status')).toEqual(['paid', 'refunded']);
    expect(list.get('event_id')).toBe('e1');
    expect(list.get('min_total')).toBe(5000);
    expect(list.get('from')).toBe('2026-09-01');
    expect(list.sort()).toEqual({ column: 'total', direction: 'asc' });
    expect(list.page()).toBe(3);
    expect(list.active()).toBe(5);
  });

  it('ignores what the address says that is not a value of that kind', async () => {
    const { list } = await open('/orders?min_total=lots&from=yesterday&page=-4');

    expect(list.get('min_total')).toBeNull();
    expect(list.get('from')).toBeNull();
    expect(list.page()).toBe(1);
    expect(list.active()).toBe(0);
  });

  it('asks for only the filters that are on, with the sort, and the page', async () => {
    const { list } = await open('/orders?status=paid&page=2');

    expect(list.query()).toEqual({ status: ['paid'], sort: 'paid_at', dir: 'desc', page: 2 });
    expect(list.criteria()).toEqual({ status: ['paid'], sort: 'paid_at', dir: 'desc' });
  });

  it('goes back to the first page when a filter or the sort changes', async () => {
    const { list } = await open('/orders?page=4');

    list.set('q', 'bisi');
    expect(list.page()).toBe(1);

    list.goTo(5);
    list.sortBy({ column: 'total', direction: 'desc' });
    expect(list.page()).toBe(1);
  });

  it('writes the list into the address, and leaves the default sort out of it', async () => {
    const { list, router, settle } = await open('/orders');

    list.set('status', ['refunded', 'pending']);
    list.set('q', 'ada');
    await settle();

    const tree = router.parseUrl(router.url);
    expect(tree.queryParams).toEqual({ status: ['refunded', 'pending'], q: 'ada' });

    list.sortBy({ column: 'total', direction: 'asc' });
    list.goTo(2);
    await settle();

    expect(router.parseUrl(router.url).queryParams).toMatchObject({ sort: 'total', dir: 'asc', page: '2' });

    list.clearAll();
    list.sortBy(null);
    await settle();

    expect(router.parseUrl(router.url).queryParams).toEqual({});
  });

  it('follows the address back when it changes under the screen', async () => {
    const { list, router, settle } = await open('/orders?q=ada');

    await router.navigateByUrl('/orders?q=chidi&status=paid');
    await settle();

    expect(list.get('q')).toBe('chidi');
    expect(list.get('status')).toEqual(['paid']);
  });

  it('remembers the columns and the density on this browser, never in the link', async () => {
    const first = await open('/orders');

    expect(first.list.visible()).toEqual(['buyer', 'event', 'reference']);

    first.list.toggleColumn('reference');
    first.list.toggleColumn('signals');
    // The column that names the row cannot go.
    first.list.toggleColumn('buyer');
    first.list.setDensity('compact');
    await first.settle();

    expect(first.list.visible()).toEqual(['buyer', 'event', 'signals']);
    expect(first.router.parseUrl(first.router.url).queryParams).toEqual({});

    TestBed.resetTestingModule();
    const again = await open('/orders');

    expect(again.list.visible()).toEqual(['buyer', 'event', 'signals']);
    expect(again.list.density()).toBe('compact');

    again.list.resetColumns();
    expect(again.list.visible()).toEqual(['buyer', 'event', 'reference']);
  });

  it('keeps and puts back a view, without filters the list does not have', async () => {
    const { list } = await open('/orders?q=ada&status=paid');
    list.toggleColumn('event');

    const snapshot = list.snapshot();
    expect(snapshot).toEqual({ q: 'ada', status: ['paid'], sort: 'paid_at', dir: 'desc', columns: ['buyer', 'reference'] });

    list.clearAll();
    list.goTo(3);
    list.apply({ ...snapshot, password: 'hunter2', columns: ['buyer', 'nonsense', 'event'] });

    expect(list.get('q')).toBe('ada');
    expect(list.get('status')).toEqual(['paid']);
    expect(list.page()).toBe(1);
    expect(list.visible()).toEqual(['buyer', 'event']);
    expect('password' in list.values()).toBe(false);
  });
});

describe('Selection', () => {
  it('ticks rows, the heading ticks all of them, and a reload keeps only rows still there', () => {
    const selection = new Selection();
    const rows = ['a', 'b', 'c'];

    selection.toggle('a');
    expect(selection.some(rows)).toBe(true);
    expect(selection.all(rows)).toBe(false);

    selection.toggleAll(rows);
    expect(selection.all(rows)).toBe(true);
    expect(selection.count()).toBe(3);

    selection.keep(['b', 'c', 'd']);
    expect(selection.ids()).toEqual(['b', 'c']);

    selection.toggleAll(['b', 'c']);
    expect(selection.count()).toBe(0);
  });
});

describe('parseAmount', () => {
  it('reads money the way people type it, into the smallest unit', () => {
    expect(parseAmount('50')).toBe(5000);
    expect(parseAmount('49.99')).toBe(4999);
    expect(parseAmount('1,250.5')).toBe(125050);
    expect(parseAmount('$ 12')).toBe(1200);
    expect(parseAmount('')).toBeNull();
    expect(parseAmount('lots')).toBeNull();
    expect(parseAmount('1.234')).toBeNull();
  });
});

const STATUSES: SelectOption[] = [
  { value: 'paid', label: 'Paid' },
  { value: 'refunded', label: 'Refunded' },
  { value: 'pending', label: 'Confirming' },
];

@Component({
  imports: [UiSelect, FormsModule],
  template: `<ui-select multiple noun="statuses" ariaLabel="Status" [options]="options" [ngModel]="chosen()" (ngModelChange)="chosen.set($event)" />`,
})
class MultiHost {
  readonly options = STATUSES;
  readonly chosen = signal<string[]>(['paid']);
}

describe('UiSelect, several at once', () => {
  it('ticks without closing, says what is chosen, and reaches the form as a list', async () => {
    const fixture = TestBed.createComponent(MultiHost);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    const trigger = element.querySelector<HTMLButtonElement>('button[role=combobox]')!;

    expect(trigger.textContent?.trim()).toBe('Paid');

    trigger.click();
    fixture.detectChanges();

    expect(element.querySelector('[role=listbox]')?.getAttribute('aria-multiselectable')).toBe('true');

    const options = () => [...element.querySelectorAll<HTMLElement>('[role=option]')];
    options()[1].click();
    fixture.detectChanges();

    expect(fixture.componentInstance.chosen()).toEqual(['paid', 'refunded']);
    expect(options().map((o) => o.getAttribute('aria-selected'))).toEqual(['true', 'true', 'false']);
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    expect(trigger.textContent).toContain('Paid, Refunded');

    options()[2].click();
    fixture.detectChanges();
    expect(trigger.textContent).toContain('3 statuses');

    element.querySelector<HTMLButtonElement>('.ui-select__clear')!.click();
    fixture.detectChanges();
    expect(fixture.componentInstance.chosen()).toEqual([]);
  });
});

@Component({
  imports: [UiColumnMenu],
  template: `<ui-column-menu [state]="screen.list" />`,
})
class ColumnHost {
  readonly screen = TestBed.runInInjectionContext(() => new OrdersScreen());
}

describe('UiColumnMenu', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({ providers: [provideRouter([])] });
  });

  it('shows and hides columns, keeps the naming one, and sets the density', () => {
    const fixture = TestBed.createComponent(ColumnHost);
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    element.querySelector<HTMLButtonElement>('.menu__button')!.click();
    fixture.detectChanges();

    const boxes = [...element.querySelectorAll<HTMLInputElement>('input[type=checkbox]')];
    expect(boxes.map((b) => b.checked)).toEqual([true, true, true, false]);
    expect(boxes[0].disabled).toBe(true);

    boxes[3].click();
    fixture.detectChanges();
    expect(fixture.componentInstance.screen.list.visible()).toContain('signals');

    const compact = [...element.querySelectorAll<HTMLButtonElement>('[role=radio]')].find((b) => b.textContent?.trim() === 'Compact')!;
    compact.click();
    fixture.detectChanges();
    expect(fixture.componentInstance.screen.list.density()).toBe('compact');
    expect(compact.getAttribute('aria-checked')).toBe('true');
  });
});
