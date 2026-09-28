import {
  ApplicationRef,
  Component,
  EnvironmentInjector,
  Injectable,
  PLATFORM_ID,
  booleanAttribute,
  computed,
  createComponent,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
  type ComponentRef,
} from '@angular/core';
import { DOCUMENT, isPlatformBrowser } from '@angular/common';
import { isObservable, lastValueFrom, type Observable } from 'rxjs';
import { UiButton } from './button';
import { UiField } from './field';
import { UiModal } from './modal';

/*
 * THE CONFIRMATION DIALOG — how every action in the site and the console asks
 * first. Written out here because it is the one component every screen needs.
 *
 *   const confirmDialog = inject(ConfirmDialog);
 *
 *   // Yes or no. Resolves true only once the action has actually gone through.
 *   const refunded = await confirmDialog.confirm({
 *     title: 'Refund this order?',
 *     body: 'Ada gets $40.00 back on the card she paid with.',
 *     consequences: ['Her two tickets stop working at the door.', 'The fee is not returned.'],
 *     confirmLabel: 'Refund $40.00',            // names the action, never "OK"
 *     tone: 'danger',                           // red button, and focus starts on Cancel
 *     run: () => this.api.refund(order.id),     // busy while it runs; a failure stays in the dialog
 *     failure: (error) => messageFor(error, 'The refund did not go through.'),
 *   });
 *
 *   // When a written reason has to come back to the caller.
 *   const { confirmed, reason } = await confirmDialog.decide({
 *     title: 'Suspend this organizer?',
 *     body: 'Their events come off sale straight away.',
 *     confirmLabel: 'Suspend',
 *     tone: 'danger',
 *     requireText: 'SUSPEND',                   // type-to-confirm, for the irreversible only
 *     reason: { label: 'Why', required: true, minLength: 10, maxLength: 500 },
 *   });
 *
 * Nothing to place in a template: the service draws the dialog itself, one
 * question at a time, and hands focus back to whatever asked. On the server
 * it answers no without drawing anything.
 *
 * `<ui-confirm>` is the same dialog placed in a template by hand, for a
 * screen that already owns an `open` flag. New code wants the service.
 */

/** A red button and focus on Cancel, or neither. */
export type ConfirmTone = 'default' | 'danger';

/** A written reason, asked for in the dialog and handed back. */
export interface ConfirmReason {
  label: string;
  required?: boolean;
  minLength?: number;
  maxLength?: number;
  /** Under the box. Defaults to the minimum length, when there is one. */
  hint?: string;
  placeholder?: string;
}

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
   * The action itself. The dialog stays open and busy while it runs, closes
   * when it succeeds, and shows `failure(error)` inline when it does not — so
   * the person can try again or cancel without starting over.
   */
  run?: (reason: string | undefined) => PromiseLike<unknown> | Observable<unknown>;
  /** A failed `run`, in words. Pass the app's own error reader. */
  failure?: (error: unknown) => string;
}

export interface ConfirmResult {
  confirmed: boolean;
  /** What was written, trimmed. Absent when nothing was, or the answer was no. */
  reason?: string;
}

/** Said when a failed `run` has no `failure` of its own. Server detail never reaches the screen. */
const FAILED = 'That did not work. Try again.';

let sequence = 0;

/**
 * Are you sure — asked properly.
 *
 * A confirm dialog that says "are you sure?" and nothing else is asking
 * somebody to guess. This one requires a consequence: what will happen, stated
 * in the caller's words, above the button that does it.
 *
 * For the genuinely irreversible, `confirmWord` makes somebody type the thing's
 * name. Deliberately not the default — friction on every delete trains people
 * to type without reading, which is worse than no friction at all.
 *
 * An `alertdialog`, so it is announced as the interruption it is. Focus starts
 * on Cancel when the action destroys something, because the first control in
 * the dialog is where a hurried Enter lands.
 */
