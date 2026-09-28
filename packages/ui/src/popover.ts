import { ElementRef, signal } from '@angular/core';

/**
 * A panel that opens under a button, above everything, and closes the way a
 * menu should: a click elsewhere, Escape, or choosing.
 *
 * Built on the browser's popover (popover="auto"), which gives light dismiss,
 * Escape and the top layer for nothing — so it is never cut off by a card's
 * overflow or a dialog, which is where a column menu or a saved-views list
 * usually sits. Placed against its button when it opens, and flipped above it
 * or held inside the window when there is no room.
 */
export class Popover {
  readonly open = signal(false);
  readonly position = signal<{ top: number; left: number }>({ top: 0, left: 0 });

  constructor(
    private readonly trigger: () => ElementRef<HTMLElement> | undefined,
    private readonly panel: () => ElementRef<HTMLElement> | undefined,
    private readonly width = 280,
  ) {}

  toggle(): void {
    this.open() ? this.hide() : this.show();
  }

  show(): void {
    const panel = this.panel()?.nativeElement as (HTMLElement & { showPopover?: () => void }) | undefined;
    const trigger = this.trigger()?.nativeElement;
    if (!panel || !trigger) return;

    const rect = trigger.getBoundingClientRect();
    const width = Math.min(this.width, innerWidth - 16);
    // Right-aligned to the button when there is no room to its right: these
    // buttons sit at the end of a toolbar.
    const left = rect.left + width > innerWidth - 8 ? Math.max(8, rect.right - width) : rect.left;

    this.position.set({ top: rect.bottom + 6, left });
    this.open.set(true);

    try {
      panel.showPopover?.();
    } catch {
      // Already showing.
    }
  }

  hide(): void {
    const panel = this.panel()?.nativeElement as (HTMLElement & { hidePopover?: () => void }) | undefined;
    this.open.set(false);

    try {
      panel?.hidePopover?.();
    } catch {
      // Already hidden by light dismiss.
    }
  }

  /** The browser closed it (a click outside, Escape): keep the signal honest. */
  onToggle(event: Event): void {
    const state = (event as Event & { newState?: string }).newState;
    if (state === 'closed') this.open.set(false);
  }
}

/** The panel's look, shared by every menu built on Popover. */
export const POPOVER_PANEL_STYLES = `
  .menu__panel {
    position: fixed;
    inset: auto;
    margin: 0;
    padding: var(--space-2);
    display: none;
    flex-direction: column;
    gap: var(--space-1);
    max-height: min(420px, 70vh);
    overflow-y: auto;
    color: var(--text);
    background: var(--surface-raised);
    border: 1px solid var(--border);
    border-radius: var(--radius-overlay);
    box-shadow: var(--shadow-overlay);
    z-index: 1000;
  }
  .menu__panel:popover-open,
  .menu__panel.is-open { display: flex; }
  .menu__heading {
    padding: var(--space-2) var(--space-2) var(--space-1);
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-semibold);
    letter-spacing: var(--font-tracking-wide);
    text-transform: uppercase;
    color: var(--text-subtle);
  }
  .menu__item {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    width: 100%;
    min-height: 36px;
    padding: var(--space-2);
    font: inherit;
    font-size: var(--font-size-sm);
    text-align: left;
    color: var(--text);
    background: none;
    border: 0;
    border-radius: var(--radius-sm);
    cursor: pointer;
  }
  .menu__item:hover:not(:disabled) { background: var(--surface-hover); }
  .menu__item:disabled { color: var(--text-subtle); cursor: default; }
  .menu__rule { height: 1px; margin: var(--space-1) 0; background: var(--border-subtle); border: 0; }
`;
