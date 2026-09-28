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
import { isObservable, lastValueFrom, type Observable } from 'rxjs';
import { messageOf } from '../core/errors';
import { MfSheet, type SheetDismissal } from './sheet';
import { MfButton } from './button';
import { MfIcon, type LucideIconData } from './icon';
import { MfField } from './field';

/** A red button and focus on Cancel, or neither. */
export type ConfirmTone = 'default' | 'danger';

/** A written reason, asked for in the sheet and handed back. */
export interface ConfirmReason {
  label: string;
  required?: boolean;
  minLength?: number;
  maxLength?: number;
  /** Under the box. Defaults to the minimum length, when there is one. */
  hint?: string;
  placeholder?: string;
}

/**
 * A question before an action. The same shape as the web's
 * `ConfirmDialog` in @myfiesta/ui, so a screen reads the same in both.
 */
export interface ConfirmRequest {
  /** The question: "Refund this order?" */
  title: string;
  /** What will actually happen. Required, because "are you sure?" is not a question. */
  body: string;
  /** The knock-on effects, one per line, when there is more than one. */
  consequences?: readonly string[];
  /** The action, named: "Submit for review", "Refund $40.00". Never "OK" or "Yes". */
  confirmLabel: string;
  cancelLabel?: string;
  /** Said on the button while `run` is working: "Refunding…". */
  busyLabel?: string;
  tone: ConfirmTone;
  /** Makes somebody type this before the button works. For the irreversible only. */
  requireText?: string;
  reason?: ConfirmReason;
  /**
   * The action itself. The sheet stays up and busy while it runs, goes when
   * it succeeds, and shows what went wrong in place when it does not — so the
   * person can try again or cancel without finding the action again.
   */
  run?: (reason: string | undefined) => PromiseLike<unknown> | Observable<unknown>;
  /** A failed `run`, in words. Defaults to the API's own sentence (`messageOf`). */
  failure?: (error: unknown) => string;
}

/**
 * The first spelling of a confirmation, still understood.
 *
 * @deprecated Write a `ConfirmRequest`: `body`, `confirmLabel` and `tone`
 * rather than `message`, `confirm` and `danger`.
 */
export interface LegacyConfirmOptions {
  title: string;
  message?: string;
  /** What the confirming button says. A verb for what happens: "Refund", not "OK". */
  confirm: string;
  cancel?: string;
  /** Something that cannot be taken back: the button is red. */
  danger?: boolean;
}

export type ConfirmOptions = ConfirmRequest | LegacyConfirmOptions;

export interface ConfirmResult {
  confirmed: boolean;
  /** What was written, trimmed. Absent when nothing was, or the answer was no. */
  reason?: string;
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
  | { kind: 'confirm'; options: ConfirmRequest; resolve: (value: ConfirmResult) => void }
  | { kind: 'menu'; options: MenuOptions; resolve: (value: string | null) => void }
  | { kind: 'prompt'; options: PromptOptions; resolve: (value: string | null) => void };

/** Either spelling, as the one the sheet draws. */
function normalise(options: ConfirmOptions): ConfirmRequest {
  if ('confirmLabel' in options) return options;

  return {
    title: options.title,
    body: options.message ?? '',
    confirmLabel: options.confirm,
    cancelLabel: options.cancel,
    tone: options.danger ? 'danger' : 'default',
  };
}

/** Whatever `run` handed back, as something to wait on. */
function completion(value: PromiseLike<unknown> | Observable<unknown>): Promise<unknown> {
  return isObservable(value) ? lastValueFrom(value, { defaultValue: undefined }) : Promise.resolve(value);
}

/** Whether what has been typed and written lets the confirming button work. */
export function confirmReady(options: ConfirmRequest, typed: string, written: string): boolean {
  const word = options.requireText;

  if (word !== undefined && typed.trim().toLowerCase() !== word.trim().toLowerCase()) return false;

  const asked = options.reason;

  if (!asked) return true;

  const length = written.trim().length;

  if (length === 0) return !asked.required;

  return length >= (asked.minLength ?? 1) && (asked.maxLength === undefined || length <= asked.maxLength);
}

/**
 * Ask somebody something, and wait for the answer.
 *
 * Confirming a refund, choosing from a row's "…" menu, typing a reason: each
 * of these is a sheet, and a screen that owns one boolean per sheet ends up
 * with seven of them and a template that is mostly plumbing. Here a screen
 * awaits a promise instead —
 *
 *     const refunded = await dialogs.confirm({
 *       title: 'Refund this order?',
 *       body: 'Ada gets $40.00 back on the card she paid with.',
 *       confirmLabel: 'Refund $40.00',
 *       tone: 'danger',
 *       run: () => this.organizer.refund(order.id),
 *     });
 *
 * — and one host in the shell draws whichever sheet is being asked. Dismissing
 * it any way at all (drag, scrim, back, Escape) is an answer: no, or nothing.
 * `decide()` is the same question, answered with the reason written in it.
 * The README has the whole of it.
 */
@Injectable({ providedIn: 'root' })
export class Dialogs {
  readonly pending = signal<Pending | null>(null);