@Component({
  selector: 'ui-confirm',
  imports: [UiModal, UiButton, UiField],
  template: `
    <ui-modal
      role="alertdialog"
      [heading]="heading()"
      [description]="consequence()"
      [describedBy]="consequences().length ? listId : null"
      [open]="open()"
      [closable]="false"
      [dismissible]="!busy()"
      (dismissed)="cancel()"
    >
      <div class="confirm">
        @if (consequences().length) {
          <ul class="confirm__list" [id]="listId">
            @for (line of consequences(); track $index) {
              <li>{{ line }}</li>
            }
          </ul>
        }

        <ng-content />

        @if (reason(); as asked) {
          <ui-field [label]="asked.label" [hint]="reasonHint()" [required]="!!asked.required">
            <textarea
              [id]="reasonId"
              name="reason"
              rows="3"
              [attr.data-autofocus]="startAt() === 'reason' ? '' : null"
              [attr.maxlength]="asked.maxLength ?? null"
              [attr.placeholder]="asked.placeholder ?? null"
              [readOnly]="busy()"
              [value]="written()"
              (input)="written.set($any($event.target).value)"
            ></textarea>
          </ui-field>
        }

        @if (confirmWord(); as word) {
          <ui-field [label]="'Type ' + word + ' to confirm'">
            <input
              [id]="wordId"
              name="confirmWord"
              autocomplete="off"
              autocapitalize="off"
              spellcheck="false"
              [attr.data-autofocus]="startAt() === 'word' ? '' : null"
              [readOnly]="busy()"
              [value]="typed()"
              (input)="typed.set($any($event.target).value)"
              (keydown.enter)="submit($event)"
            />
          </ui-field>
        }

        @if (error(); as message) {
          <p class="confirm__error" role="alert">{{ message }}</p>
        }
      </div>

      <ng-container ngProjectAs="[modalActions]">
        <button
          uiButton
          variant="secondary"
          type="button"
          [attr.data-autofocus]="startAt() === 'cancel' ? '' : null"
          [disabled]="busy()"
          (click)="cancel()"
        >
          {{ cancelLabel() }}
        </button>
        <!--
          Busy is aria-disabled rather than disabled: a disabled button drops
          the focus that was on it, out of the dialog and onto the page.
        -->
        <button
          uiButton
          [variant]="destructive() ? 'danger' : 'primary'"
          type="button"
          [attr.data-autofocus]="startAt() === 'confirm' ? '' : null"
          [disabled]="!ready()"
          [attr.aria-disabled]="busy() ? 'true' : null"
          [attr.aria-busy]="busy() ? 'true' : null"
          [loading]="busy()"
          (click)="submit()"
        >
          {{ busy() ? busyLabel() : confirmLabel() }}
        </button>
      </ng-container>
    </ui-modal>
  `,
  styles: `
    .confirm { display: grid; gap: var(--space-4); }
    .confirm:empty { display: none; }
    .confirm__list {
      display: grid;
      gap: var(--space-2);
      margin: 0;
      padding-left: var(--space-5);
      font-size: var(--font-size-sm);
      color: var(--text);
    }
    .confirm__list li::marker { color: var(--text-subtle); }
    .confirm__error {
      margin: 0;
      padding: var(--space-3);
      font-size: var(--font-size-sm);
      color: var(--danger-text);
      background-color: color-mix(in srgb, var(--danger) 9%, transparent);
      border-left: 3px solid var(--danger);
      border-radius: var(--radius-control);
    }
    /* A label that names the action can be long — "Refund $1,250.00 to 12
       buyers" — and at phone width it wraps rather than running off the
       dialog. */
    button {
      max-width: 100%;
      height: auto;
      min-height: 44px;
      padding-block: var(--space-2);
      white-space: normal;
      text-align: center;
    }
  `,
})
export class UiConfirm {
  readonly open = input(false);
  readonly heading = input.required<string>();

