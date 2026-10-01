import { Component, input } from '@angular/core';
import { EventDetail } from '../../core/api.types';

/**
 * "Tell me when tickets go on sale", for a night with nothing on sale yet
 * (`notify_on_sale`, which the server works out: on, still to come, not sold
 * out, and no public tier selling).
 *
 * The waitlist feature's own file, in both places it is offered: the event
 * page, in the price card, and the ticket page, above the tiers. Each places
 * it once and never edits it again; `place` says which it is in, for the
 * feature to draw it to suit. Until the feature fills it, it draws nothing.
 *
 * `contents`, so the part adds no box of its own to either page: empty, it
 * takes no row and no gap.
 */
@Component({
  selector: 'app-notify-on-sale-part',
  host: { class: 'contents' },
  template: ``,
})
export class NotifyOnSalePart {
  readonly event = input.required<EventDetail>();
  /** The event page's price card, or the ticket page above the tiers. */
  readonly place = input.required<'event' | 'tickets'>();
}
