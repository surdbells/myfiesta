import { Injectable, computed, signal } from '@angular/core';
import { Capacitor } from '@capacitor/core';
import { Keyboard } from '@capacitor/keyboard';

/**
 * The state the bars around a screen answer to.
 *
 * Three things decide whether the bottom bar is there, and none of them belongs
 * to one screen: whether the keyboard is up (a bar floating over the field
 * somebody is typing in), whether the screen is being scrolled down through
 * content (the bar tucks away and comes back on the first scroll up), and what
 * each tab has waiting (a badge). Screens report; the bars read.
 *
 * The keyboard's height is kept too. The WebView is told not to resize for it
 * (capacitor.config), so anything pinned to the bottom — a Save bar, a sheet's
 * actions — has to lift itself by exactly this much or be typed under.
 */
@Injectable({ providedIn: 'root' })
export class Chrome {
  /** The on-screen keyboard's height in CSS pixels. Zero when it is down. */
  readonly keyboardHeight = signal(0);

  readonly keyboard = computed(() => this.keyboardHeight() > 0);

  /** Scrolled down through a long screen: the bottom bar steps aside. */
  readonly tucked = signal(false);

  /**
   * A task is open — a form, a flow with a Save at the end. The bottom bar
   * goes away for it: three tabs under a Save button are an invitation to
   * leave halfway.
   */
  readonly task = signal(false);

  /** Bumped when the tab already open is tapped again: that screen goes to its top. */
  readonly scrollTopRequested = signal(0);

  /** Counts on tabs, by link. Zero is no badge. */
  private readonly counts = signal<Record<string, number>>({});

  readonly badges = this.counts.asReadonly();

  /** What the bottom bar actually does right now. */
  readonly barHidden = computed(() => this.keyboard() || this.task());
  readonly barTucked = computed(() => !this.barHidden() && this.tucked());

  private lastTop = 0;

  constructor() {
    this.watchKeyboard();
  }

  setBadge(link: string, count: number): void {
    this.counts.update((all) => ({ ...all, [link]: Math.max(0, count) }));
  }

  /**
   * A screen reporting where it is scrolled to.
   *
   * Tucks after a deliberate scroll down past the first screenful and comes
   * back on any scroll up — the same rule the phone's own apps use, which is
   * why it feels right rather than jumpy. Near the top the bar is always there.
   */
  reportScroll(top: number): void {
    const delta = top - this.lastTop;
    this.lastTop = top;

    if (top < 80) this.tucked.set(false);
    else if (delta > 6) this.tucked.set(true);
    else if (delta < -6) this.tucked.set(false);
  }

  /** A new screen starts with the bar out. */
  reset(): void {
    this.lastTop = 0;
    this.tucked.set(false);
  }

  private watchKeyboard(): void {
    if (Capacitor.isNativePlatform()) {
      void Keyboard.addListener('keyboardWillShow', ({ keyboardHeight }) => this.keyboardHeight.set(keyboardHeight));
      void Keyboard.addListener('keyboardWillHide', () => this.keyboardHeight.set(0));

      return;
    }

    // In a browser there is no event, but the visual viewport shrinks by the
    // keyboard's height. A fifth of the screen is a keyboard; less is a
    // toolbar appearing.
    const viewport = typeof window !== 'undefined' ? window.visualViewport : null;

    if (!viewport) return;

    viewport.addEventListener('resize', () => {
      const covered = window.innerHeight - viewport.height;

      this.keyboardHeight.set(covered > window.innerHeight * 0.2 ? covered : 0);
    });
  }
}
