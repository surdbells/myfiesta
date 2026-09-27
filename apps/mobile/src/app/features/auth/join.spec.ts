import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const browsed = vi.hoisted(() => [] as string[]);

vi.mock('@capacitor/browser', () => ({
  Browser: {
    open: async ({ url }: { url: string }) => {
      browsed.push(url);
    },
  },
}));

import { Join } from './join';
import { Api } from '../../core/api';
import { Discover } from '../../core/discovery';
import { SessionStore } from '../../core/session';

/**
 * Signing up from the phone agrees to the terms, the privacy policy and the
 * refund policy — and only when the person ticks the box.
 *
 * The server refuses a sign-up without it. This screen says so before the
 * button does anything, puts the three pages a tap away in the browser, and
 * sends what the box actually says rather than a yes on somebody's behalf.
 */
describe('Join, agreeing to the terms', () => {
  let sent: unknown[][];

  beforeEach(() => {
    sent = [];
    browsed.length = 0;

    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'sign-in', children: [] }]),
        {
          provide: Api,
          useValue: {
            register: async (...args: unknown[]) => {
              sent.push(args);

              return { message: 'Check your email to finish setting up your account.', pending: true };
            },
          },
        },
        { provide: Discover, useValue: { siteBase: () => 'https://myfiesta.test' } },
        { provide: SessionStore, useValue: { startFromLogin: async () => ({ scope: 'attendee' }) } },
      ],
    });
  });

  async function open() {
    const fixture = TestBed.createComponent(Join);
    fixture.autoDetectChanges();
    await fixture.whenStable();

    const page = fixture.componentInstance;
    page.name.set('Ada Okafor');
    page.email.set('ada@example.com');
    page.password.set('correct horse 7');
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;

    return {
      fixture,
      page,
      root,
      box: () => root.querySelector<HTMLInputElement>('input[name="agreed"]')!,
      button: () => root.querySelector<HTMLButtonElement>('button[type="submit"]')!,
      link: (text: string) => Array.from(root.querySelectorAll('a')).find((a) => a.textContent?.trim() === text),
    };
  }

  it('starts unticked, and sends nothing until it is ticked', async () => {
    const { page, box, button } = await open();

    expect(box().checked).toBe(false);
    expect(page.ready()).toBe(false);
    expect(button().disabled).toBe(true);

    await page.submit();

    expect(sent).toEqual([]);
  });

  it('opens the three pages in the browser, and leaves the form as it was', async () => {
    const { link } = await open();

    for (const [text, path] of [
      ['terms', '/terms'],
      ['privacy policy', '/privacy'],
      ['refund policy', '/refunds'],
    ]) {
      const anchor = link(text);

      expect(anchor, `a link reading "${text}"`).toBeDefined();
      expect(anchor!.getAttribute('href')).toBe(`https://myfiesta.test${path}`);

      const click = new MouseEvent('click', { bubbles: true, cancelable: true });
      anchor!.dispatchEvent(click);

      // Handed to the browser, not followed inside the app's own page.
      expect(click.defaultPrevented).toBe(true);
    }

    expect(browsed).toEqual(['https://myfiesta.test/terms', 'https://myfiesta.test/privacy', 'https://myfiesta.test/refunds']);
  });

  it('sends the agreement with the sign-up, and then says to check the inbox', async () => {
    const { fixture, page, box, button, root } = await open();

    box().click();
    await fixture.whenStable();

    expect(page.agreed()).toBe(true);
    expect(button().disabled).toBe(false);

    await page.submit();
    await fixture.whenStable();

    expect(sent).toEqual([['Ada Okafor', 'ada@example.com', 'correct horse 7', null, true]]);
    expect(root.textContent).toContain('Check your email to finish setting up your account.');
  });
});
