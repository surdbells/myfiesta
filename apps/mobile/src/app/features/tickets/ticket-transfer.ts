import { Component, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { ApiError, type Ticket } from '../../core/api';
import { Dialogs, MfButton, MfField, MfSheet, ToastStore } from '../../ui';
import { TicketTransferApi } from './transfer-api';

/**
 * Sending this ticket to somebody else.
 *
 * Only where the server says it can go (`transferable`): a working ticket
 * nobody has come in on, not given back, before the night starts. A ticket
 * saved on this phone before the server said falls back to whether it is
 * still a working one; the server asks again either way.
 *
 * The copy says what really happens. It used to say the ticket "stops working
 * on this phone straight away", when the code went on working — the same
 * code, now in two people's hands. Now the new holder gets a new code and an
 * email with a link of their own, and the code on this phone stops getting
 * anybody in.
 *
 * `display: contents`, so it sits in the screen's column of buttons and takes
 * no room when there is nothing to offer.
 */
@Component({
  selector: 'mf-ticket-transfer',
  imports: [FormsModule, MfButton, MfField, MfSheet],
  template: `
    @if (sendable()) {
      <button mfButton variant="ghost" block (click)="open.set(true)">Send to somebody else</button>
    } @else if (closed()) {
      <p class="closed subtle">This ticket can no longer be sent to somebody else.</p>
    }

    <mf-sheet
      [open]="open()"
      heading="Send this ticket"
      subheading="They get it by email with a new code. The code on this phone stops getting anybody in, and you cannot take it back."
      (closed)="open.set(false)"
    >
      <form class="transfer" (ngSubmit)="send()">
        <mf-field label="Their name">
          <input #control name="name" autocomplete="off" maxlength="120" [ngModel]="name()" (ngModelChange)="name.set($event)" />
        </mf-field>

        <mf-field label="Their email" [error]="error()">
          <input
            #control
            name="email"
            type="email"
            inputmode="email"
            autocapitalize="off"
            autocorrect="off"
            spellcheck="false"
            maxlength="255"
            [ngModel]="email()"
            (ngModelChange)="email.set($event)"
          />
        </mf-field>

        <button mfButton type="submit" block label="Sending…" [loading]="sending()" [disabled]="!ready()">
          Send the ticket
        </button>
      </form>
    </mf-sheet>
  `,
  styles: `
    :host {
      display: contents;
    }

    .closed {
      margin: 0;
      font-size: var(--font-size-sm);
      text-align: center;
    }

    .transfer {
      display: grid;
      gap: var(--space-4);
      padding-top: var(--space-2);
    }
  `,
})
export class MfTicketTransfer {
  private readonly api = inject(TicketTransferApi);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);
  private readonly router = inject(Router);

  /** The ticket on screen. */
  readonly ticket = input.required<Ticket>();

  readonly open = signal(false);
  readonly sending = signal(false);
  readonly name = signal('');
  readonly email = signal('');
  readonly error = signal<string | null>(null);

  /** Whether to offer it: the server's word, or for a ticket saved before it said, whether it still works. */
  readonly sendable = computed(() => {
    const held = this.ticket();

    return held.transferable ?? held.status === 'valid';
  });

  /**
   * Said rather than the button quietly missing, for a ticket that still
   * works but cannot go — the night has started, or some of a table are in.
   * Not for one already used, which says so above the code.
   */
  readonly closed = computed(() => this.ticket().transferable === false && this.ticket().status === 'valid');

  readonly ready = computed(() => this.name().trim() !== '' && this.email().trim() !== '');

  async send(): Promise<void> {
    const held = this.ticket();
    if (this.sending() || !this.ready()) return;

    const name = this.name().trim();
    const email = this.email().trim();

    // Said back with the address in it: a ticket sent to a typo is a ticket
    // gone, and this one cannot be taken back.
    const sure = await this.dialogs.confirm({
      title: `Send your ${held.type ?? ''} ticket to ${name}?`.replace(/\s+/g, ' '),
      body: `Your ticket for ${held.event.title} goes to ${email} with a new code. The code on this phone stops getting anybody in.`,
      consequences: ['You cannot take it back.'],
      confirmLabel: 'Send the ticket',
      tone: 'danger',
    });

    if (!sure || this.sending()) return;

    this.sending.set(true);
    this.error.set(null);

    try {
      const { message } = await this.api.send(held.id, { email, name });

      this.open.set(false);
      this.toasts.show(message, 'success');
      await this.router.navigate(['/tickets'], { replaceUrl: true });
    } catch (error) {
      this.error.set(error instanceof ApiError ? error.message : 'That could not be sent.');
    } finally {
      this.sending.set(false);
    }
  }
}
