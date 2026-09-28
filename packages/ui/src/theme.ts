import { DOCUMENT, isPlatformBrowser } from '@angular/common';
import { Component, Injectable, PLATFORM_ID, computed, inject, signal } from '@angular/core';
import { Monitor, Moon, Sun } from 'lucide-angular';
import { UiIcon, type LucideIconData } from './icon';

/** What somebody chose: follow the device, or one theme whatever it says. */
export type ThemeMode = 'system' | 'light' | 'dark';

/**
 * Where the choice is kept. theme-boot.js in each app's public folder reads
 * the same key before the first paint, so the two must never disagree.
 */
export const THEME_STORAGE_KEY = 'myfiesta.theme';

const MODES: readonly ThemeMode[] = ['system', 'light', 'dark'];

const isMode = (value: unknown): value is ThemeMode =>
  typeof value === 'string' && (MODES as readonly string[]).includes(value);

/**
 * Light, dark, or whatever the device says — for the two web apps.
 *
 * The palette itself is already in the tokens three ways (tokens.css): light
 * by default, dark under a dark OS setting unless the page says light, and
 * dark whenever the page says dark. So choosing a theme is nothing more than
 * setting `data-theme` on the root element, or removing it to follow the
 * device, and remembering which.
 *
 * The attribute is also set before Angular starts, by theme-boot.js, so a
 * page rendered on the server does not flash light and then turn dark. This
 * service takes over from there and keeps the two in step.
 *
 * Browser storage can be missing or refuse (private windows, blocked site
 * data), and a server render has none; every read and write is guarded, and a
 * choice that cannot be kept still applies for the visit.
 */
@Injectable({ providedIn: 'root' })
export class ThemeStore {
  private readonly document = inject(DOCUMENT);
  private readonly browser = isPlatformBrowser(inject(PLATFORM_ID));

  readonly mode = signal<ThemeMode>(this.read());

  constructor() {
    this.apply(this.mode());
  }

  set(mode: ThemeMode): void {
    this.mode.set(mode);
    this.apply(mode);
    this.write(mode);
  }

  private apply(mode: ThemeMode): void {
    const root = this.document.documentElement;

    if (mode === 'system') {
      root.removeAttribute('data-theme');
    } else {
      root.setAttribute('data-theme', mode);
    }
  }

  private read(): ThemeMode {
    if (!this.browser) return 'system';

    try {
      const stored = localStorage.getItem(THEME_STORAGE_KEY);
      return isMode(stored) ? stored : 'system';
    } catch {
      return 'system';
    }
  }

  private write(mode: ThemeMode): void {
    if (!this.browser) return;

    try {
      if (mode === 'system') {
        localStorage.removeItem(THEME_STORAGE_KEY);
      } else {
        localStorage.setItem(THEME_STORAGE_KEY, mode);
      }
    } catch {
      // Kept for this visit only; nothing else to do.
    }
  }
}

/**
 * The switch: three icons in one pill, the chosen one raised.
 *
 * A radio group rather than a cycling button, so a screen reader hears all
 * three choices and which is set, and nobody has to press twice to find out
 * what the button does.
 */
@Component({
  selector: 'ui-theme-toggle',
  imports: [UiIcon],
  template: `
    <div class="theme" role="radiogroup" aria-label="Theme">
      @for (option of options; track option.mode) {
        <button
          type="button"
          role="radio"
          class="theme__option"
          [class.is-on]="store.mode() === option.mode"
          [attr.aria-checked]="store.mode() === option.mode"
          [attr.aria-label]="option.label"
          [title]="option.label"
          (click)="store.set(option.mode)"
        >
          <ui-icon [icon]="option.icon" size="sm" />
        </button>
      }
    </div>
  `,
  styles: `
    .theme {
      display: inline-flex;
      gap: 2px;
      padding: 2px;
      border: 1px solid var(--border);
      border-radius: var(--radius-full);
      background-color: var(--surface-inset);
    }
    .theme__option {
      display: grid;
      place-items: center;
      width: 28px;
      height: 28px;
      padding: 0;
      border: 0;
      border-radius: var(--radius-full);
      background: transparent;
      color: var(--text-subtle);
      cursor: pointer;
      transition:
        background-color var(--motion-fast) var(--motion-ease),
        color var(--motion-fast) var(--motion-ease),
        box-shadow var(--motion-fast) var(--motion-ease);
    }
    .theme__option:hover {
      color: var(--text);
    }
    .theme__option.is-on {
      color: var(--text);
      background-color: var(--surface-raised);
      box-shadow: var(--shadow-card);
    }
  `,
})
export class UiThemeToggle {
  protected readonly store = inject(ThemeStore);

  protected readonly options: readonly { mode: ThemeMode; label: string; icon: LucideIconData }[] = [
    { mode: 'system', label: 'Match device', icon: Monitor },
    { mode: 'light', label: 'Light', icon: Sun },
    { mode: 'dark', label: 'Dark', icon: Moon },
  ];

  /** For a caller that wants to say which theme is in force. */
  readonly mode = computed(() => this.store.mode());
}
