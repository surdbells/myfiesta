import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import {
  UiDateRange,
  UiField,
  UiFilterBar,
  UiPagination,
  UiSelect,
  UiSortHeader,
  type DateRange,
  type FilterChip,
  type Sort,
} from '@myfiesta/ui';

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

@Component({
  imports: [UiField, UiSelect],
  template: `
    <ui-field label="How you want to be paid" required>
      <ui-select ariaLabel="How you want to be paid" [options]="[{ value: 'interac', label: 'Interac' }]" />
    </ui-field>
  `,
})
class FieldAroundSelect {}

/**
 * A field whose control is a component, not an input.
 *
 * The control arrives one render later than a plain input does, and the field
 * used to look for it once — so the label ended up pointing at an id nothing
 * had, which looks perfect on screen and is a control a screen reader cannot
 * name. Found by an audit on the payouts screen.
 */
describe('UiField around a component', () => {
  it('points its label at the control, whenever that control turns up', async () => {
    const fixture = TestBed.createComponent(FieldAroundSelect);
    fixture.autoDetectChanges();
    await fixture.whenStable();

    const element = fixture.nativeElement as HTMLElement;
    const label = element.querySelector('label')!;
    const trigger = element.querySelector('button[role=combobox]')!;

    expect(trigger.id).toBeTruthy();
    expect(label.getAttribute('for')).toBe(trigger.id);
  });

  it('points at nothing rather than at something that is not there', async () => {
    const fixture = TestBed.createComponent(EmptyField);
    fixture.autoDetectChanges();
    await fixture.whenStable();

    const label = (fixture.nativeElement as HTMLElement).querySelector('label')!;

    // A dangling `for` reads as wired and is not.
    expect(label.getAttribute('for')).toBeNull();
  });
});

@Component({
  imports: [UiField],
  template: `<ui-field label="Nothing here" />`,
})
class EmptyField {}

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

@Component({
  imports: [UiField],
  template: `
    <ui-field label="Email">
      <input id="email" type="email" />
    </ui-field>
  `,
})
class LabelledFieldHost {}

describe('UiField with an id of its own', () => {
  it('labels the id the screen gave the control, not the generated one', () => {
    const fixture = TestBed.createComponent(LabelledFieldHost);
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;
    const label = element.querySelector('label')!;
    const input = element.querySelector('input')!;

    // The screen keeps its id — autofill and password managers key off it.
    expect(input.id).toBe('email');
    // And the label follows it. Pointing at the generated id instead leaves
    // the label attached to nothing, which looks identical on screen.
    expect(label.getAttribute('for')).toBe('email');
  });
});

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

@Component({
  imports: [UiFilterBar],
  template: `
    <ui-filter-bar
      searchLabel="Search orders"
      [chips]="chips()"
      [summary]="summary()"
      (removed)="take($event)"
      (clearedAll)="takeAll()"
    />
  `,
})
class FilterBarHost {
  readonly chips = signal<FilterChip[]>([]);
  readonly summary = signal<string | null>(null);
  readonly removed = signal<string | null>(null);
  readonly cleared = signal(0);

  /** What a real host does: take the filter off and re-render without it. */
  take(key: string): void {
    this.removed.set(key);
    this.chips.update((chips) => chips.filter((chip) => chip.key !== key));
  }

  takeAll(): void {
    this.cleared.update((n) => n + 1);
    this.chips.set([]);
  }
}

/**
 * The filter bar's job is the half nobody looks at.
 *
 * A filtered table that looks exactly like an unfiltered one is how somebody
 * exports four hundred rows believing they exported everything, or reads a
 * week of takings as a year of them. The chips and the count are what stop
 * that, so they are what is tested — not the search box, which is visibly
 * there or visibly not.
 */
