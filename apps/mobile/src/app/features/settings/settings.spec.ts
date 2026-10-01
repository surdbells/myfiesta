import { computed, signal, type DebugElement } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
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
import { SessionStore, type Session } from '../../core/session';
import { Theme } from '../../core/theme';
import { Dialogs, ToastStore, type ConfirmRequest } from '../../ui';
import manifest from '../../../../package.json';
import { AVATAR_REFUSED, MfSettingsAvatar } from './avatar';
import { ProfileApi } from './profile-api';
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
            adoptAccount: async () => undefined,
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

/**
 * Signing out, and which build this is.
 *
 * "Sign out" opened a sheet asking "Sign out?", whose own Sign out button then
 * asked again in the confirmation every action uses — two questions, saying
 * two different things about the tickets. And the foot of the screen said
 * "myFiesta 2.0.0", typed by hand, while the stores had 18.0.0.
 */
describe('Settings, signing out and the version', () => {
  let asked: ConfirmRequest[];
  let yes: boolean;
  let signOut: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    asked = [];
    yes = true;
    signOut = vi.fn(async () => undefined);

    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'sign-in', children: [] },
          { path: 'welcome', children: [] },
        ]),
        { provide: Api, useValue: { me: async () => ({ name: 'Ada Okafor', email: 'ada@example.test', email_verified: true, phone: null, timezone: null, organizations: [] }) } },
        {
          provide: SessionStore,
          useValue: {
            session: signal({ scope: 'attendee', token: 't', name: 'Ada Okafor', email: 'ada@example.test', organizations: [] }),
            signedIn: () => true,
            locked: () => false,
            organization: () => null,
            adoptAccount: async () => undefined,
            signOut,
          },
        },
        {
          provide: Dialogs,
          useValue: {
            confirm: vi.fn(async (request: ConfirmRequest) => {
              asked.push(request);
              return yes;
            }),
          },
        },
        { provide: Theme, useValue: { choice: signal('system'), resolved: computed(() => 'light'), set: async () => undefined } },
        { provide: Reminders, useValue: { available: false, on: signal(false), refused: signal(false), set: async () => undefined } },
        { provide: Discover, useValue: { siteBase: () => 'https://myfiesta.test' } },
      ],
    });
  });

  async function open() {
    const fixture = TestBed.createComponent(Settings);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    return fixture;
  }

  const button = (root: HTMLElement, label: string) =>
    [...root.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.replace(/\s+/g, ' ').trim() === label);

  it('asks once, and signs out on the answer', async () => {
    const fixture = await open();
    const root = fixture.nativeElement as HTMLElement;

    button(root, 'Sign out')!.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(asked).toHaveLength(1);
    expect(asked[0].title).toBe('Sign out?');
    expect(asked[0].consequences?.join(' ')).toContain('Your tickets stay on your account.');
    expect(signOut).toHaveBeenCalledTimes(1);
    // No second question on the screen behind it.
    expect(root.textContent).not.toContain('Stay signed in');
  });

  it('stays signed in when the answer is no', async () => {
    yes = false;
    const fixture = await open();

    button(fixture.nativeElement as HTMLElement, 'Sign out')!.click();
    await fixture.whenStable();

    expect(asked).toHaveLength(1);
    expect(signOut).not.toHaveBeenCalled();
  });

  it('opens the introduction again, over Settings', async () => {
    const fixture = await open();

    button(fixture.nativeElement as HTMLElement, 'Show the introduction')!.click();
    await fixture.whenStable();

    expect(TestBed.inject(Router).url).toBe('/welcome');
  });

  it('says the version the stores have, from package.json', async () => {
    const fixture = await open();

    expect((fixture.nativeElement as HTMLElement).querySelector('.version')?.textContent?.trim()).toBe(`myFiesta ${manifest.version}`);
  });
});

/**
 * The photo at the top of "Signed in as", and the time zone in Your details.
 *
 * The photo is the face on the home screen's greeting. Choosing one uploads it
 * straight away, with how much has gone, because the phone's own picker was
 * the choice; removing one deletes the file, so it asks first. The zone is
 * what the greeting reads the time of day in — saved only when it was changed,
 * and never put back to "the phone's own" by a form that could not see it.
 */
