import { Component, input, model, output } from '@angular/core';
import { HeldTicket, TicketAnswer } from '../../../core/api.types';

/**
 * "Send to someone": handing one ticket to somebody else, by their email,
 * once a confirmation has said what that means (`transferable` says whether
 * the server would allow it).
 *
 * The transfer feature's own file. The tickets page places it once on each
 * ticket, under giving it back, and never edits it again. It is handed what
 * it needs to act alone:
 * - `token`, the link's credential, which every request on the page is made
 *   with;
 * - `working`, shared with the page: the ticket a request is running for, so
 *   that while one runs every other link on the page waits, and while this
 *   one runs the page's own do too;
 * - `answered`, for what the server said, which the page shows and redraws
 *   from, as it does for giving a ticket back.
 * Until the feature fills it, it draws nothing and does nothing.
 *
 * `contents`, so the part adds no box of its own to the ticket: empty, it
 * takes no row and no gap.
 */
@Component({
  selector: 'app-transfer-part',
  host: { class: 'contents' },
  template: ``,
})
export class TransferPart {
  readonly token = input.required<string>();
  readonly ticket = input.required<HeldTicket>();
  /** The ticket a request on the page is running for, or null. */
  readonly working = model<string | null>(null);
  readonly answered = output<TicketAnswer>();
}