describe('UiFilterBar', () => {
  function mount(chips: FilterChip[], summary: string | null = null) {
    const fixture = TestBed.createComponent(FilterBarHost);
    fixture.componentInstance.chips.set(chips);
    fixture.componentInstance.summary.set(summary);
    fixture.detectChanges();

    return { fixture, element: fixture.nativeElement as HTMLElement };
  }

  const both: FilterChip[] = [
    { key: 'event', label: 'Event', value: 'Afro Fest' },
    { key: 'status', label: 'Status', value: 'Paid' },
  ];

  it('says nothing at all when nothing is filtered', () => {
    const { element } = mount([]);

    expect(element.querySelector('.state')).toBeNull();
    expect(element.textContent).not.toContain('Clear all');
  });

  it('names every filter that is on, and what it is set to', () => {
    // Read per chip rather than from the bar's text: the gap between a label
    // and its value is drawn by CSS, so the two run together in textContent
    // and "EventAfro Fest" would pass a looser assertion.
    const said = Array.from(mount(both).element.querySelectorAll('.chips li')).map((chip) => [
      chip.querySelector('.chip__label')?.textContent?.trim(),
      chip.querySelector('.chip__value')?.textContent?.trim(),
    ]);

    expect(said).toEqual([
      ['Event', 'Afro Fest'],
      ['Status', 'Paid'],
    ]);
  });

  it('gives each chip a remove button a screen reader can tell apart', () => {
    const labels = Array.from(mount(both).element.querySelectorAll('.chips button')).map((button) =>
      button.getAttribute('aria-label'),
    );

    expect(labels).toEqual(['Remove Event filter', 'Remove Status filter']);
  });

  it('removes one filter by itself, and all of them together', () => {
    const { fixture, element } = mount(both);

    const [, second] = Array.from(element.querySelectorAll<HTMLButtonElement>('.chips button'));
    second.click();
    expect(fixture.componentInstance.removed()).toBe('status');

    element.querySelector<HTMLButtonElement>('.clear')!.click();
    expect(fixture.componentInstance.cleared()).toBe(1);
  });

  /*
   * Where focus goes when the thing you pressed disappears.
   *
   * A remove button takes focus to <body> with it, so clearing three filters
   * by keyboard throws you to the top of the page twice. None of this is
   * visible to anybody using a mouse, which is exactly why it rots.
   */
  it('leaves focus on the chip that took the place of the one removed', async () => {
    const { fixture, element } = mount(both);

    const [first] = Array.from(element.querySelectorAll<HTMLButtonElement>('.chips button'));
    first.focus();
    first.click();

    await fixture.whenStable();
    fixture.detectChanges();

    expect(document.activeElement).toBe(element.querySelector('.chips button'));
    expect(document.activeElement?.getAttribute('aria-label')).toBe('Remove Status filter');
  });

  it('falls back to the last chip when the one removed was last', async () => {
    const { fixture, element } = mount(both);

    const buttons = Array.from(element.querySelectorAll<HTMLButtonElement>('.chips button'));
    buttons[1].focus();
    buttons[1].click();

    await fixture.whenStable();
    fixture.detectChanges();

    expect(document.activeElement?.getAttribute('aria-label')).toBe('Remove Event filter');
  });

  it('falls back to the search box when the last filter comes off', async () => {
    const { fixture, element } = mount([{ key: 'status', label: 'Status', value: 'Paid' }]);

    element.querySelector<HTMLButtonElement>('.chips button')!.click();

    await fixture.whenStable();
    fixture.detectChanges();

    expect(document.activeElement).toBe(element.querySelector('input[type="search"]'));
  });

  it('does the same when everything is cleared at once', async () => {
    const { fixture, element } = mount(both);

    element.querySelector<HTMLButtonElement>('.clear')!.click();

    await fixture.whenStable();
    fixture.detectChanges();

    expect(fixture.componentInstance.cleared()).toBe(1);
    expect(document.activeElement).toBe(element.querySelector('input[type="search"]'));
  });

  it('announces the count rather than only drawing it', () => {
    const { element } = mount(both, 'Showing 12 of 340 orders');
    const summary = element.querySelector('.summary');

    expect(summary?.textContent).toContain('Showing 12 of 340 orders');
    expect(summary?.getAttribute('aria-live')).toBe('polite');
  });

  it('shows the count even when no chip is on, because that is the honest total', () => {
    const { element } = mount([], '340 orders');

    expect(element.querySelector('.summary')?.textContent).toContain('340 orders');
    expect(element.textContent).not.toContain('Clear all');
  });
});

