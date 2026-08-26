import { Component, Injectable, computed, inject, signal } from '@angular/core';
import { X } from 'lucide-angular';
import { UiIcon } from './icon';

export interface Toast {
  id: number;
  tone: 'success' | 'danger' | 'info';
  message: string;
}

/**
 * Transient confirmation.
 *
 * A service rather than a component input, because the thing that knows an
 * action succeeded is a method in a component, not its template — and every
 * screen previously carried its own `notice` signal and its own paragraph to
 * render it.
 *
 * Deliberately not used for errors that need acting on. A toast disappears; an
 * error somebody has to fix belongs on the screen, next to the thing that
 * failed. Only failures somebody can do nothing about go here.
 */
@Injectable({ providedIn: 'root' })
export class ToastStore {
  private readonly items = signal<Toast[]>([]);
  private next = 0;

  readonly toasts = this.items.asReadonly();

  show(message: string, tone: Toast['tone'] = 'success'): void {
    const id = this.next++;

    this.items.update((list) => [...list, { id, tone, message }]);

    /*
     * Long enough to read a sentence twice.
     *
     * Errors linger, because somebody who missed a failure has no other way to
     * learn about it, and a four-second error is one nobody reads.
     */
    setTimeout(() => this.dismiss(id), tone === 'danger' ? 9000 : 5000);
  }

  dismiss(id: number): void {
    this.items.update((list) => list.filter((t) => t.id !== id));
  }
}

/**
 * Where they appear.
 *
 * Mounted once in the app shell. The region is polite rather than assertive:
 * a confirmation should wait for a screen reader to finish the sentence it is
 * on, not interrupt it.
 */
@Component({
  selector: 'ui-toasts',
  imports: [UiIcon],
  template: `
    <div class="toasts" role="status" aria-live="polite">
      @for (toast of store.toasts(); track toast.id) {
        <div class="toast" [class]="'toast--' + toast.tone">
          <span>{{ toast.message }}</span>
          <button type="button" aria-label="Dismiss" (click)="store.dismiss(toast.id)">
            <ui-icon [icon]="closeIcon" size="sm" />
          </button>
        </div>
      }
    </div>
  `,
  styles: `
    .toasts {
      position: fixed;
      /* Bottom on a phone, where a thumb is; away from the top bar on a
         laptop, where it would cover navigation. */
      inset: auto var(--space-5) var(--space-5) auto;
      z-index: 60;
      display: grid;
      gap: var(--space-2);
      max-width: min(30rem, calc(100vw - 2 * var(--space-5)));
      pointer-events: none;
    }
    @media (max-width: 560px) {
      .toasts { inset: auto var(--space-4) var(--space-4) var(--space-4); }
    }
    .toast {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      padding: var(--space-3) var(--space-4);
      font-size: var(--font-size-sm);
      background-color: var(--surface-raised);
      border: 1px solid;
      border-radius: var(--radius-md);
      box-shadow: var(--shadow-overlay);
      pointer-events: auto;
      animation: rise var(--motion-base) var(--motion-ease);
    }
    @keyframes rise {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: none; }
    }
    .toast--success { color: var(--success); border-color: color-mix(in srgb, var(--success) 32%, transparent); }
    .toast--danger { color: var(--danger); border-color: color-mix(in srgb, var(--danger) 32%, transparent); }
    .toast--info { color: var(--info); border-color: color-mix(in srgb, var(--info) 32%, transparent); }
    .toast button {
      margin-left: auto;
      width: 28px;
      height: 28px;
      font: inherit;
      font-size: var(--font-size-lg);
      line-height: 1;
      color: inherit;
      background: none;
      border: 0;
      cursor: pointer;
      opacity: 0.7;
    }
    .toast button:hover { opacity: 1; }
  `,
})
export class UiToasts {
  protected readonly closeIcon = X;

  readonly store = inject(ToastStore);
}
