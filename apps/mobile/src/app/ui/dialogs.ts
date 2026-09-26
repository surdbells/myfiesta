import {
  Component,
  ElementRef,
  Injectable,
  Injector,
  afterNextRender,
  computed,
  effect,
  inject,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MfSheet, type SheetDismissal } from './sheet';
import { MfButton } from './button';
import { MfIcon, type LucideIconData } from './icon';
import { MfField } from './field';

export interface ConfirmOptions {
  title: string;
  message?: string;
  /** What the confirming button says. A verb for what happens: "Refund", not "OK". */
  confirm: string;
  cancel?: string;
  /** Something that cannot be taken back: the button is red. */
  danger?: boolean;
}

export interface MenuAction {
  key: string;
  label: string;
  icon?: LucideIconData;
  hint?: string;
  danger?: boolean;
  disabled?: boolean;
}

export interface MenuOptions {
  title?: string;
  subtitle?: string;
  actions: MenuAction[];
}

export interface PromptOptions {
  title: string;
  message?: string;
  label: string;
  value?: string;
  placeholder?: string;
  confirm: string;
  multiline?: boolean;
  /** The confirming button waits for something to be typed. */
  required?: boolean;
  inputmode?: 'text' | 'email' | 'numeric' | 'decimal';
}

type Pending =
  | { kind: 'confirm'; options: ConfirmOptions; resolve: (value: boolean) => void }
  | { kind: 'menu'; options: MenuOptions; resolve: (value: string | null) => void }
  | { kind: 'prompt'; options: PromptOptions; resolve: (value: string | null) => void };

/**
 * Ask somebody something, and wait for the answer.
 *
 * Confirming a refund, choosing from a row's "…" menu, typing a reason: each
 * of these is a sheet, and a screen that owns one boolean per sheet ends up
 * with seven of them and a template that is mostly plumbing. Here a screen
 * awaits a promise instead —
 *
 *     if (await dialogs.confirm({ title: 'Refund this order?', confirm: 'Refund', danger: true })) …
 *
 * — and one host in the shell draws whichever sheet is being asked. Dismissing
 * it any way at all (drag, scrim, back, Escape) is an answer: no, or nothing.
 */
@Injectable({ providedIn: 'root' })
export class Dialogs {
  readonly pending = signal<Pending | null>(null);

  confirm(options: ConfirmOptions): Promise<boolean> {
    return new Promise((resolve) => this.show({ kind: 'confirm', options, resolve }));
  }

  menu(options: MenuOptions): Promise<string | null> {
    return new Promise((resolve) => this.show({ kind: 'menu', options, resolve }));
  }

  prompt(options: PromptOptions): Promise<string | null> {
    return new Promise((resolve) => this.show({ kind: 'prompt', options, resolve }));
  }

  /** Answered: close it and hand the answer back. */
  settle(value: boolean | string | null): void {
    const current = this.pending();

    if (!current) return;

    this.pending.set(null);

    if (current.kind === 'confirm') current.resolve(value === true);
    else current.resolve(typeof value === 'string' ? value : null);
  }

  private show(next: Pending): void {
    // Only one question at a time: an earlier one is answered "no".
    this.settle(null);
    this.pending.set(next);
  }
}

/**
 * Where the Dialogs service draws. One of these lives in the shell.
 */
