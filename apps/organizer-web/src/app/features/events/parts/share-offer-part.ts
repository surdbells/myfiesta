import { Component, input, output } from '@angular/core';
import { OrganizerEventDetail } from '../../../core/api.types';

/**
 * "Friend buys, both save": the event's share offer, on its Overview.
 *
 * The SHARE track's own file. The Overview places it once and never edits it
 * again, so the feature fills this in without touching the page. Until then it
 * draws nothing. Who may change it (codes.manage) is for the part to check:
 * the Overview shows it to everybody who can open the event.
 *
 * `changed` hands back the event as it stands after a write, read again
 * from GET /organizer/events/{id} once the part's own call has answered (the
 * API puts each feature's field on it: OrganizerEventExtras), and the Overview
 * takes it from there.
 *
 * `contents`, so an empty part adds no box and no gap to the page.
 */
@Component({
  selector: 'app-share-offer-part',
  host: { class: 'contents' },
  template: ``,
})
export class ShareOfferPart {
  readonly event = input.required<OrganizerEventDetail>();
  readonly changed = output<OrganizerEventDetail>();
}
