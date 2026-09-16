import { Injectable, signal } from '@angular/core';

/**
 * Every sheet that is open, newest last.
 *
 * Android's back gesture and the hardware back button are one event for the
 * whole app, so something has to know what "back" means at that moment.
 * Without this, back at an open sheet leaves the app entirely — which on a
 * ticket screen looks like the app crashed.
 */
@Injectable({ providedIn: 'root' })
export class SheetStack {
  private readonly open = signal<Array<() => void>>([]);

  readonly depth = this.open.asReadonly();

  push(dismiss: () => void): void {
    this.open.update((all) => [...all, dismiss]);
  }

  remove(dismiss: () => void): void {
    this.open.update((all) => all.filter((entry) => entry !== dismiss));
  }

  /**
   * Close the topmost sheet. True when there was one, so back can be swallowed.
   *
   * The entry is dropped here rather than waiting for the sheet to finish
   * closing: two quick taps of back would otherwise both land on the same
   * sheet and leave the one underneath open.
   */
  dismissTop(): boolean {
    const all = this.open();
    const top = all[all.length - 1];

    if (!top) return false;

    this.remove(top);
    top();

    return true;
  }
}
