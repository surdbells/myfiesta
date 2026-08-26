import { Component, ElementRef, effect, input, output, viewChild } from '@angular/core';
import { X } from 'lucide-angular';
import { UiIcon } from './icon';

/**
 * A panel that slides in from the edge.
 *
 * Same native `<dialog>` foundation as the modal, for the same reasons — the
 * top layer, a real focus trap, the rest of the page made inert, and Escape.
 * What differs is only the shape: full height against one edge instead of
 * centred, which is the right form for two things a centred box handles badly.
 *
 *   - navigation on a phone, where the drawer is the sidebar
 *   - a detail panel beside a table, where the point is to keep the row you
 *     came from visible behind it
 *
 * The animation runs on the dialog's own open/close, and is dropped entirely
 * under `prefers-reduced-motion` — a panel that flies in from the side is one
 * of the movements that actually makes people ill.
 */
@Component({
  selector: 'ui-drawer',
  imports: [UiIcon],
  template: `
    <dialog
      #dialog
      class="drawer"
      [class.drawer--start]="side() === 'start'"
      (close)="dismissed.emit()"
      (click)="onBackdrop($event)"
    >
      <div class="drawer__panel">
        <header class="drawer__head">
          <h2 class="drawer__title">{{ heading() }}</h2>
          <button type="button" class="drawer__x" aria-label="Close" (click)="close()">
            <ui-icon [icon]="closeIcon" />
          </button>
        </header>

        <div class="drawer__body"><ng-content /></div>

        <footer class="drawer__foot"><ng-content select="[drawerActions]" /></footer>
      </div>
    </dialog>
  `,
  styles: `
    .drawer {
      /* Pinned to the right edge, full height. The margin rules are what pull
         a native dialog out of its centred default. */
      margin: 0 0 0 auto;
      padding: 0;
      height: 100dvh;
      max-height: 100dvh;
      width: min(420px, 100vw);
      max-width: 100vw;
      color: var(--text);
      background-color: var(--surface-raised);
      border: 0;
      border-inline-start: 1px solid var(--border);
      box-shadow: var(--shadow-overlay);
    }
    .drawer--start {
      margin: 0 auto 0 0;
      border-inline-start: 0;
      border-inline-end: 1px solid var(--border);
    }
    .drawer::backdrop { background-color: rgba(8, 12, 9, 0.55); }

    .drawer[open] { animation: drawer-in var(--motion-base) var(--motion-ease); }
    @keyframes drawer-in {
      from { transform: translateX(100%); }
    }
    .drawer--start[open] { animation-name: drawer-in-start; }
    @keyframes drawer-in-start {
      from { transform: translateX(-100%); }
    }
    @media (prefers-reduced-motion: reduce) {
      .drawer[open] { animation: none; }
    }

    .drawer__panel {
      display: grid;
      /* Head and foot fixed, body scrolls. Without this a long form scrolls
         the close button off the top. */
      grid-template-rows: auto 1fr auto;
      height: 100%;
    }
    .drawer__head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: var(--space-4);
      padding: var(--space-4) var(--space-5);
      border-bottom: 1px solid var(--border-subtle);
    }
    .drawer__title {
      margin: 0;
      font-size: var(--font-size-lg);
      font-weight: var(--font-weight-semibold);
    }
    .drawer__x {
      width: 44px;
      height: 44px;
      margin-right: calc(-1 * var(--space-2));
      font: inherit;
      font-size: var(--font-size-xl);
      line-height: 1;
      color: var(--text-muted);
      background: none;
      border: 0;
      border-radius: var(--radius-md);
      cursor: pointer;
      flex-shrink: 0;
    }
    .drawer__x:hover { color: var(--text); background-color: var(--surface-inset); }
    .drawer__body { overflow-y: auto; padding: var(--space-5); min-height: 0; }
    .drawer__foot:not(:empty) {
      display: flex;
      justify-content: flex-end;
      gap: var(--space-3);
      padding: var(--space-4) var(--space-5);
      border-top: 1px solid var(--border-subtle);
    }
  `,
})
export class UiDrawer {
  protected readonly closeIcon = X;

  readonly heading = input.required<string>();

  /** Which edge it comes from. `end` (the right) unless it is navigation. */
  readonly side = input<'start' | 'end'>('end');

  readonly open = input(false);
  readonly dismissed = output<void>();

  private readonly dialog = viewChild<ElementRef<HTMLDialogElement>>('dialog');

  constructor() {
    effect(() => {
      const element = this.dialog()?.nativeElement;

      if (!element) return;

      if (this.open() && !element.open) {
        element.showModal();
      } else if (!this.open() && element.open) {
        element.close();
      }
    });
  }

  close(): void {
    this.dialog()?.nativeElement.close();
  }

  onBackdrop(event: MouseEvent): void {
    if (event.target === this.dialog()?.nativeElement) {
      this.close();
    }
  }
}
