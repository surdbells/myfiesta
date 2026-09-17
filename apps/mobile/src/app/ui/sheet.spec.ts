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

@Component({
  imports: [MfSheet],
  template: `
    <button id="opener" (click)="open.set(true)">Open</button>
    <button id="behind">Something else on the page</button>

    <mf-sheet [open]="open()" heading="Pick one" (closed)="open.set(false)">
      <button id="first">First</button>
      <button id="last">Last</button>
    </mf-sheet>
  `,
})
class HostWithPage {
  readonly open = signal(false);
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

/**
 * Modality the sheet actually keeps.
 *
 * `aria-modal` tells a screen reader the rest of the page is not there; it
 * tells a keyboard nothing. Before this, two presses of Tab walked out of an
 * open sheet and into the page behind it, and closing left focus wherever
 * that had wandered to.
 */
describe('MfSheet and the keyboard', () => {
  async function open() {
    const fixture = TestBed.createComponent(HostWithPage);
    fixture.autoDetectChanges();
    await fixture.whenStable();

    const root: HTMLElement = fixture.nativeElement;
    document.body.appendChild(root);

    root.querySelector<HTMLElement>('#opener')!.focus();
    root.querySelector<HTMLElement>('#opener')!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    await new Promise((resolve) => requestAnimationFrame(() => resolve(null)));

    const tab = (shiftKey = false) =>
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey, bubbles: true, cancelable: true }));

    return { fixture, root, tab };
  }

  it('sends Tab from the last thing back to the first', async () => {
    const ui = await open();

    ui.root.querySelector<HTMLElement>('#last')!.focus();
    ui.tab();

    expect(document.activeElement?.id).toBe('first');
  });

  it('sends Shift+Tab from the first thing to the last', async () => {
    const ui = await open();

    ui.root.querySelector<HTMLElement>('#first')!.focus();
    ui.tab(true);

    expect(document.activeElement?.id).toBe('last');
  });

  it('pulls focus back in when it is somewhere on the page behind', async () => {
    const ui = await open();

    // However focus got out there — a click, a browser quirk — Tab brings it
    // back rather than walking further away.
    ui.root.querySelector<HTMLElement>('#behind')!.focus();
    ui.tab();

    expect(document.activeElement?.id).toBe('first');
  });

  it('gives focus back to whatever opened it', async () => {
    const ui = await open();

    ui.root.querySelector<HTMLElement>('#first')!.focus();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    ui.fixture.detectChanges();
    await ui.fixture.whenStable();

    // Not the top of the screen: back where the person was.
    expect(document.activeElement?.id).toBe('opener');
  });
});
