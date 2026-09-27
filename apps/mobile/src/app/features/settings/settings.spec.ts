import { computed, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import type { AccountErasurePreview, AccountErasureResult } from '@myfiesta/api-types';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const browsed = vi.hoisted(() => [] as string[]);

vi.mock('@capacitor/browser', () => ({
  Browser: {
    open: async ({ url }: { url: string }) => {
      browsed.push(url);
    },
  },
}));

import { Api, ApiError } from '../../core/api';
import { Discover } from '../../core/discovery';
import { Reminders } from '../../core/reminders';
import { SessionStore } from '../../core/session';
import { Theme } from '../../core/theme';
import { Settings } from './settings';

/**
 * "Delete my account", from the phone's settings.
 *
 * The App Store rejects an app that makes accounts and cannot start deleting
 * one. What is being protected here: that the screen says what goes and what
 * stays before the password is typed; that the only owner of an organization
 * is sent to hand it over, and never offered a button that would be refused;
 * and that the phone forgets a session whose account no longer exists — while
 * one still waiting for its emailed link stays signed in.
 */
describe('Settings, deleting the account', () => {
  const preview = (over: Partial<AccountErasurePreview> = {}): AccountErasurePreview => ({
    email: 'ada@example.test',
    email_verified: true,
    refused: null,
    organizations: [],
    kept_for_years: 7,
    history_kept: false,
    ...over,
  });

  let answer: AccountErasurePreview;
  let erase: ReturnType<typeof vi.fn<(password: string) => Promise<AccountErasureResult>>>;
  let clear: ReturnType<typeof vi.fn>;
  let chooseOrganization: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    browsed.length = 0;
    answer = preview();
    erase = vi.fn(async (_password: string): Promise<AccountErasureResult> => ({
      status: 'completed',
      message: 'Your account is deleted, and you are signed out everywhere.',
    }));
    clear = vi.fn(async () => undefined);
    chooseOrganization = vi.fn(async () => undefined);

    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: '', children: [] },
          { path: 'manage/team', children: [] },
        ]),
        {
          provide: Api,
          useValue: {
            me: async () => ({ name: 'Ada Okafor', email: 'ada@example.test', email_verified: true, phone: null, timezone: null, organizations: [] }),
            erasurePreview: async () => answer,
            eraseAccount: erase,
          },
        },
        {
          provide: SessionStore,
          useValue: {
            session: signal({ scope: 'organizer', token: 't', name: 'Ada Okafor', email: 'ada@example.test', organizations: [] }),
            signedIn: () => true,
            locked: () => false,
            organization: () => null,
            identify: async () => undefined,
            chooseOrganization,
            clear,
          },
        },
        { provide: Theme, useValue: { choice: signal('system'), resolved: computed(() => 'light'), set: async () => undefined } },
        { provide: Reminders, useValue: { available: false, on: signal(false), refused: signal(false), set: async () => undefined } },
        { provide: Discover, useValue: { siteBase: () => 'https://myfiesta.test' } },
      ],
    });
  });

  function screen(): Settings {
    return TestBed.runInInjectionContext(() => new Settings());
  }

  it('says who it would take off which team before asking for the password', async () => {
    answer = preview({
      organizations: [{ id: 'org-2', name: 'Toronto Afters', slug: 'toronto-afters', role: 'manager', only_owner: false }],
    });
    const page = screen();

    await page.startDelete();

    expect(page.deleteOpen()).toBe(true);
    expect(page.leaving()).toBe('Toronto Afters');
    expect(page.stranded()).toEqual([]);
    // Not until the password is typed.
    expect(page.deleteReady()).toBe(false);

    page.deletePassword.set('correct horse 7');
    expect(page.deleteReady()).toBe(true);
  });

  it('sends the only owner to hand the organization over, and never offers the button', async () => {
    answer = preview({
      refused: 'You are the only owner of Lagos Nights. Make somebody else an owner, or close the organization, and then ask again.',
      organizations: [{ id: 'org-1', name: 'Lagos Nights', slug: 'lagos-nights', role: 'owner', only_owner: true }],
    });
    const page = screen();

    await page.startDelete();
    page.deletePassword.set('correct horse 7');

    expect(page.stranded().map((o) => o.name)).toEqual(['Lagos Nights']);
    expect(page.deleteReady()).toBe(false);

    await page.deleteAccount();
    expect(erase).not.toHaveBeenCalled();

    // To that organization's team, with it chosen first.
    await page.handOver('org-1');
    expect(chooseOrganization).toHaveBeenCalledWith('org-1');
    expect(TestBed.inject(Router).url).toBe('/manage/team');

    // Closing it is done with us, from the site's contact page.
    page.writeToUs();
    expect(browsed).toEqual(['https://myfiesta.test/contact']);
  });

  it('refuses a staff account with no organization to hand over, and never offers the button', async () => {
    answer = preview({
      refused: 'This account has myFiesta staff access. Ask another administrator to remove your staff access first.',
      organizations: [],
      history_kept: true,
    });
    const page = screen();

    await page.startDelete();
    page.deletePassword.set('correct horse 7');

    // Nothing to hand over: the sheet shows the message and nothing else.
    expect(page.stranded()).toEqual([]);
    expect(page.deleteReady()).toBe(false);

    await page.deleteAccount();
    expect(erase).not.toHaveBeenCalled();
  });

  it('deletes on the password, then forgets the session with no sign-out to make', async () => {
    const page = screen();

    await page.startDelete();
    page.deletePassword.set('correct horse 7');
    await page.deleteAccount();

    expect(erase).toHaveBeenCalledWith('correct horse 7');
    // Every token went with the account; the phone only has to forget it.
    expect(clear).toHaveBeenCalled();
    expect(page.deleteOpen()).toBe(false);
    expect(page.deletePassword()).toBe('');
  });

  it('stays signed in while an unproved address waits for its link', async () => {
    answer = preview({ email_verified: false });
    erase.mockResolvedValueOnce({ status: 'pending', message: 'We sent a link to ada@example.test. Your account is deleted when you open it and confirm.' });
    const page = screen();

    await page.startDelete();
    page.deletePassword.set('correct horse 7');
    await page.deleteAccount();

    expect(page.deletePending()).toContain('We sent a link to ada@example.test');
    expect(page.deletePassword()).toBe('');
    expect(clear).not.toHaveBeenCalled();
  });

  it('puts a wrong password under the password box and keeps the account', async () => {
    erase.mockRejectedValueOnce(
      new ApiError('That is not your current password.', 422, { current_password: ['That is not your current password.'] }),
    );
    const page = screen();

    await page.startDelete();
    page.deletePassword.set('a guess');
    await page.deleteAccount();

    expect(page.err('current_password')).toBe('That is not your current password.');
    expect(clear).not.toHaveBeenCalled();
  });

  it('shows a refusal that arrived after the sheet opened, and changes nothing', async () => {
    erase.mockRejectedValueOnce(new ApiError('You are the only owner of Lagos Nights.', 409));
    const page = screen();

    await page.startDelete();
    page.deletePassword.set('correct horse 7');
    await page.deleteAccount();

    expect(page.erasure()?.refused).toBe('You are the only owner of Lagos Nights.');
    expect(page.deleteReady()).toBe(false);
    expect(clear).not.toHaveBeenCalled();
  });
});
