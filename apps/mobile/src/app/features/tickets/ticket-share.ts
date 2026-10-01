import { Component, input } from '@angular/core';
import type { Ticket } from '../../core/api';

/**
 * The holder's friend-discount link for this night, from the ticket's
 * `share_link`: send it, and a friend who buys with it saves, and so do they.
 *
 * A place held on the ticket screen so the feature that fills it never has to
 * edit that screen. It shows nothing yet, and takes no room while it does: a
 * night with no offer should not carry an empty block about one.
 */
@Component({
  selector: 'mf-ticket-share',
  template: ``,
  styles: `
    :host {
      display: contents;
    }
  `,
})
export class MfTicketShare {
  /** The ticket on screen. */
  readonly ticket = input.required<Ticket>();
}
