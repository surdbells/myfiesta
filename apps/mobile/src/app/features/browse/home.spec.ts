import { computed, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Discover } from '../../core/discovery';
import { Clock } from '../../core/greeting';
import { SessionStore, type Session } from '../../core/session';
import { Home } from './home';

/**
 * The profile chip over "What's on".
 *
 * The home screen used to say "Evening, Ada" in its subtitle, from the
 * phone's clock. Now an account gets a chip above the title — its photo, or
 * its initials, "Good evening" in the zone saved on the account, and the
 * first name — and tapping it opens Settings. Signed out, or on a door pass,
 * there is nobody to greet by name: the subtitle keeps a plain greeting in the
 * phone's own zone.
 */
describe('Home, the profile chip', () => {
  let state: ReturnType<typeof signal<Session | null>>;
  let now: ReturnType<typeof signal<Date>>;
  let refreshAccount: ReturnType<typeof vi.fn>;

  const ada = (over: Partial<Session> = {}): Session => ({
    scope: 'attendee',
    token: 't',
    name: 'Ada Okafor',
    email: 'ada@example.test',
    organizations: [],
    avatarUrl: null,
    timezone: 'America/Toronto',
    ...over,
  });

  beforeEach(() => {
    state = signal<Session | null>(ada());
    // 23:00 UTC: seven in the evening in Toronto, eight the next morning in Tokyo.
    now = signal(new Date('2026-07-15T23:00:00Z'));
    refreshAccount = vi.fn(async () => undefined);

    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: '', children: [] },
          { path: 'settings', children: [] },
        ]),
        {
          provide: Discover,
          useValue: {
            home: async () => ({ featured: [], upcoming: [], cities: [] }),
            past: async () => [],
            saved: async () => [],
          },
        },
        {
          provide: SessionStore,
          useValue: {
            session: state,
            signedIn: computed(() => state() !== null),
            locked: computed(() => state()?.scope === 'door'),
            refreshAccount,
          },
        },
        { provide: Clock, useValue: { now } },
      ],
    });
  });

  async function open() {
    const fixture = TestBed.createComponent(Home);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    return fixture;
  }

  const chip = (root: HTMLElement) => root.querySelector<HTMLButtonElement>('mf-profile-chip .chip');
  const subtitle = (root: HTMLElement) => root.querySelector('.hero-title .sub')?.textContent?.trim() ?? null;

  it('greets an account by first name, with its initials when there is no photo', async () => {
    const root = (await open()).nativeElement as HTMLElement;

    expect(chip(root)?.querySelector('.hello')?.textContent?.trim()).toBe('Good evening');
    expect(chip(root)?.querySelector('.name')?.textContent?.trim()).toBe('Ada');
    expect(chip(root)?.querySelector('mf-avatar .initials')?.textContent?.trim()).toBe('AO');
    expect(chip(root)?.querySelector('mf-avatar img')).toBeNull();
    // Said once, above the title, rather than again underneath it.
    expect(subtitle(root)).toBeNull();
    // Over "What's on", not somewhere in the list below it.
    expect(root.querySelector('.hero-title mf-profile-chip + h1')?.textContent).toContain('What’s on');
    // The photo and the zone as they are now, asked for quietly.
    expect(refreshAccount).toHaveBeenCalledTimes(1);
  });

  it('shows the photo when the account has one', async () => {
    state.set(ada({ avatarUrl: 'https://media.test/avatars/1/a.jpg' }));
    const root = (await open()).nativeElement as HTMLElement;

    expect(chip(root)?.querySelector('mf-avatar img')?.getAttribute('src')).toBe('https://media.test/avatars/1/a.jpg');
    expect(chip(root)?.querySelector('mf-avatar .initials')).toBeNull();
  });

  it('says the time of day where the account is, and the phone’s when it has no zone', async () => {
    state.set(ada({ timezone: 'Asia/Tokyo' }));
    const fixture = await open();
    const root = fixture.nativeElement as HTMLElement;

    expect(chip(root)?.querySelector('.hello')?.textContent?.trim()).toBe('Good morning');

    // No zone on the account: the phone's, which is UTC in these specs.
    state.set(ada({ timezone: null }));
    fixture.detectChanges();
    expect(chip(root)?.querySelector('.hello')?.textContent?.trim()).toBe('Good evening');

    // The clock moves on: 13:00 UTC is nine in the morning in Toronto.
    state.set(ada());
    now.set(new Date('2026-07-15T13:00:00Z'));
    fixture.detectChanges();
    expect(chip(root)?.querySelector('.hello')?.textContent?.trim()).toBe('Good morning');
  });

  it('opens Settings when tapped', async () => {
    const fixture = await open();

    chip(fixture.nativeElement as HTMLElement)!.click();
    await fixture.whenStable();

    expect(TestBed.inject(Router).url).toBe('/settings');
  });

  it('greets nobody by name when signed out, in the phone’s zone', async () => {
    state.set(null);
    const root = (await open()).nativeElement as HTMLElement;

    expect(chip(root)).toBeNull();
    // 23:00 in UTC, the phone's zone in these specs.
    expect(subtitle(root)).toBe('Good evening');
  });

  it('has no chip on a door pass, which is a label for the night rather than a person', async () => {
    state.set(ada({ scope: 'door', name: 'Front gate', email: null, timezone: undefined }));
    const root = (await open()).nativeElement as HTMLElement;

    expect(chip(root)).toBeNull();
    expect(subtitle(root)).toBe('Good evening');
    expect(root.textContent).not.toContain('Front');
  });
});
