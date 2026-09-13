import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { FormsModule } from '@angular/forms';
import { describe, expect, it } from 'vitest';
import { UiSelect, type SelectOption } from '@myfiesta/ui';

/**
 * The searchable dropdown, driven the way people use it: a label that reaches
 * it, typing to find, arrows and Enter to choose, Escape to back out, and a
 * value that reaches the form.
 */

const PROVINCES: SelectOption[] = [
  { value: 'AB', label: 'Alberta' },
  { value: 'ON', label: 'Ontario' },
  { value: 'QC', label: 'Québec' },
  { value: 'SK', label: 'Saskatchewan', disabled: true },
  { value: 'NS', label: 'Nova Scotia' },
];

@Component({
  imports: [UiSelect, FormsModule],
  template: `
    <label for="province">Province</label>
    <ui-select
      controlId="province"
      ariaLabel="Province"
      [options]="options"
      [ngModel]="province()"
      (ngModelChange)="province.set($event)"
    />
  `,
})
class Host {
  readonly options = PROVINCES;
  readonly province = signal<string | null>('ON');
}

async function mount() {
  const fixture = TestBed.createComponent(Host);
  fixture.detectChanges();
  await fixture.whenStable();
  fixture.detectChanges();

  const element: HTMLElement = fixture.nativeElement;
  const trigger = element.querySelector<HTMLButtonElement>('button[role=combobox]')!;
  const search = () => element.querySelector<HTMLInputElement>('input[role=searchbox]')!;
  const options = () => [...element.querySelectorAll<HTMLElement>('[role=option]')];

  const key = async (target: HTMLElement, name: string) => {
    target.dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true }));
    fixture.detectChanges();
    await Promise.resolve();
    fixture.detectChanges();
  };

  const type = (text: string) => {
    search().value = text;
    search().dispatchEvent(new Event('input'));
    fixture.detectChanges();
  };

  return { fixture, element, trigger, search, options, key, type, host: fixture.componentInstance };
}

describe('UiSelect', () => {
  it('is reached by its label and shows the chosen option', async () => {
    const { element, trigger } = await mount();

    expect(element.querySelector('label')!.getAttribute('for')).toBe(trigger.id);
    expect(trigger.textContent?.trim()).toBe('Ontario');
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
  });

  it('filters as you type, ignoring accents and case', async () => {
    const { trigger, key, type, options } = await mount();

    await key(trigger, 'ArrowDown');
    type('quebec');

    expect(options().map((o) => o.textContent?.trim())).toEqual(['Québec']);
  });

  it('chooses with the keyboard and hands the value to the form', async () => {
    const { trigger, key, type, search, host, fixture } = await mount();

    await key(trigger, 'Enter');
    type('nova');
    await key(search(), 'Enter');
    await fixture.whenStable();

    expect(host.province()).toBe('NS');
    expect(trigger.textContent?.trim()).toBe('Nova Scotia');
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
  });

  it('opens already searching for a letter typed on the closed control', async () => {
    const { trigger, key, search, options } = await mount();

    await key(trigger, 'a');

    expect(search().value).toBe('a');
    expect(options().length).toBeGreaterThan(0);
    expect(options().every((o) => /a/i.test(o.textContent ?? ''))).toBe(true);
  });

  it('skips a disabled option when moving', async () => {
    const { trigger, key, search, host, fixture } = await mount();

    await key(trigger, 'ArrowDown'); // opens on Ontario (index 1)
    await key(search(), 'ArrowDown'); // Québec
    await key(search(), 'ArrowDown'); // Saskatchewan is disabled → Nova Scotia
    await key(search(), 'Enter');
    await fixture.whenStable();

    expect(host.province()).toBe('NS');
  });

  it('points the search at the highlighted option for screen readers', async () => {
    const { trigger, key, search, element } = await mount();

    await key(trigger, 'ArrowDown');

    const active = search().getAttribute('aria-activedescendant')!;
    expect(element.querySelector(`#${active}`)?.textContent?.trim()).toBe('Ontario');
  });

  it('backs out with Escape, leaving the value and returning focus', async () => {
    const { trigger, key, type, search, host } = await mount();

    await key(trigger, 'ArrowDown');
    type('alb');
    await key(search(), 'Escape');

    expect(host.province()).toBe('ON');
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    expect(document.activeElement).toBe(trigger);
  });

  it('moves focus into the search once the list has rendered', async () => {
    const { trigger, key, search, fixture } = await mount();

    await key(trigger, 'ArrowDown');
    await fixture.whenStable();
    fixture.detectChanges();

    // Focusing before the list was visible did nothing, and every key after
    // the first then went to the closed control.
    expect(document.activeElement).toBe(search());
  });

  it('keeps keys typed before focus arrives in the search', async () => {
    const { trigger, key, search } = await mount();

    await key(trigger, 'n');
    await key(trigger, 'o');

    expect(search().value).toBe('no');
  });

  it('says so when nothing matches', async () => {
    const { trigger, key, type, element } = await mount();

    await key(trigger, 'ArrowDown');
    type('zzz');

    expect(element.textContent).toContain('No matches');
  });
});