@Component({
  imports: [UiDateRange],
  template: `<ui-date-range [value]="value()" (valueChange)="value.set($event)" />`,
})
class DateRangeHost {
  readonly value = signal<DateRange>({ from: null, to: null });
}

/**
 * A range that reads back as what was chosen.
 *
 * The button is the only thing on screen once the panel closes, so if it says
 * "Any time" while a range is set, the table below it is narrowed by something
 * invisible. Matching the presets is the part that gets this wrong: it has to
 * refuse to claim "Last 7 days" for a range that merely happens to be seven
 * days long somewhere in the past.
 */
describe('UiDateRange', () => {
  function mount() {
    const fixture = TestBed.createComponent(DateRangeHost);
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;

    return {
      fixture,
      element,
      label: () => element.querySelector('button')?.textContent?.replace(/\s+/g, ' ').trim() ?? '',
      open: () => {
        element.querySelector<HTMLButtonElement>('button')!.click();
        fixture.detectChanges();
      },
      set: (value: DateRange) => {
        fixture.componentInstance.value.set(value);
        fixture.detectChanges();
      },
    };
  }

  it('reads as the noun until something is chosen', () => {
    expect(mount().label()).toContain('Any time');
  });

  it('sets a real pair of dates when a preset is picked, ending today', () => {
    const view = mount();
    view.open();

    Array.from(view.element.querySelectorAll<HTMLButtonElement>('.presets button'))
      .find((button) => button.textContent?.includes('Last 7 days'))!
      .click();
    view.fixture.detectChanges();

    const { from, to } = view.fixture.componentInstance.value();

    expect(to).toBe(new Date().toISOString().slice(0, 10));
    expect((Date.parse(to!) - Date.parse(from!)) / 86_400_000).toBe(6);
    expect(view.label()).toContain('Last 7 days');
  });

  it('closes the panel once a preset is taken', () => {
    const view = mount();
    view.open();
    expect(view.element.querySelector('.panel')).not.toBeNull();

    view.element.querySelector<HTMLButtonElement>('.presets button')!.click();
    view.fixture.detectChanges();

    expect(view.element.querySelector('.panel')).toBeNull();
  });

  it('says what its button opens, not only that it is open', () => {
    const view = mount();
    const trigger = view.element.querySelector('button')!;

    expect(trigger.getAttribute('aria-haspopup')).toBe('dialog');
    expect(trigger.getAttribute('aria-expanded')).toBe('false');

    view.open();
    expect(view.element.querySelector('button')!.getAttribute('aria-expanded')).toBe('true');
  });

  it('hands focus back to its own button when the panel closes', () => {
    const view = mount();
    view.open();

    // Taking a preset, the ordinary way out.
    view.element.querySelector<HTMLButtonElement>('.presets button')!.click();
    view.fixture.detectChanges();
    expect(document.activeElement).toBe(view.element.querySelector('button'));

    // And Escape, the way out for anybody who changed their mind.
    view.open();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    view.fixture.detectChanges();

    expect(view.element.querySelector('.panel')).toBeNull();
    expect(document.activeElement).toBe(view.element.querySelector('button'));
  });

  it('will not call an arbitrary week "Last 7 days"', () => {
    const view = mount();

    // Seven days long, but ending in the past. Claiming the preset here would
    // put the wrong words under a table showing something else.
    view.set({ from: '2026-02-01', to: '2026-02-07' });

    expect(view.label()).not.toContain('Last 7 days');
    expect(view.label()).toContain('Feb');
  });

  it('says which end is open when only one is set', () => {
    const view = mount();

    view.set({ from: '2026-02-01', to: null });
    expect(view.label()).toContain('From');

    view.set({ from: null, to: '2026-02-07' });
    expect(view.label()).toContain('Until');
  });
});
