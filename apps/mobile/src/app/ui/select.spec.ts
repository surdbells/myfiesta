import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { FormsModule } from '@angular/forms';
import { describe, expect, it } from 'vitest';
import { MfSelect, type MfOption } from './select';

/**
 * The picker, driven the way a thumb drives it: tap to open, type to narrow,
 * tap to choose, and the sheet is gone with a value behind it.
 */

const CITIES: MfOption[] = [
  { value: 'toronto', label: 'Toronto', hint: 'Ontario' },
  { value: 'montreal', label: 'Montréal', hint: 'Québec' },
  { value: 'ottawa', label: 'Ottawa', hint: 'Ontario' },
  { value: 'calgary', label: 'Calgary', hint: 'Alberta' },
  { value: 'vancouver', label: 'Vancouver', hint: 'British Columbia' },
  { value: 'lagos', label: 'Lagos', hint: 'Lagos State' },
  { value: 'abuja', label: 'Abuja', hint: 'FCT' },
  { value: 'kano', label: 'Kano', hint: 'Kano State', disabled: true },
];

@Component({
  imports: [MfSelect, FormsModule],
  template: `
    <mf-select
      heading="Which city"
      [options]="options()"
      [ngModel]="city()"
      (ngModelChange)="city.set($event)"
    />
  `,
})
class Host {
  readonly options = signal<MfOption[]>(CITIES);
  readonly city = signal<string | null>('toronto');
}

async function mount() {
  const fixture = TestBed.createComponent(Host);
  fixture.autoDetectChanges();
  await fixture.whenStable();

  const root: HTMLElement = fixture.nativeElement;

  return {
    fixture,
    host: fixture.componentInstance,
    trigger: () => root.querySelector<HTMLButtonElement>('.trigger')!,
    search: () => root.querySelector<HTMLInputElement>('.search input'),
    options: () => Array.from(root.querySelectorAll<HTMLButtonElement>('.option')),
    labels: () => Array.from(root.querySelectorAll('.option .text')).map((o) => o.textContent?.trim() ?? ''),
    open: async () => {
      root.querySelector<HTMLButtonElement>('.trigger')!.click();
      await fixture.whenStable();
    },
    settle: () => fixture.whenStable(),
  };
}

describe('MfSelect', () => {
  it('shows what is chosen, and its hint, on the closed trigger', async () => {
    const ui = await mount();

    expect(ui.trigger().textContent).toContain('Toronto');
    expect(ui.trigger().textContent).toContain('Ontario');
    expect(ui.options()).toHaveLength(0);
  });

  it('opens a sheet with every option and marks the chosen one', async () => {
    const ui = await mount();

    await ui.open();

    expect(ui.options()).toHaveLength(CITIES.length);
    expect(ui.trigger().getAttribute('aria-expanded')).toBe('true');

    const chosen = ui.options().find((option) => option.classList.contains('selected'));
    expect(chosen?.textContent).toContain('Toronto');
  });

  it('narrows on the label and on the hint, because a city is not always in the name', async () => {
    const ui = await mount();
    await ui.open();

    ui.search()!.value = 'ontario';
    ui.search()!.dispatchEvent(new Event('input'));
    await ui.settle();

    // Both Ontario cities, found through their hint.
    expect(ui.labels().map((label) => label.split(' ')[0])).toEqual(['Toronto', 'Ottawa']);

    ui.search()!.value = 'lag';
    ui.search()!.dispatchEvent(new Event('input'));
    await ui.settle();

    expect(ui.labels()).toHaveLength(1);
    expect(ui.labels()[0]).toContain('Lagos');
  });

  it('says so when nothing matches, rather than showing an empty sheet', async () => {
    const ui = await mount();
    await ui.open();

    ui.search()!.value = 'zzz';
    ui.search()!.dispatchEvent(new Event('input'));
    await ui.settle();

    expect(ui.options()).toHaveLength(0);
    expect((ui.fixture.nativeElement as HTMLElement).querySelector('.none')?.textContent).toContain('zzz');
  });

  it('chooses, tells the form, and closes', async () => {
    const ui = await mount();
    await ui.open();

    const lagos = ui.options().find((option) => option.textContent?.includes('Lagos'))!;
    lagos.click();
    await ui.settle();

    expect(ui.host.city()).toBe('lagos');
    expect(ui.trigger().textContent).toContain('Lagos');

    // The sheet is on its way out; what matters is that it is no longer
    // holding the options open.
    expect(ui.trigger().getAttribute('aria-expanded')).toBe('false');
  });

  it('refuses a disabled option', async () => {
    const ui = await mount();
    await ui.open();

    const kano = ui.options().find((option) => option.textContent?.includes('Kano'))!;
    expect(kano.disabled).toBe(true);

    kano.click();
    await ui.settle();

    expect(ui.host.city()).toBe('toronto');
  });

  it('leaves the search box out of a list short enough not to need one', async () => {
    const ui = await mount();

    ui.host.options.set(CITIES.slice(0, 3));
    await ui.settle();
    await ui.open();

    expect(ui.search()).toBeNull();
    expect(ui.options()).toHaveLength(3);
  });
});

