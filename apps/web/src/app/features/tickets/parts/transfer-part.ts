import { Component, computed, inject, input, model, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ConfirmDialog } from '@myfiesta/ui';
import { HeldTicket, TicketAnswer } from '../../../core/api.types';
import { TransferApi } from '../transfer-api';

/**
 * "Send to someone": handing one ticket to somebody else, by their email,
 * once a confirmation has said what that means.
 *
 * Only where the server says it can go (`transferable`): a working ticket
 * nobody has come in on, not given back, before the night starts. Null — a
 * server that does not say — draws nothing, the same as no.
 *
 * The tickets page places it once on each ticket, under giving it back. It is
 * handed what it needs to act alone:
 * - `token`, the link's credential, which every request on the page is made
 *   with;
 * - `working`, shared with the page: the ticket a request is running for, so
 *   that while one runs every other link on the page waits, and while this
 *   one runs the page's own do too;
 * - `answered`, for what the server said, which the page shows and redraws
 *   from, as it does for giving a ticket back.
 *
 * `contents`, so the part adds no box of its own to the ticket.
 */
@Component({
  selector: 'app-transfer-part',
  host: { class: 'contents' },
  imports: [FormsModule],
  template: `
    @if (ticket().transferable === true) {
      @if (!open()) {
        <button
          class="send cursor-pointer sm:col-start-2 sm:-mt-4 sm:ml-6 sm:justify-self-start border-0 bg-transparent p-0 text-sm font-medium text-text-muted underline underline-offset-2 [font-family:inherit] hover:text-text"
          type="button"
          [disabled]="busy()"
          (click)="open.set(true)"
        >
          Send to someone
        </button>
      } @else {
        <form
          class="transfer grid w-full sm:col-span-2 gap-3 rounded-md border border-border-subtle bg-surface-sunken p-4 text-left"
          aria-label="Send this ticket to someone"
          (ngSubmit)="send()"
        >
          <p class="m-0 text-sm text-text-muted">
            They get it by email with a new code, and a link of their own to open it.
          </p>

          <div class="field">
            <label [for]="'transfer-name-' + ticket().id">Their name</label>
            <input
              [id]="'transfer-name-' + ticket().id"
              name="name"
              autocomplete="off"
              maxlength="120"
              required
              [ngModel]="name()"
              (ngModelChange)="name.set($event)"
            />
          </div>

          <div class="field">
            <label [for]="'transfer-email-' + ticket().id">Their email</label>
            <input
              [id]="'transfer-email-' + ticket().id"
              name="email"
              type="email"
              inputmode="email"
              autocomplete="off"
              autocapitalize="off"
              spellcheck="false"
              maxlength="255"
              required
              [attr.aria-invalid]="error() ? 'true' : null"
              [attr.aria-describedby]="error() ? 'transfer-error-' + ticket().id : null"
              [ngModel]="email()"
              (ngModelChange)="email.set($event)"
            />
            @if (error(); as message) {
              <p class="field__error" role="alert" [id]="'transfer-error-' + ticket().id">{{ message }}</p>
            }
          </div>

          <div class="flex flex-wrap items-center gap-4">
            <button class="btn btn--primary btn--sm" type="submit" [disabled]="busy() || !ready()">
              {{ sending() ? 'Sending…' : 'Send the ticket' }}
            </button>
            <button class="btn-link text-sm" type="button" [disabled]="sending()" (click)="close()">Cancel</button>
          </div>
        </form>
      }
    }
  `,
})
export class TransferPart {
  private readonly api = inject(TransferApi);
  private readonly confirmDialog = inject(ConfirmDialog);

  readonly token = input.required<string>();
  readonly ticket = input.required<HeldTicket>();
  /** The ticket a request on the page is running for, or null. */
  readonly working = model<string | null>(null);
  readonly answered = output<TicketAnswer>();

  readonly open = signal(false);
  readonly name = signal('');
  readonly email = signal('');
  readonly error = signal<string | null>(null);

  readonly busy = computed(() => this.working() !== null);
  readonly sending = computed(() => this.working() === this.ticket().id);
  readonly ready = computed(() => this.name().trim() !== '' && this.email().trim() !== '');

  close(): void {
    this.open.set(false);
    this.error.set(null);
  }

  async send(): Promise<void> {
    if (this.busy() || !this.ready()) return;

    const ticket = this.ticket();
    const name = this.name().trim();
    const email = this.email().trim();

    // Said back with the address in it: a ticket sent to a typo is a ticket
    // gone, and it cannot be taken back from here.
    const sure = await this.confirmDialog.confirm({
      title: `Send your ${ticket.type ? `${ticket.type} ` : ''}ticket to ${name}?`,
      body: `It goes to ${email} with a new code. The code on this page stops getting anybody in straight away.`,
      consequences: [
        'You cannot take it back. Only they can send it on from here.',
        'Check the address: a ticket sent to a typo goes to whoever reads that inbox.',
      ],
      confirmLabel: 'Send the ticket',
      tone: 'danger',
    });

    if (!sure || this.busy()) return;

    this.working.set(ticket.id);
    this.error.set(null);

    this.api.send(this.token(), ticket.id, { email, name }).subscribe({
      next: ({ message, access }) => {
        this.working.set(null);
        this.close();
        this.answered.emit({ message, access });
      },
      error: (response) => {
        this.working.set(null);

        // A field the server could not take (an address it cannot send to)
        // is said beside the field, and the form stays as it was typed.
        // Anything else is about the ticket — the night has started, it has
        // been used — and is said where the page says everything else.
        const invalid = response?.error?.errors as Record<string, string[]> | undefined;

        if (invalid && (invalid['email'] || invalid['name'])) {
          this.error.set(invalid['email']?.[0] ?? invalid['name']?.[0] ?? 'Check the name and address.');

          return;
        }

        this.close();
        this.answered.emit({
          message: response?.error?.message ?? 'That could not be sent just now. Try again in a moment.',
          access: null,
        });
      },
    });
  }
}