describe('Settings, the photo and the time zone', () => {
  let asked: ConfirmRequest[];
  let yes: boolean;
  let state: ReturnType<typeof signal<Session | null>>;
  let adoptAccount: ReturnType<typeof vi.fn>;
  let uploadAvatar: ReturnType<typeof vi.fn>;
  let removeAvatar: ReturnType<typeof vi.fn>;
  let updateProfile: ReturnType<typeof vi.fn>;
  let me: ReturnType<typeof vi.fn>;

  const account = (over: Record<string, unknown> = {}) => ({
    name: 'Ada Okafor',
    email: 'ada@example.test',
    email_verified: true,
    phone: null,
    timezone: 'America/Vancouver',
    avatar_url: null,
    organizations: [],
    ...over,
  });

  beforeEach(() => {
    asked = [];
    yes = true;
    state = signal<Session | null>({
      scope: 'attendee',
      token: 't',
      name: 'Ada Okafor',
      email: 'ada@example.test',
      organizations: [],
      avatarUrl: null,
      timezone: 'America/Vancouver',
    });
    adoptAccount = vi.fn(async (changes: { avatar_url?: string | null; timezone?: string | null; name?: string }) => {
      const now = state();
      if (!now) return;
      state.set({
        ...now,
        ...(changes.name !== undefined ? { name: changes.name } : {}),
        ...(changes.avatar_url !== undefined ? { avatarUrl: changes.avatar_url } : {}),
        ...(changes.timezone !== undefined ? { timezone: changes.timezone } : {}),
      });
    });
    uploadAvatar = vi.fn(async () => ({ avatar_url: 'https://media.test/avatars/1/new.jpg' }));
    removeAvatar = vi.fn(async () => ({ avatar_url: null }));
    updateProfile = vi.fn(async (body: { name: string; timezone?: string | null }) => ({
      name: body.name,
      email: 'ada@example.test',
      phone: null,
      timezone: body.timezone === undefined ? 'America/Vancouver' : body.timezone,
    }));
    me = vi.fn(async () => account());

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: Api, useValue: { me, updateProfile } },
        { provide: ProfileApi, useValue: { uploadAvatar, removeAvatar } },
        {
          provide: SessionStore,
          useValue: {
            session: state,
            signedIn: computed(() => state() !== null),
            locked: computed(() => state()?.scope === 'door'),
            organization: () => null,
            adoptAccount,
          },
        },
        {
          provide: Dialogs,
          useValue: {
            // As the sheet does it: a yes runs the action before answering.
            confirm: vi.fn(async (request: ConfirmRequest) => {
              asked.push(request);
              if (yes) await request.run?.(undefined);

              return yes;
            }),
          },
        },
        { provide: Theme, useValue: { choice: signal('system'), resolved: computed(() => 'light'), set: async () => undefined } },
        { provide: Reminders, useValue: { available: false, on: signal(false), refused: signal(false), set: async () => undefined } },
        { provide: Discover, useValue: { siteBase: () => 'https://myfiesta.test' } },
      ],
    });
  });

  async function open() {
    const fixture = TestBed.createComponent(Settings);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    return fixture;
  }

  const button = (root: HTMLElement, label: string) =>
    [...root.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.replace(/\s+/g, ' ').trim() === label);

  const photoPart = (fixture: { debugElement: DebugElement }) =>
    fixture.debugElement.query(By.directive(MfSettingsAvatar)).componentInstance as MfSettingsAvatar;

  it('offers a photo with initials until there is one', async () => {
    const root = (await open()).nativeElement as HTMLElement;

    expect(root.querySelector('mf-settings-avatar mf-avatar .initials')?.textContent?.trim()).toBe('AO');
    expect(button(root, 'Add a photo')).toBeDefined();
    expect(button(root, 'Remove photo')).toBeUndefined();
  });

  it('uploads the chosen photo with its progress, and shows it', async () => {
    const fixture = await open();
    const root = fixture.nativeElement as HTMLElement;
    const input = root.querySelector<HTMLInputElement>('mf-settings-avatar input[type=file]')!;
    const opened = vi.spyOn(input, 'click').mockImplementation(() => undefined);

    // The phone's own picker: camera, library or Files, as the person likes.
    button(root, 'Add a photo')!.click();
    expect(opened).toHaveBeenCalled();
    // No HEIC: an iPhone converts to JPEG only for a picker that does not
    // ask for it, and the server cannot read HEIC.
    expect(input.accept).toBe('image/jpeg,image/png,image/webp');

    const seen: (number | null)[] = [];
    const part = photoPart(fixture);
    const file = new File([new Uint8Array(1024)], 'me.jpg', { type: 'image/jpeg' });
    uploadAvatar.mockImplementationOnce(async (_file: File, progress?: (percent: number | null) => void) => {
      progress?.(40);
      seen.push(part.progress());
      expect(part.uploading()).toBe(true);

      return { avatar_url: 'https://media.test/avatars/1/new.jpg' };
    });

    Object.defineProperty(input, 'files', { value: [file], configurable: true });
    input.dispatchEvent(new Event('change'));
    await fixture.whenStable();
    fixture.detectChanges();

    expect(uploadAvatar).toHaveBeenCalledWith(file, expect.any(Function));
    expect(seen).toEqual([40]);
    // The phone's own picker was the choice; nothing more is asked.
    expect(asked).toHaveLength(0);
    expect(adoptAccount).toHaveBeenCalledWith({ avatar_url: 'https://media.test/avatars/1/new.jpg' });
    expect(part.uploading()).toBe(false);
    expect(root.querySelector('mf-settings-avatar mf-avatar img')?.getAttribute('src')).toBe('https://media.test/avatars/1/new.jpg');
    expect(button(root, 'Change photo')).toBeDefined();
  });

  it('says a photo is too big before sending it', async () => {
    const fixture = await open();
    const big = new File([new Uint8Array(13 * 1024 * 1024)], 'huge.jpg', { type: 'image/jpeg' });

    await photoPart(fixture).upload(big);

    expect(uploadAvatar).not.toHaveBeenCalled();
    expect(TestBed.inject(ToastStore).toasts().map((t) => t.text)).toContain('That photo is 13.0 MB. The limit is 12 MB.');
  });

  describe('when the photo does not go', () => {
    const kept = 'https://media.test/avatars/1/a.jpg';
    const file = () => new File([new Uint8Array(1024)], 'IMG_0001.HEIC', { type: 'image/heic' });
    const toasts = () => TestBed.inject(ToastStore).toasts().map((t) => t.text);

    beforeEach(() => {
      state.update((s) => (s ? { ...s, avatarUrl: kept } : s));
      me.mockResolvedValue(account({ avatar_url: kept }));
    });

    it('says a refused file in plain words, and keeps the photo there was', async () => {
      uploadAvatar.mockRejectedValueOnce(
        new ApiError('The file field must be a file of type: jpeg, jpg, png, webp.', 422, {
          file: ['The file field must be a file of type: jpeg, jpg, png, webp.'],
        }),
      );
      const fixture = await open();
      const part = photoPart(fixture);

      await part.upload(file());
      fixture.detectChanges();

      expect(toasts()).toContain(AVATAR_REFUSED);
      expect(toasts().join(' ')).not.toContain('file field');
      expect(part.uploading()).toBe(false);
      expect(part.progress()).toBeNull();
      // Opening Settings adopts the account as the server has it; no photo
      // change is adopted after that.
      expect(adoptAccount).not.toHaveBeenCalledWith({ avatar_url: expect.anything() });
      expect(state()?.avatarUrl).toBe(kept);
      expect((fixture.nativeElement as HTMLElement).querySelector('mf-settings-avatar mf-avatar img')?.getAttribute('src')).toBe(kept);
    });

    it('passes on a sentence the server wrote for a person', async () => {
      uploadAvatar.mockRejectedValueOnce(new ApiError('That file is not an image we can read.', 422));
      const part = photoPart(await open());

      await part.upload(file());

      expect(toasts()).toContain('That file is not an image we can read.');
      expect(state()?.avatarUrl).toBe(kept);
    });

    it('says there was no connection, and can be tried again', async () => {
      uploadAvatar.mockRejectedValueOnce(new ApiError('No connection. Check signal and try again.', 0));
      const fixture = await open();
      const part = photoPart(fixture);

      await part.upload(file());
      fixture.detectChanges();

      expect(toasts()).toContain('No connection. Check signal and try again.');
      expect(part.uploading()).toBe(false);
      expect(state()?.avatarUrl).toBe(kept);
      expect(button(fixture.nativeElement as HTMLElement, 'Change photo')?.disabled).toBeFalsy();
    });

    it('keeps the photo when removing it fails, and says nothing was removed', async () => {
      const failures: string[] = [];
      // As the sheet does it: a failed action stays on screen with what went
      // wrong, until the person cancels.
      vi.mocked(TestBed.inject(Dialogs).confirm).mockImplementationOnce(async (options) => {
        const request = options as ConfirmRequest;
        asked.push(request);
        try {
          await request.run?.(undefined);
        } catch (error) {
          failures.push(error instanceof ApiError ? error.message : String(error));

          return false;
        }

        return true;
      });
      removeAvatar.mockRejectedValueOnce(new ApiError('No connection. Check signal and try again.', 0));
      const fixture = await open();

      button(fixture.nativeElement as HTMLElement, 'Remove photo')!.click();
      await fixture.whenStable();

      expect(removeAvatar).toHaveBeenCalledTimes(1);
      expect(failures).toEqual(['No connection. Check signal and try again.']);
      expect(adoptAccount).not.toHaveBeenCalledWith({ avatar_url: null });
      expect(state()?.avatarUrl).toBe(kept);
      expect(toasts()).not.toContain('Photo removed.');
    });
  });

  it('asks before removing the photo, and keeps it on no', async () => {
    state.update((s) => (s ? { ...s, avatarUrl: 'https://media.test/avatars/1/a.jpg' } : s));
    me.mockResolvedValue(account({ avatar_url: 'https://media.test/avatars/1/a.jpg' }));
    yes = false;
    const fixture = await open();
    const root = fixture.nativeElement as HTMLElement;

    button(root, 'Remove photo')!.click();
    await fixture.whenStable();

    expect(asked).toHaveLength(1);
    expect(asked[0].tone).toBe('danger');
    expect(asked[0].confirmLabel).toBe('Remove photo');
    expect(removeAvatar).not.toHaveBeenCalled();
    expect(state()?.avatarUrl).toBe('https://media.test/avatars/1/a.jpg');

    yes = true;
    button(root, 'Remove photo')!.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(removeAvatar).toHaveBeenCalledTimes(1);
    expect(state()?.avatarUrl).toBeNull();
    expect(root.querySelector('mf-settings-avatar mf-avatar .initials')?.textContent?.trim()).toBe('AO');
  });

  it('has no photo to change on a door pass', async () => {
    state.set({ scope: 'door', token: 't', name: 'Front gate', email: null, organizations: [] });
    const root = (await open()).nativeElement as HTMLElement;

    expect(root.querySelector('mf-settings-avatar .photo')).toBeNull();
  });

  it('opens Your details on the zone on file, and saves only a changed one', async () => {
    const fixture = await open();
    const page = fixture.componentInstance;

    await page.editDetails();
    expect(page.zone()).toBe('America/Vancouver');
    // The account's zone is in the list, and the phone's own comes first.
    expect(page.zones()[0]).toMatchObject({ value: '' });
    expect(page.zones().some((o) => o.value === 'America/Vancouver')).toBe(true);

    await page.saveDetails();
    expect(updateProfile).toHaveBeenLastCalledWith({ name: 'Ada Okafor' });

    await page.editDetails();
    page.zone.set('America/Halifax');
    await page.saveDetails();

    expect(updateProfile).toHaveBeenLastCalledWith({ name: 'Ada Okafor', timezone: 'America/Halifax' });
    expect(asked[asked.length - 1].consequences?.join(' ')).toContain('Your greeting follows Halifax');
    expect(adoptAccount).toHaveBeenLastCalledWith({ name: 'Ada Okafor', timezone: 'America/Halifax' });
  });

  it('puts the zone back to the phone’s own as null', async () => {
    const fixture = await open();
    const page = fixture.componentInstance;

    await page.editDetails();
    page.zone.set('');
    await page.saveDetails();

    expect(updateProfile).toHaveBeenLastCalledWith({ name: 'Ada Okafor', timezone: null });
    expect(asked[asked.length - 1].consequences?.join(' ')).toContain('follows this phone’s time zone');
  });

  it('never clears a zone it could not see', async () => {
    const fixture = await open();
    const page = fixture.componentInstance;
    state.update((s) => (s ? { ...s, timezone: null } : s));
    me.mockRejectedValue(new Error('offline'));

    await page.editDetails();
    expect(page.zone()).toBe('');
    await page.saveDetails();

    expect(updateProfile).toHaveBeenLastCalledWith({ name: 'Ada Okafor' });
  });
});
