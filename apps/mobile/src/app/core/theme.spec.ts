import { TestBed } from '@angular/core/testing';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Theme } from './theme';

/**
 * Light, dark, and the phone's own setting.
 *
 * The rule under test is the one the web apps also follow: "system" stamps
 * nothing, so the media query decides; an explicit choice stamps the
 * attribute, so it beats the phone.
 */
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

vi.mock('@capacitor/status-bar', () => ({
  StatusBar: { setStyle: async () => undefined },
  Style: { Dark: 'DARK', Light: 'LIGHT' },
}));

afterEach(() => document.documentElement.removeAttribute('data-theme'));

describe('Theme', () => {
  it('stamps nothing for system, so the phone decides', async () => {
    const theme = TestBed.inject(Theme);

    await theme.set('dark');
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');

    await theme.set('system');
    expect(document.documentElement.hasAttribute('data-theme')).toBe(false);
    expect(theme.choice()).toBe('system');
  });

  it('stamps an explicit choice, which beats the phone either way', async () => {
    const theme = TestBed.inject(Theme);

    await theme.set('light');

    expect(document.documentElement.getAttribute('data-theme')).toBe('light');
    expect(theme.resolved()).toBe('light');
  });

  it('remembers the choice across a restart', async () => {
    const theme = TestBed.inject(Theme);
    await theme.set('dark');

    // A fresh injector, as if the app had been closed and opened again.
    TestBed.resetTestingModule();
    const next = TestBed.inject(Theme);
    expect(next.choice()).toBe('system');

    await next.restore();

    expect(next.choice()).toBe('dark');
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
  });
});