  /** The confirmed action is running: the sheet stays up, and nothing dismisses it. */
  readonly busy = signal(false);

  /** Why the last attempt at the confirmed action failed, shown in the sheet. */
  readonly failure = signal<string | null>(null);

  /** Asked while an action was running, and shown once it has finished. */
  private readonly waiting: Pending[] = [];

  /** Yes or no. True only once the action — `run`, when given — has gone through. */
  confirm(options: ConfirmOptions): Promise<boolean> {
    return this.decide(options).then((answer) => answer.confirmed);
  }

  /** The answer, and the reason written with it. */
  decide(options: ConfirmOptions): Promise<ConfirmResult> {
    return new Promise((resolve) => this.show({ kind: 'confirm', options: normalise(options), resolve }));
  }

  menu(options: MenuOptions): Promise<string | null> {
    return new Promise((resolve) => this.show({ kind: 'menu', options, resolve }));
  }

  prompt(options: PromptOptions): Promise<string | null> {
    return new Promise((resolve) => this.show({ kind: 'prompt', options, resolve }));
  }

  /**
   * The confirming button was pressed.
   *
   * With no `run`, that is the answer. With one, the sheet stays up and busy
   * until it finishes: a success answers yes; a failure stays on screen, in
   * the sheet, until they try again or cancel.
   */
  async accept(reason?: string): Promise<void> {
    const current = this.pending();

    if (current?.kind !== 'confirm' || this.busy()) return;

    const written = reason?.trim() || undefined;
    const answer: ConfirmResult = written === undefined ? { confirmed: true } : { confirmed: true, reason: written };
    const run = current.options.run;

    if (!run) {
      this.answer(current, answer);
      return;
    }

    this.busy.set(true);
    this.failure.set(null);

    try {
      await completion(run(written));
    } catch (error) {
      this.busy.set(false);

      // Left on screen with what went wrong. Anything asked meanwhile waits
      // until this has been answered — tried again, or cancelled.
      if (this.pending() === current) {
        this.failure.set((current.options.failure ?? messageOf)(error) || messageOf(error));
      }

      return;
    }

    this.busy.set(false);
    this.answer(current, answer);
  }

  /**
   * Answered: close it and hand the answer back.
   *
   * `true` answers a confirmation yes without running its action — the
   * button goes through `accept()`. Refused while that action is running:
   * the answer to it is not in yet.
   */
  settle(value: boolean | string | null): void {
    const current = this.pending();

    if (!current || this.busy()) return;

    if (current.kind === 'confirm') {
      this.answer(current, { confirmed: value === true });
    } else {
      this.close();
      current.resolve(typeof value === 'string' ? value : null);
    }
  }

  private answer(current: Extract<Pending, { kind: 'confirm' }>, result: ConfirmResult): void {
    this.close();
    current.resolve(result);
  }

