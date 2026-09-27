import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { toMajor, toMinor } from '../core/money';
import { MfStepper } from './stepper';
import { MfMultiSelect } from './multi-select';
import { Dialogs } from './dialogs';
import type { MfOption } from './select';

/**
 * The parts of the kit that are wrong silently.
 *
 * A price that comes back a cent out, a stepper that wraps from 10 to 1, a
 * dialog whose promise never settles because it was dragged away rather than
 * answered, a multi-select that commits half a choice when it is dismissed —
 * each of these looks fine on screen until the moment it costs somebody.
 */

describe('typing money', () => {
  it('holds what was typed as whole minor units', () => {
    expect(toMinor('25')).toBe(2500);
    expect(toMinor('25.5')).toBe(2550);
    expect(toMinor('19.99')).toBe(1999);
  });

  it('forgives what a phone keyboard produces', () => {
    // A comma from a phone whose keypad writes cents after one, a pasted symbol.
    expect(toMinor('25,50', true)).toBe(2550);
    expect(toMinor('$25.00')).toBe(2500);
    expect(toMinor(' 40 ')).toBe(4000);
  });

  it('is exact, and asks about a third decimal rather than rounding it away', () => {
    // 1.005 * 100 is 100.49999… in floating point, and 1.005 is not a price.
    expect(toMinor('1.005', false)).toBeNull();
    expect(Number.isInteger(toMinor('0.1'))).toBe(true);
  });

  it('is nothing, not zero, when nothing number-like was typed', () => {
    expect(toMinor('')).toBeNull();
    expect(toMinor('.')).toBeNull();
    expect(toMinor('free')).toBeNull();
  });

  it('shows minor units back the way they are typed', () => {
    expect(toMajor(2500, false)).toBe('25.00');
    expect(toMajor(5, false)).toBe('0.05');
    expect(toMajor(null)).toBe('');
  });
});

@Component({
  imports: [MfStepper],
  template: `<mf-stepper label="Per order" [min]="1" [max]="3" [(value)]="count" />`,
})
class StepperHost {
  readonly count = signal(2);
}

describe('MfStepper', () => {
  async function mount() {
    const fixture = TestBed.createComponent(StepperHost);
    fixture.autoDetectChanges();
    await fixture.whenStable();

    const root: HTMLElement = fixture.nativeElement;
    const [fewer, more] = Array.from(root.querySelectorAll<HTMLButtonElement>('.step'));

    return { fixture, host: fixture.componentInstance, fewer, more };
  }

  it('stops at the top rather than wrapping round', async () => {
    const { fixture, host, more } = await mount();

    more.click();
    await fixture.whenStable();
    expect(host.count()).toBe(3);
    expect(more.disabled).toBe(true);

    more.click();
    await fixture.whenStable();
    expect(host.count()).toBe(3);
  });

  it('stops at the bottom too', async () => {
    const { fixture, host, fewer } = await mount();

    fewer.click();
    fewer.click();
    await fixture.whenStable();

    expect(host.count()).toBe(1);
    expect(fewer.disabled).toBe(true);
  });

  it('names both buttons for a screen reader', async () => {
    const { fewer, more } = await mount();

    expect(fewer.getAttribute('aria-label')).toBe('Fewer: Per order');
    expect(more.getAttribute('aria-label')).toBe('More: Per order');
  });
});

const TIERS: MfOption[] = [
  { value: 'early', label: 'Early Bird' },
  { value: 'general', label: 'General' },
  { value: 'vip', label: 'VIP' },
];

@Component({
  imports: [MfMultiSelect],
  template: `<mf-multi-select heading="Ticket types" emptyLabel="Every ticket type" [options]="tiers" [(value)]="chosen" />`,
})
class MultiHost {
  readonly tiers = TIERS;
  readonly chosen = signal<string[]>(['early']);
}

describe('MfMultiSelect', () => {
  async function mount() {
    const fixture = TestBed.createComponent(MultiHost);
    fixture.autoDetectChanges();
    await fixture.whenStable();

    const root: HTMLElement = fixture.nativeElement;

    return {
      fixture,
      host: fixture.componentInstance,
      trigger: () => root.querySelector<HTMLButtonElement>('.trigger')!,
      option: (label: string) =>
        Array.from(root.querySelectorAll<HTMLButtonElement>('.option')).find((o) => o.textContent?.includes(label))!,
      button: (label: string) =>
        Array.from(root.querySelectorAll<HTMLButtonElement>('button')).find((b) => b.textContent?.trim() === label)!,
      settle: () => fixture.whenStable(),
    };
  }

  it('says what is chosen on the closed trigger, and what nothing means', async () => {
    const ui = await mount();

    expect(ui.trigger().textContent).toContain('Early Bird');

    ui.host.chosen.set([]);
    await ui.settle();
    expect(ui.trigger().textContent).toContain('Every ticket type');
  });

  it('commits only on Done', async () => {
    const ui = await mount();

    ui.trigger().click();
    await ui.settle();

    ui.option('VIP').click();
    await ui.settle();
    // Ticked in the sheet, not yet on the form.
    expect(ui.host.chosen()).toEqual(['early']);

    ui.button('Done').click();
    await ui.settle();
    expect(ui.host.chosen()).toEqual(['early', 'vip']);
  });

  it('leaves the form alone when the sheet is dismissed instead', async () => {
    const ui = await mount();

    ui.trigger().click();
    await ui.settle();

    ui.option('General').click();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await ui.settle();

    expect(ui.host.chosen()).toEqual(['early']);
  });
});

describe('Dialogs', () => {
  const dialogs = () => TestBed.inject(Dialogs);

  it('answers a confirm with what was pressed', async () => {
    const asked = dialogs().confirm({ title: 'Refund?', confirm: 'Refund' });
    dialogs().settle(true);

    expect(await asked).toBe(true);
  });

  it('answers no when the sheet is dismissed any other way', async () => {
    const asked = dialogs().confirm({ title: 'Refund?', confirm: 'Refund' });
    dialogs().settle(null);

    expect(await asked).toBe(false);
  });

  it('answers the earlier question no when a second one is asked', async () => {
    const first = dialogs().confirm({ title: 'First?', confirm: 'Yes' });
    const second = dialogs().menu({ actions: [{ key: 'edit', label: 'Edit' }] });

    expect(await first).toBe(false);

    dialogs().settle('edit');
    expect(await second).toBe('edit');
  });

  it('hands a prompt back what was typed, or nothing', async () => {
    const typed = dialogs().prompt({ title: 'Why?', label: 'Reason', confirm: 'Send' });
    dialogs().settle('Could not make it');
    expect(await typed).toBe('Could not make it');

    const dropped = dialogs().prompt({ title: 'Why?', label: 'Reason', confirm: 'Send' });
    dialogs().settle(null);
    expect(await dropped).toBeNull();
  });
});
