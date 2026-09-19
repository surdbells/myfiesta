import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { Component } from '@angular/core';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { framingHeaders } from '../../framing';
import { EmbedMode, isEmbedded } from './embed';

@Component({ template: '' })
class Blank {}

/**
 * Tickets inside an organizer's own site.
 *
 * Three promises. A framed page keeps every step framed, so the buyer never
 * lands on our whole site inside a box on somebody else's. Only the framed
 * paths may be framed at all. And the script on the organizer's page listens
 * to our frames and nothing else.
 */
describe('EmbedMode', () => {
  let router: Router;
  let embed: EmbedMode;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideRouter([{ path: '**', component: Blank }])] });
    router = TestBed.inject(Router);
    embed = TestBed.inject(EmbedMode);
  });

  it('keeps the ordinary flow where it always was', async () => {
    await router.navigateByUrl('/afro-fest/tickets');

    expect(embed.active()).toBe(false);
    expect(embed.tickets('afro-fest')).toEqual(['/', 'afro-fest', 'tickets']);
    expect(embed.checkout('afro-fest')).toEqual(['/', 'afro-fest', 'checkout']);
    expect(embed.order('ABC123')).toEqual(['/order', 'ABC123']);
  });

  it('keeps every step inside the frame once framed', async () => {
    await router.navigateByUrl('/embed/afro-fest');

    expect(embed.active()).toBe(true);
    expect(embed.tickets('afro-fest')).toEqual(['/', 'embed', 'afro-fest']);
    expect(embed.checkout('afro-fest')).toEqual(['/', 'embed', 'afro-fest', 'checkout']);
    expect(embed.order('ABC123')).toEqual(['/', 'embed', 'order', 'ABC123']);
  });

  it('does not mistake an event that merely starts with the word', () => {
    expect(isEmbedded('/embedded-night')).toBe(false);
    expect(isEmbedded('/embed/afro-fest')).toBe(true);
  });

  it('says nothing to a parent when it is not framed', async () => {
    const spy = vi.spyOn(window, 'postMessage');
    await router.navigateByUrl('/embed/afro-fest');

    // In the test there is no parent — window.parent is window.
    embed.tell({ type: 'resize', height: 900 });

    expect(spy).not.toHaveBeenCalled();
  });
});

describe('framing', () => {
  it('lets any site frame the embedded checkout', () => {
    expect(framingHeaders('/embed/afro-fest')['Content-Security-Policy']).toBe('frame-ancestors *');
    expect(framingHeaders('/embed/order/ABC123')['Content-Security-Policy']).toBe('frame-ancestors *');
  });

  it('refuses every other page', () => {
    for (const path of ['/', '/afro-fest', '/afro-fest/checkout', '/tickets/abc', '/order/ABC123', '/embedded-night']) {
      expect(framingHeaders(path)['Content-Security-Policy']).toBe("frame-ancestors 'self'");
      expect(framingHeaders(path)['X-Frame-Options']).toBe('SAMEORIGIN');
    }
  });
});

describe('embed.js', () => {
  const ORIGIN = 'https://tickets.example';

  function load(): void {
    const script = document.createElement('script');
    script.src = `${ORIGIN}/embed.js`;
    Object.defineProperty(document, 'currentScript', { value: script, configurable: true });

    try {
      new Function(readFileSync(resolve(process.cwd(), 'public/embed.js'), 'utf8'))();
    } finally {
      delete (document as unknown as Record<string, unknown>)['currentScript'];
    }
  }

  function message(frame: HTMLIFrameElement, origin: string, data: unknown): void {
    window.dispatchEvent(new MessageEvent('message', { origin, data, source: frame.contentWindow }));
  }

  afterEach(() => (document.body.innerHTML = ''));

  it('puts the tickets in the page, sized by what the frame reports', () => {
    document.body.innerHTML = '<div id="t" data-myfiesta-event="afro-fest"></div>';
    load();

    const frame = document.querySelector<HTMLIFrameElement>('#t iframe')!;
    expect(frame.src).toBe(`${ORIGIN}/embed/afro-fest`);

    message(frame, ORIGIN, { source: 'myfiesta', type: 'resize', height: 1234 });
    expect(frame.style.height).toBe('1234px');
  });

  it('ignores anybody else claiming to be us', () => {
    document.body.innerHTML = '<div id="t" data-myfiesta-event="afro-fest"></div>';
    load();

    const frame = document.querySelector<HTMLIFrameElement>('#t iframe')!;
    message(frame, 'https://evil.example', { source: 'myfiesta', type: 'resize', height: 1 });

    expect(frame.style.height).toBe('');
  });

  it('tells the page a sale happened', () => {
    document.body.innerHTML = '<div id="t" data-myfiesta-event="afro-fest"></div>';
    load();

    const heard: unknown[] = [];
    document.addEventListener('myfiesta:paid', (e) => heard.push((e as CustomEvent).detail));

    const frame = document.querySelector<HTMLIFrameElement>('#t iframe')!;
    message(frame, ORIGIN, { source: 'myfiesta', type: 'paid', event: 'afro-fest', tickets: 2 });

    expect(heard).toEqual([{ event: 'afro-fest', tickets: 2 }]);
  });

  it('turns a link into a button that opens the tickets over the page, and closes on Escape', () => {
    document.body.innerHTML = `<a id="b" href="${ORIGIN}/afro-fest" data-myfiesta-event="afro-fest" data-myfiesta-mode="button">Buy</a>`;
    load();

    document.getElementById('b')!.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));

    expect(document.querySelector('[role="dialog"] iframe')).not.toBeNull();

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

    expect(document.querySelector('[role="dialog"]')).toBeNull();
  });
});
