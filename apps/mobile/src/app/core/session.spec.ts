import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { SessionStore } from './session';
import { Api } from './api';

vi.mock('@capacitor/preferences', () => {
  const store = new Map<string, string>();

  return {
    Preferences: {
      get: async ({ key }: { key: string }) => ({ value: store.get(key) ?? null }),
      set: async ({ key, value }: { key: string; value: string }) => void store.set(key, value),
      remove: async ({ key }: { key: string }) => void store.delete(key),
    },
  };
});

/**
 * What the session says the app may show.
 *
 * The scope comes from the abilities the server granted. Nothing here is a
 * security boundary — the API refuses what a token may not do — but getting it
 * wrong puts sales figures in front of somebody hired for one night.
 */
describe('SessionStore', () => {
  let session: SessionStore;

  beforeEach(async () => {
    TestBed.resetTestingModule();
    session = TestBed.inject(SessionStore);
    await session.clear();
  });

  it('reads the scope from the abilities, with organizer winning over attendee', async () => {
    await session.startFromLogin({
      token: 'tok',
      abilities: ['attendee', 'organizer'],
      user: { name: 'Ada Okoro', email: 'ada@example.test' },
      organizations: [{ id: 'org-1', name: 'Lagos Nights', role: 'owner', permissions: [] }],
    });

    expect(session.scope()).toBe('organizer');
    expect(session.canSeeSales()).toBe(true);
    expect(session.locked()).toBe(false);
    expect(session.organization()?.name).toBe('Lagos Nights');
    expect(TestBed.inject(Api).token).toBe('tok');
  });

  it('gives a ticket holder no sales screens', async () => {
    await session.startFromLogin({
      token: 'tok',
      abilities: ['attendee'],
      user: { name: 'Bisi' },
      organizations: [],
    });

    expect(session.scope()).toBe('attendee');
    expect(session.canSeeSales()).toBe(false);
  });

  it('locks a phone that opened a door pass to that one event', async () => {
    await session.startFromDoorPass({
      token: 'door-tok',
      label: 'Front gate',
      expires_at: new Date(Date.now() + 3_600_000).toISOString(),
      event: { id: 'evt-1', title: 'Afro Fest' },
    });

    expect(session.scope()).toBe('door');
    expect(session.locked()).toBe(true);
    expect(session.canSeeSales()).toBe(false);
    expect(session.session()?.eventId).toBe('evt-1');
  });

  it('comes back after a restart, and forgets a pass whose night is over', async () => {
    await session.startFromDoorPass({
      token: 'door-tok',
      label: 'Front gate',
      expires_at: new Date(Date.now() + 3_600_000).toISOString(),
      event: { id: 'evt-1', title: 'Afro Fest' },
    });

    TestBed.resetTestingModule();
    const restored = TestBed.inject(SessionStore);
    await restored.restore();
    expect(restored.locked()).toBe(true);

    // The same phone, the next morning.
    await restored.startFromDoorPass({
      token: 'door-tok',
      label: 'Front gate',
      expires_at: new Date(Date.now() - 1_000).toISOString(),
      event: { id: 'evt-1', title: 'Afro Fest' },
    });

    TestBed.resetTestingModule();
    const stale = TestBed.inject(SessionStore);
    await stale.restore();

    // A scanner that refuses everything with no explanation is worse than a
    // sign-in screen.
    expect(stale.signedIn()).toBe(false);
  });

  it('takes in an address changed from somewhere else, and keeps it after a restart', async () => {
    await session.startFromLogin({
      token: 'tok',
      abilities: ['attendee'],
      user: { name: 'Ada Okoro', email: 'ada@example.test' },
      organizations: [],
    });

    // The link to the new address was opened on the laptop.
    await session.identify('Ada Okoro', 'ada@new.example.test');
    expect(session.session()?.email).toBe('ada@new.example.test');

    TestBed.resetTestingModule();
    const restored = TestBed.inject(SessionStore);
    await restored.restore();
    expect(restored.session()?.email).toBe('ada@new.example.test');
    expect(restored.session()?.token).toBe('tok');
  });

  it('never renames a door pass after the account that made it', async () => {
    await session.startFromDoorPass({
      token: 'door-tok',
      label: 'Front gate',
      expires_at: new Date(Date.now() + 3_600_000).toISOString(),
      event: { id: 'evt-1', title: 'Afro Fest' },
    });

    await session.identify('Ada Okoro', 'ada@example.test');

    expect(session.session()?.name).toBe('Front gate');
    expect(session.session()?.email).toBeNull();
  });

  it('keeps the photo and the zone signing in sent, for the first home screen', async () => {
    await session.startFromLogin({
      token: 'tok',
      abilities: ['attendee'],
      user: { name: 'Ada Okoro', email: 'ada@example.test', avatar_url: 'https://media.test/avatars/1/a.jpg', timezone: 'America/Vancouver' },
      organizations: [],
    });

    expect(session.session()?.avatarUrl).toBe('https://media.test/avatars/1/a.jpg');
    expect(session.session()?.timezone).toBe('America/Vancouver');

    // A session from before either existed: initials, and the phone's zone.
    await session.startFromLogin({ token: 'tok', abilities: ['attendee'], user: { name: 'Bisi' }, organizations: [] });

    expect(session.session()?.avatarUrl).toBeNull();
    expect(session.session()?.timezone).toBeNull();
  });

  it('takes in a photo and a zone changed elsewhere, leaving out what was not said', async () => {
    await session.startFromLogin({
      token: 'tok',
      abilities: ['attendee'],
      user: { name: 'Ada Okoro', email: 'ada@example.test', avatar_url: null, timezone: null },
      organizations: [],
    });

    await session.adoptAccount({ avatar_url: 'https://media.test/avatars/1/b.jpg', timezone: 'America/Halifax' });
    await session.adoptAccount({ name: 'Ada O.' });

    expect(session.session()).toMatchObject({
      name: 'Ada O.',
      email: 'ada@example.test',
      avatarUrl: 'https://media.test/avatars/1/b.jpg',
      timezone: 'America/Halifax',
    });

    TestBed.resetTestingModule();
    const restored = TestBed.inject(SessionStore);
    await restored.restore();
    expect(restored.session()?.avatarUrl).toBe('https://media.test/avatars/1/b.jpg');
  });

  it('asks the server who this is at most once a minute, and never for a door pass', async () => {
    const api = TestBed.inject(Api);
    const me = vi.spyOn(api, 'me').mockResolvedValue({
      name: 'Ada Okoro',
      email: 'ada@example.test',
      email_verified: true,
      phone: null,
      timezone: 'America/Toronto',
      avatar_url: 'https://media.test/avatars/1/c.jpg',
      organizations: [],
    });

    await session.startFromLogin({ token: 'tok', abilities: ['attendee'], user: { name: 'Ada Okoro', email: 'ada@example.test' }, organizations: [] });

    await Promise.all([session.refreshAccount(), session.refreshAccount()]);
    await session.refreshAccount();

    expect(me).toHaveBeenCalledTimes(1);
    expect(session.session()?.avatarUrl).toBe('https://media.test/avatars/1/c.jpg');
    expect(session.session()?.timezone).toBe('America/Toronto');

    TestBed.resetTestingModule();
    const door = TestBed.inject(SessionStore);
    const asked = vi.spyOn(TestBed.inject(Api), 'me');
    await door.startFromDoorPass({
      token: 'door-tok',
      label: 'Front gate',
      expires_at: new Date(Date.now() + 3_600_000).toISOString(),
      event: { id: 'evt-1', title: 'Afro Fest' },
    });

    await door.refreshAccount();
    await door.adoptAccount({ avatar_url: 'https://media.test/avatars/1/c.jpg' });

    expect(asked).not.toHaveBeenCalled();
    expect(door.session()?.avatarUrl).toBeUndefined();
  });

  it('keeps what it had when the server cannot be asked', async () => {
    vi.spyOn(TestBed.inject(Api), 'me').mockRejectedValue(new Error('offline'));

    await session.startFromLogin({
      token: 'tok',
      abilities: ['attendee'],
      user: { name: 'Ada Okoro', email: 'ada@example.test', avatar_url: 'https://media.test/avatars/1/a.jpg', timezone: null },
      organizations: [],
    });

    await session.refreshAccount();

    expect(session.session()?.avatarUrl).toBe('https://media.test/avatars/1/a.jpg');
  });
});
