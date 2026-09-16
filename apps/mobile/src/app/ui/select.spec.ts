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
