import { Component, Injectable, inject, signal } from '@angular/core';

interface Toast {
  id: number;
  text: string;
  tone: 'neutral' | 'success' | 'danger';
}

/**
 * Short messages, said once.
 *
 * Above the safe area rather than at the top: the top of a phone is where the
 * carrier and the clock live, and a message there is read as part of the
 * system rather than as the app answering what you just did.
 */
@Injectable({ providedIn: 'root' })
export class ToastStore {
  private next = 0;

  readonly toasts = signal<Toast[]>([]);

  show(text: string, tone: Toast['tone'] = 'neutral', ms = 3200): void {
    const id = this.next++;

    this.toasts.update((all) => [...all, { id, text, tone }]);
    setTimeout(() => this.dismiss(id), ms);
  }

  dismiss(id: number): void {
    this.toasts.update((all) => all.filter((toast) => toast.id !== id));
  }
}

@Component({
  selector: 'mf-toasts',
  template: `
    @for (toast of store.toasts(); track toast.id) {
      <output class="toast" [class]="toast.tone" (click)="store.dismiss(toast.id)">{{ toast.text }}</output>
    }
  `,
  styles: `
    :host {
      position: fixed;
      left: 0;
      right: 0;
      bottom: calc(var(--mf-safe-bottom) + var(--mf-toast-lift) + var(--space-3));
      transition: bottom 220ms var(--mf-ease-out);
      z-index: 200;
      display: grid;
      justify-items: center;
      gap: var(--space-2);
      padding: 0 var(--space-5);
      pointer-events: none;
    }

    .toast {
      max-width: 32rem;
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-lg);
      background: var(--text);
      color: var(--surface);
      font-size: var(--font-size-sm);
      line-height: var(--font-leading-snug);
      box-shadow: var(--shadow-floating);
      pointer-events: auto;
      animation: mf-toast-in 220ms cubic-bezier(0.32, 0.72, 0, 1);
    }

    .success {
      background: var(--primary);
      color: var(--on-primary);
    }

    .danger {
      background: var(--danger);
      color: var(--text-inverse);
    }

    @keyframes mf-toast-in {
      from {
        opacity: 0;
        transform: translateY(12px);
      }
    }
  `,
})
export class MfToasts {
  protected readonly store = inject(ToastStore);
}