  /** What will actually happen. Required, because "are you sure?" is not a question. */
  readonly consequence = input.required<string>();

  /** The knock-on effects, listed under it and read out with it. */
  readonly consequences = input<readonly string[]>([]);

  readonly confirmLabel = input('Confirm');
  readonly busyLabel = input('Working…');
  readonly cancelLabel = input('Cancel');
  // Takes the bare attribute, so `<ui-confirm destructive>` reads the way it
  // does on a native control instead of silently passing the empty string.
  readonly destructive = input(false, { transform: booleanAttribute });
  readonly busy = input(false, { transform: booleanAttribute });

  /** Reserve for the irreversible. Friction everywhere trains people past it. */
  readonly confirmWord = input<string | null>(null);

  /** A written reason, emitted with `confirmed`. */
  readonly reason = input<ConfirmReason | null>(null);

  /** Why the last attempt failed, shown above the buttons until the next one. */
  readonly error = input<string | null>(null);

  /** Pressed, with what was written as the reason (trimmed), if one was asked for. */
  readonly confirmed = output<string | undefined>();
  readonly cancelled = output<void>();

  readonly typed = signal('');
  readonly written = signal('');

  private readonly uid = ++sequence;
  protected readonly listId = `ui-confirm-${this.uid}-list`;
  protected readonly reasonId = `ui-confirm-${this.uid}-reason`;
  protected readonly wordId = `ui-confirm-${this.uid}-word`;

  /**
   * Where focus starts.
   *
   * Cancel whenever the action destroys something. Otherwise the first thing
   * that has to be typed, and failing that the button that does it.
   */
  protected readonly startAt = computed(() => {
    if (this.destructive()) return 'cancel';
    if (this.reason()) return 'reason';
    if (this.confirmWord()) return 'word';

    return 'confirm';
  });

  protected readonly reasonHint = computed(() => {
    const asked = this.reason();

    if (!asked) return null;
    if (asked.hint) return asked.hint;

    return asked.minLength && asked.minLength > 1 ? `At least ${asked.minLength} characters.` : null;
  });

  private readonly reasonReady = computed(() => {
    const asked = this.reason();

    if (!asked) return true;

    const length = this.written().trim().length;

    if (length === 0) return !asked.required;

    return length >= (asked.minLength ?? 1) && (asked.maxLength === undefined || length <= asked.maxLength);
  });

  readonly ready = computed(() => {
    const word = this.confirmWord();
    const typedOk = word === null || this.typed().trim().toLowerCase() === word.trim().toLowerCase();

    return typedOk && this.reasonReady();
  });

  constructor() {
    // Opened afresh, empty: the word typed to delete the last thing must not
    // already be sitting there, button lit, when the next thing is asked about.
    effect(() => {
      if (!this.open()) return;

      untracked(() => {
        this.typed.set('');
        this.written.set('');
      });
    });
  }

  protected submit(event?: Event): void {
    event?.preventDefault();

    if (!this.ready() || this.busy()) return;

    const reason = this.written().trim();

    this.confirmed.emit(this.reason() && reason !== '' ? reason : undefined);
  }

  protected cancel(): void {
    if (this.busy()) return;

    this.cancelled.emit();
  }
}

/** One question on screen. */
interface Asking {
  busy: boolean;
  readonly done: Promise<ConfirmResult>;
  finish(result: ConfirmResult): void;
}

/** Whatever `run` handed back, as something to wait on. */
function completion(value: PromiseLike<unknown> | Observable<unknown>): Promise<unknown> {
  return isObservable(value) ? lastValueFrom(value, { defaultValue: undefined }) : Promise.resolve(value);
}

/**
 * Ask somebody before doing something, and wait for the answer.
 *
 * A screen awaits a promise rather than owning an `open` flag, a busy flag and
 * an error per action — seven actions on a screen was seven of each, and a
 * template that was mostly plumbing. See the note at the top of this file.
 */