@Component({
  selector: 'mf-dialog-host',
  imports: [FormsModule, MfSheet, MfButton, MfIcon, MfField],
  template: `
    <mf-sheet
      [open]="!!pending()"
      [heading]="heading()"
      [subheading]="subheading()"
      (closed)="dismissed($event)"
    >
      @switch (pending()?.kind) {
        @case ('confirm') {
          @if (confirmOptions(); as o) {
            @if (o.message) {
              <p class="message">{{ o.message }}</p>
            }
          }
        }

        @case ('menu') {
          <ul class="actions" role="menu">
            @for (action of menuOptions()?.actions ?? []; track action.key) {
              <li>
                <button
                  type="button"
                  role="menuitem"
                  class="action"
                  [class.danger]="action.danger"
                  [disabled]="action.disabled"
                  (click)="dialogs.settle(action.key)"
                >
                  @if (action.icon) {
                    <span class="glyph"><mf-icon [icon]="action.icon" /></span>
                  }
                  <span class="text">
                    <span class="label">{{ action.label }}</span>
                    @if (action.hint) {
                      <span class="hint">{{ action.hint }}</span>
                    }
                  </span>
                </button>
              </li>
            }
          </ul>
        }

        @case ('prompt') {
          @if (promptOptions(); as o) {
            @if (o.message) {
              <p class="message">{{ o.message }}</p>
            }
            <mf-field class="entry" [label]="o.label">
              @if (o.multiline) {
                <textarea
                  #entry
                  rows="4"
                  [placeholder]="o.placeholder ?? ''"
                  [ngModel]="text()"
                  (ngModelChange)="text.set($event)"
                ></textarea>
              } @else {
                <input
                  #entry
                  [attr.inputmode]="o.inputmode ?? 'text'"
                  [placeholder]="o.placeholder ?? ''"
                  [ngModel]="text()"
                  (ngModelChange)="text.set($event)"
                  (keydown.enter)="submitPrompt()"
                />
              }
            </mf-field>
          }
        }
      }

      @if (pending()?.kind === 'confirm' || pending()?.kind === 'prompt') {
        <ng-container sheetFooter>
          <button mfButton variant="secondary" (click)="dialogs.settle(null)">{{ cancelLabel() }}</button>
          @if (pending()?.kind === 'confirm') {
            <button mfButton [variant]="confirmOptions()?.danger ? 'danger' : 'primary'" (click)="dialogs.settle(true)">
              {{ confirmOptions()?.confirm }}
            </button>
          } @else {
            <button mfButton [disabled]="!promptReady()" (click)="submitPrompt()">{{ promptOptions()?.confirm }}</button>
          }
        </ng-container>
      }
    </mf-sheet>
  `,
  styles: `
    .message {
      color: var(--text-muted);
      line-height: var(--font-leading-normal);
    }

    .actions {
      display: grid;
      gap: 2px;
      margin: 0 calc(var(--space-2) * -1);
      padding: 0;
      list-style: none;
    }

    .action {
      display: flex;
      align-items: center;
      gap: var(--space-4);
      width: 100%;
      min-height: 52px;
      padding: var(--space-2) var(--space-3);
      border: 0;
      border-radius: var(--radius-lg);
      background: transparent;
      color: var(--text);
      font: inherit;
      text-align: left;
      cursor: pointer;
    }

    .action:active {
      background: var(--surface-hover);
    }

    .action:disabled {
      opacity: 0.45;
    }

    .action.danger {
      color: var(--danger-text);
    }

    .glyph {
      display: grid;
      place-items: center;
      width: 36px;
      height: 36px;
      border-radius: var(--radius-md);
      background: var(--surface-inset);
    }

    .action.danger .glyph {
      background: color-mix(in srgb, var(--danger) 12%, transparent);
    }

    .text {
      display: grid;
      gap: 2px;
      min-width: 0;
    }

    .label {
      font-weight: var(--font-weight-medium);
    }

    .hint {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .entry {
      margin-top: var(--space-4);
    }
  `,
})
export class MfDialogHost {
  protected readonly dialogs = inject(Dialogs);
  private readonly injector = inject(Injector);
  private readonly entry = viewChild<ElementRef<HTMLInputElement | HTMLTextAreaElement>>('entry');

  protected readonly pending = this.dialogs.pending;
  protected readonly text = signal('');

  protected readonly confirmOptions = computed(() => {
    const p = this.pending();
    return p?.kind === 'confirm' ? p.options : null;
  });

  protected readonly menuOptions = computed(() => {
    const p = this.pending();
    return p?.kind === 'menu' ? p.options : null;
  });

  protected readonly promptOptions = computed(() => {
    const p = this.pending();
    return p?.kind === 'prompt' ? p.options : null;
  });

  protected readonly heading = computed(() => {
    const p = this.pending();
    return p ? (p.options.title ?? null) : null;
  });

  protected readonly subheading = computed(() => {
    const p = this.pending();
    return p?.kind === 'menu' ? (p.options.subtitle ?? null) : null;
  });

  protected readonly cancelLabel = computed(() => {
    const p = this.pending();
    return p?.kind === 'confirm' ? (p.options.cancel ?? 'Cancel') : 'Cancel';
  });

  protected readonly promptReady = computed(() => !this.promptOptions()?.required || this.text().trim() !== '');

  constructor() {
    // A prompt opens with its starting value and the cursor already in it —
    // after the sheet has risen, or the keyboard comes up under a moving panel.
    effect(() => {
      const p = this.pending();

      if (p?.kind !== 'prompt') return;

      untracked(() => this.text.set(p.options.value ?? ''));
      afterNextRender(() => setTimeout(() => this.entry()?.nativeElement.focus(), 360), { injector: this.injector });
    });
  }

  protected submitPrompt(): void {
    if (!this.promptReady()) return;

    this.dialogs.settle(this.text().trim());
  }

  protected dismissed(_reason: SheetDismissal): void {
    this.dialogs.settle(null);
  }
}