/**
 * Driven without a thumb.
 *
 * Tab reaches every option on its own — the sheet keeps focus inside itself —
 * but a list of twenty cities is a lot of tabbing to reach Vancouver, and a
 * list that ignores an arrow key is a list that feels broken to anybody who
 * tried one.
 */
describe('MfSelect from the keyboard', () => {
  async function opened() {
    const ui = await mount();
    await ui.open();

    const sheet = (ui.fixture.nativeElement as HTMLElement).querySelector('mf-sheet')!;
    const press = async (key: string) => {
      sheet.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
      await ui.settle();
    };

    return { ...ui, press, focused: () => document.activeElement?.textContent?.trim() ?? '' };
  }

  it('steps into the list on the first arrow', async () => {
    const ui = await opened();

    await ui.press('ArrowDown');

    expect(ui.focused()).toContain('Toronto');
  });

  it('walks down and back up', async () => {
    const ui = await opened();

    await ui.press('ArrowDown');
    await ui.press('ArrowDown');
    expect(ui.focused()).toContain('Montréal');

    await ui.press('ArrowUp');
    expect(ui.focused()).toContain('Toronto');
  });

  it('wraps at both ends rather than stopping dead', async () => {
    const ui = await opened();

    await ui.press('End');
    expect(ui.focused()).toContain('Abuja');

    // Kano is disabled, so End lands on the last option somebody can take.
    await ui.press('ArrowDown');
    expect(ui.focused()).toContain('Toronto');

    await ui.press('ArrowUp');
    expect(ui.focused()).toContain('Abuja');
  });

  it('goes to either end in one press', async () => {
    const ui = await opened();

    await ui.press('ArrowDown');
    await ui.press('End');
    expect(ui.focused()).toContain('Abuja');

    await ui.press('Home');
    expect(ui.focused()).toContain('Toronto');
  });

  it('leaves every other key alone, so the search box still works', async () => {
    const ui = await opened();
    const search = ui.search()!;

    search.focus();
    await ui.press('a');

    // Typing belongs to the box above the list; only movement is taken.
    expect(document.activeElement).toBe(search);
  });
});

@Component({
  imports: [MfSelect],
  template: `<mf-select #picker bare heading="Which event is it for?" [options]="options" (valueChange)="chosen.set($event)" />`,
})
class BareHost {
  readonly options = CITIES;
  readonly chosen = signal<string | null>(null);
}

describe('A bare select', () => {
  it('has no field of its own, and opens when asked', async () => {
    const fixture = TestBed.createComponent(BareHost);
    fixture.autoDetectChanges();
    await fixture.whenStable();

    const root: HTMLElement = fixture.nativeElement;

    // A choice asked in the middle of something else: no trigger on the page.
    expect(root.querySelector('.trigger')).toBeNull();
    expect(root.querySelectorAll('.option').length).toBe(0);

    const select = fixture.debugElement.children[0].componentInstance as MfSelect;
    select.show();
    await fixture.whenStable();

    root.querySelectorAll<HTMLButtonElement>('.option')[5].click();
    await fixture.whenStable();

    expect(fixture.componentInstance.chosen()).toBe('lagos');
  });
});
