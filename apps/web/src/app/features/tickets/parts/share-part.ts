import { Component, input } from '@angular/core';
import { TicketAccess } from '../../../core/api.types';

/**
 * The buyer's own friend-discount link for this night, to pass on: a friend
 * who buys through it saves, and so does the buyer, once the friend has paid
 * (each ticket's `share_link`, null where the night has no offer).
 *
 * The friend-discount feature's own file. The tickets page places it once,
 * under the tickets, and never edits it again. It is handed the whole order,
 * since the link is the buyer's for the night rather than any one ticket's.
 * Until the feature fills it, it draws nothing.
 *
 * `contents`, so the part adds no box of its own to the page: empty, it
 * takes no space.
 */
@Component({
  selector: 'app-share-part',
  host: { class: 'contents' },
  template: ``,
})
export class SharePart {
  readonly order = input.required<TicketAccess>();
}