  private close(): void {
    this.pending.set(null);
    this.failure.set(null);

    // Whatever was asked while an action ran gets its turn now.
    const next = this.waiting.shift();

    if (next) this.pending.set(next);
  }

  private show(next: Pending): void {
    // Only one question at a time. One still waiting for an answer is
    // answered "no"; one whose action is running is let finish, and this
    // waits its turn — cancelling it would say "no" about something that may
    // yet go through.
    if (this.busy()) {
      this.waiting.push(next);
      return;
    }

    this.settle(null);
    this.pending.set(next);
  }
}

let sequence = 0;

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
      [role]="pending()?.kind === 'confirm' ? 'alertdialog' : 'dialog'"
      [describedBy]="describedBy()"
      [dismissible]="!dialogs.busy()"
      (closed)="dismissed($event)"
    >
      @switch (pending()?.kind) {
        @case ('confirm') {
          @if (confirmOptions(); as o) {
            @if (o.body) {
              <p class="message" [id]="bodyId">{{ o.body }}</p>
            }
            @if (o.consequences?.length) {
              <ul class="consequences" [id]="listId">
                @for (line of o.consequences; track $index) {
                  <li>{{ line }}</li>
                }
              </ul>
            }
            @if (o.reason; as asked) {
              <mf-field
                class="entry"
                [label]="asked.label"
                [hint]="reasonHint()"
                [optional]="!asked.required"
                [limit]="asked.maxLength ?? null"
                [count]="written().length"
              >
                <textarea
                  #reasonEntry
                  name="reason"
                  rows="3"
                  [attr.maxlength]="asked.maxLength ?? null"
                  [placeholder]="asked.placeholder ?? ''"
                  [readOnly]="dialogs.busy()"
                  [ngModel]="written()"
                  (ngModelChange)="written.set($event)"
                ></textarea>
              </mf-field>
            }
            @if (o.requireText; as word) {
              <mf-field class="entry" [label]="'Type ' + word + ' to confirm'">
                <input
                  #wordEntry
                  name="confirmWord"
                  autocomplete="off"
                  autocapitalize="off"
                  autocorrect="off"
                  spellcheck="false"
                  [readOnly]="dialogs.busy()"
                  [ngModel]="typed()"
                  (ngModelChange)="typed.set($event)"
                  (keydown.enter)="accept()"
                />
              </mf-field>
            }
            @if (dialogs.failure(); as message) {
              <p class="failure" role="alert">{{ message }}</p>
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
          <button
            #cancelButton
            mfButton
            class="decision"
            variant="secondary"
            [disabled]="dialogs.busy()"
            (click)="dialogs.settle(null)"
          >
            {{ cancelLabel() }}
          </button>
          @if (confirmOptions(); as o) {
            <button
              #confirmButton
              mfButton
              class="decision"
              [variant]="o.tone === 'danger' ? 'danger' : 'primary'"
              [disabled]="!ready()"
              [loading]="dialogs.busy()"
              [label]="o.busyLabel ?? null"
              (click)="accept()"
            >
              {{ o.confirmLabel }}
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

    .consequences {
      display: grid;
      gap: var(--space-2);
      margin: var(--space-3) 0 0;
      padding-left: var(--space-5);
      color: var(--text);
      font-size: var(--font-size-sm);
      line-height: var(--font-leading-snug);
    }

    .consequences li::marker {
      color: var(--text-subtle);
    }

    /* A label that names the action can run long — "Refund $1,250.00 to 12
       buyers" — and wraps inside its button rather than off the sheet. */
    .decision {
      min-width: 0;
      padding: var(--space-2) var(--space-3);
      line-height: var(--font-leading-snug);
      text-align: center;
    }

    .failure {
      margin-top: var(--space-4);
      padding: var(--space-3);
      border-radius: var(--radius-md);
      border-left: 3px solid var(--danger);
      background: color-mix(in srgb, var(--danger) 9%, transparent);
      color: var(--danger-text);
      font-size: var(--font-size-sm);
      line-height: var(--font-leading-snug);
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
  private readonly reasonEntry = viewChild<ElementRef<HTMLTextAreaElement>>('reasonEntry');
  private readonly wordEntry = viewChild<ElementRef<HTMLInputElement>>('wordEntry');
  private readonly cancelButton = viewChild('cancelButton', { read: ElementRef<HTMLButtonElement> });
  private readonly confirmButton = viewChild('confirmButton', { read: ElementRef<HTMLButtonElement> });

  protected readonly pending = this.dialogs.pending;
  protected readonly text = signal('');

  /** The word typed to confirm, and the reason written. Emptied for every question. */
  protected readonly typed = signal('');
  protected readonly written = signal('');

  private readonly uid = ++sequence;
  protected readonly bodyId = `mf-confirm-${this.uid}-body`;
  protected readonly listId = `mf-confirm-${this.uid}-list`;

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

  /** What a screen reader reads out after the question: what happens, and what follows from it. */
  protected readonly describedBy = computed(() => {
    const o = this.confirmOptions();

    if (!o) return null;

    const ids = [o.body ? this.bodyId : null, o.consequences?.length ? this.listId : null].filter(Boolean);

    return ids.length ? ids.join(' ') : null;
  });

  protected readonly cancelLabel = computed(() => this.confirmOptions()?.cancelLabel ?? 'Cancel');

  protected readonly reasonHint = computed(() => {
    const asked = this.confirmOptions()?.reason;

    if (!asked) return null;
    if (asked.hint) return asked.hint;

    return asked.minLength && asked.minLength > 1 ? `At least ${asked.minLength} characters.` : null;
  });

  protected readonly ready = computed(() => {
    const o = this.confirmOptions();

    return !!o && confirmReady(o, this.typed(), this.written());
  });

  protected readonly promptReady = computed(() => !this.promptOptions()?.required || this.text().trim() !== '');

  constructor() {
    // Each question opens empty, with the cursor or the focus where it should
    // start — after the sheet has risen, or the keyboard comes up under a
    // moving panel.
    effect(() => {
      const p = this.pending();

      if (!p || p.kind === 'menu') return;

      untracked(() => {
        this.typed.set('');
        this.written.set('');
        if (p.kind === 'prompt') this.text.set(p.options.value ?? '');
      });

      afterNextRender(
        () =>
          setTimeout(() => {
            if (this.pending() === p) this.startingPoint(p)?.focus();
          }, 360),
        { injector: this.injector },
      );
    });

    // The button goes disabled while its action runs, and focus falls off it.
    // A failure puts it back there — trying again is one press, the same as
    // on the web, rather than a walk back from the top of the sheet.
    effect(() => {
      if (!this.dialogs.failure()) return;

      afterNextRender(() => this.confirmButton()?.nativeElement.focus(), { injector: this.injector });
    });
  }

  /**
   * Where focus starts.
   *
   * Cancel whenever the action destroys something: the first control is
   * where a hurried Enter or a stray tap lands. Otherwise the first thing
   * that has to be typed, and failing that the button that does it.
   */
  private startingPoint(p: Pending): HTMLElement | undefined {
    if (p.kind === 'prompt') return this.entry()?.nativeElement;
    if (p.kind !== 'confirm') return undefined;

    if (p.options.tone === 'danger') return this.cancelButton()?.nativeElement;

    return (
      this.reasonEntry()?.nativeElement ?? this.wordEntry()?.nativeElement ?? this.confirmButton()?.nativeElement
    );
  }

  protected accept(): void {
    if (!this.ready() || this.dialogs.busy()) return;

    void this.dialogs.accept(this.confirmOptions()?.reason ? this.written() : undefined);
  }

  protected submitPrompt(): void {
    if (!this.promptReady()) return;

    this.dialogs.settle(this.text().trim());
  }

  protected dismissed(_reason: SheetDismissal): void {
    this.dialogs.settle(null);
  }
}