@Injectable({ providedIn: 'root' })
export class ConfirmDialog {
  private readonly appRef = inject(ApplicationRef);
  private readonly injector = inject(EnvironmentInjector);
  private readonly document = inject(DOCUMENT);
  private readonly browser = isPlatformBrowser(inject(PLATFORM_ID));

  private active: Asking | null = null;

  /** Yes or no. True only once the action — `run`, when given — has gone through. */
  async confirm(request: ConfirmRequest): Promise<boolean> {
    return (await this.decide(request)).confirmed;
  }

  /** The answer, and the reason written with it. */
  decide(request: ConfirmRequest): Promise<ConfirmResult> {
    // Nobody to ask on the server, and nothing is done without asking.
    if (!this.browser) return Promise.resolve({ confirmed: false });

    const current = this.active;

    // One question at a time. One still waiting to be answered is answered
    // no; one whose action is running is let finish, and this waits its turn —
    // cancelling it would report "no" for something that may yet go through.
    if (current?.busy) return current.done.then(() => this.decide(request));
    current?.finish({ confirmed: false });

    return this.ask(request);
  }

  private ask(request: ConfirmRequest): Promise<ConfirmResult> {
    const active = this.document.activeElement;
    const opener = active instanceof HTMLElement ? active : null;

    const ref: ComponentRef<UiConfirm> = createComponent(UiConfirm, { environmentInjector: this.injector });

    ref.setInput('heading', request.title);
    ref.setInput('consequence', request.body);
    ref.setInput('consequences', request.consequences ?? []);
    ref.setInput('confirmLabel', request.confirmLabel);
    ref.setInput('cancelLabel', request.cancelLabel ?? 'Cancel');
    if (request.busyLabel) ref.setInput('busyLabel', request.busyLabel);
    ref.setInput('destructive', request.tone === 'danger');
    ref.setInput('confirmWord', request.requireText ?? null);
    ref.setInput('reason', request.reason ?? null);
    ref.setInput('open', true);

    this.document.body.appendChild(ref.location.nativeElement);
    this.appRef.attachView(ref.hostView);

    let resolve!: (result: ConfirmResult) => void;
    let settled = false;

    const asking: Asking = {
      busy: false,
      done: new Promise<ConfirmResult>((done) => (resolve = done)),
      finish: (result) => {
        if (settled) return;
        settled = true;

        subscriptions.forEach((subscription) => subscription.unsubscribe());
        this.appRef.detachView(ref.hostView);
        (ref.location.nativeElement as HTMLElement).remove();
        ref.destroy();

        if (this.active === asking) this.active = null;

        // Back where the question came from, so a keyboard user carries on
        // down the list rather than starting again from the top.
        if (opener?.isConnected) opener.focus();

        resolve(result);
      },
    };

    const accept = async (reason: string | undefined): Promise<void> => {
      if (asking.busy || settled) return;

      const answer: ConfirmResult = reason === undefined ? { confirmed: true } : { confirmed: true, reason };

      if (!request.run) {
        asking.finish(answer);
        return;
      }

      asking.busy = true;
      ref.setInput('busy', true);
      ref.setInput('error', null);

      try {
        await completion(request.run(reason));
      } catch (error) {
        asking.busy = false;

        if (settled) return;

        // Kept open, with what went wrong where they are looking: try again,
        // or cancel, without having to find the action and start over.
        ref.setInput('busy', false);
        ref.setInput('error', (request.failure ?? (() => FAILED))(error) || FAILED);

        return;
      }

      asking.busy = false;
      asking.finish(answer);
    };

    const subscriptions = [
      ref.instance.confirmed.subscribe((reason) => void accept(reason)),
      ref.instance.cancelled.subscribe(() => {
        if (!asking.busy) asking.finish({ confirmed: false });
      }),
    ];

    this.active = asking;

    return asking.done;
  }
}
