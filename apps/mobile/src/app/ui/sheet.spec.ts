import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { MfSheet } from './sheet';
import { SheetStack } from './sheet-stack';

@Component({
  imports: [MfSheet],
  template: `
    <mf-sheet [open]="open()" heading="Sign out?" (closed)="closed.set($event); open.set(false)">
      <p>Body</p>
    </mf-sheet>
  `,
})
class Host {
  readonly open = signal(false);
  readonly closed = signal<string | null>(null);
}

async function mount() {
  const fixture = TestBed.createComponent(Host);
  fixture.autoDetectChanges();
  await fixture.whenStable();

  const root: HTMLElement = fixture.nativeElement;

  return {
    fixture,
    host: fixture.componentInstance,
    panel: () => root.querySelector('.panel'),
    scrim: () => root.querySelector<HTMLElement>('.scrim'),
    settle: () => fixture.whenStable(),
  };
}

describe('MfSheet', () => {
  it('keeps its content out of the DOM until it opens', async () => {
    const ui = await mount();

    expect(ui.panel()).toBeNull();

    ui.host.open.set(true);
    await ui.settle();

    expect(ui.panel()).not.toBeNull();
    expect(ui.panel()?.getAttribute('aria-modal')).toBe('true');
  });

  it('asks to close on the scrim rather than closing itself', async () => {
    const ui = await mount();

    ui.host.open.set(true);
    await ui.settle();

    ui.scrim()!.click();
    await ui.settle();

    // The owner holds the flag: a sheet that hid itself while its owner still
    // believed it was open is a picker that cannot be reopened.
    expect(ui.host.closed()).toBe('backdrop');
  });

  it('stops the page behind it from scrolling, and lets it go again', async () => {
    const ui = await mount();

    ui.host.open.set(true);
    await ui.settle();
    expect(document.body.style.overflow).toBe('hidden');

    ui.host.open.set(false);
    await ui.settle();
    expect(document.body.style.overflow).toBe('');
  });

  it('is what back closes first, newest first', async () => {
    const stack = TestBed.inject(SheetStack);
    const closed: string[] = [];

    stack.push(() => closed.push('first'));
    stack.push(() => closed.push('second'));

    expect(stack.dismissTop()).toBe(true);
    expect(stack.dismissTop()).toBe(true);
    expect(stack.dismissTop()).toBe(false);

    // The one opened last is the one back means.
    expect(closed).toEqual(['second', 'first']);
  });
});
