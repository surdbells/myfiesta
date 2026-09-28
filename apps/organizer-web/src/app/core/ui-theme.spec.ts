import { PLATFORM_ID } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { THEME_STORAGE_KEY, ThemeStore, UiThemeToggle } from '@myfiesta/ui';

/**
 * Light, dark, or the device's.
 *
 * The palette itself is the tokens' job; what is pinned here is the part that
 * can go quietly wrong: the choice surviving a reload, "match device" really
 * handing the decision back (no attribute left behind), storage that refuses
 * not taking the page down with it, and a server render never touching the
 * document's theme at all.
 */

const root = () => document.documentElement;

describe('ThemeStore', () => {
  beforeEach(() => {
    localStorage.clear();
    root().removeAttribute('data-theme');
    TestBed.resetTestingModule();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    localStorage.clear();
    root().removeAttribute('data-theme');
  });

  it('follows the device until somebody chooses', () => {
    const store = TestBed.inject(ThemeStore);

    expect(store.mode()).toBe('system');
    expect(root().hasAttribute('data-theme')).toBe(false);
  });

  it('applies a choice at once and keeps it for the next visit', () => {
    TestBed.inject(ThemeStore).set('dark');

    expect(root().getAttribute('data-theme')).toBe('dark');
    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark');

    root().removeAttribute('data-theme');
    TestBed.resetTestingModule();

    const next = TestBed.inject(ThemeStore);
    expect(next.mode()).toBe('dark');
    expect(root().getAttribute('data-theme')).toBe('dark');
  });

  it('hands the decision back to the device, leaving nothing behind', () => {
    const store = TestBed.inject(ThemeStore);
    store.set('light');
    store.set('system');

    expect(root().hasAttribute('data-theme')).toBe(false);
    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBeNull();
  });

  it('ignores a stored value it does not recognise', () => {
    localStorage.setItem(THEME_STORAGE_KEY, 'sepia');

    expect(TestBed.inject(ThemeStore).mode()).toBe('system');
    expect(root().hasAttribute('data-theme')).toBe(false);
  });

  it('still applies a choice for the visit when storage refuses it', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError');
    });

    TestBed.inject(ThemeStore).set('dark');

    expect(root().getAttribute('data-theme')).toBe('dark');
  });

  it('does not read storage during a server render', () => {
    localStorage.setItem(THEME_STORAGE_KEY, 'dark');
    TestBed.configureTestingModule({ providers: [{ provide: PLATFORM_ID, useValue: 'server' }] });

    expect(TestBed.inject(ThemeStore).mode()).toBe('system');
    expect(root().hasAttribute('data-theme')).toBe(false);
  });
});

describe('UiThemeToggle', () => {
  beforeEach(() => {
    localStorage.clear();
    root().removeAttribute('data-theme');
    TestBed.resetTestingModule();
  });

  afterEach(() => {
    localStorage.clear();
    root().removeAttribute('data-theme');
  });

  it('offers three choices as a radio group, with the current one checked', () => {
    const fixture = TestBed.createComponent(UiThemeToggle);
    fixture.detectChanges();

    const group = fixture.nativeElement.querySelector('[role="radiogroup"]') as HTMLElement;
    const options = [...group.querySelectorAll<HTMLButtonElement>('[role="radio"]')];

    expect(group.getAttribute('aria-label')).toBe('Theme');
    expect(options.map((o) => o.getAttribute('aria-label'))).toEqual(['Match device', 'Light', 'Dark']);
    expect(options.map((o) => o.getAttribute('aria-checked'))).toEqual(['true', 'false', 'false']);
  });

  it('switches the page when one is pressed', () => {
    const fixture = TestBed.createComponent(UiThemeToggle);
    fixture.detectChanges();

    const dark = fixture.nativeElement.querySelector('[aria-label="Dark"]') as HTMLButtonElement;
    dark.click();
    fixture.detectChanges();

    expect(root().getAttribute('data-theme')).toBe('dark');
    expect(dark.getAttribute('aria-checked')).toBe('true');
  });
});
