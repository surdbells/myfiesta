import { Component, input } from '@angular/core';
import type { Ticket } from '../../core/api';

/**
 * Sending this ticket to somebody else, from the ticket's `transferable`.
 *
 * A place held beside the screen's own "Send to somebody else" so the feature
 * that reworks sending (a new code for the recipient, a closing time at the
 * start of the night, honest copy about the old one) has a file of its own.
 * Until then the screen keeps the flow it has, and this shows nothing and
 * takes no room in the buttons' column.
 */
@Component({
  selector: 'mf-ticket-transfer',
  template: ``,
  styles: `
    :host {
      display: contents;
    }
  `,
})
export class MfTicketTransfer {
  /** The ticket on screen. */
  readonly ticket = input.required<Ticket>();
}
