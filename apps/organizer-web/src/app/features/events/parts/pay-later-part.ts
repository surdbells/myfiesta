import { Component, input, output } from '@angular/core';
import { OrganizerEventDetail } from '../../../core/api.types';

/**
 * Letting buyers pay later with Klarna or Affirm (`pay_later_enabled`), an
 * opt-in with a confirmation naming the fee, on the event's Settings.
 *
 * The PAY track's own file. Settings places it once, below its form, and
 * never edits it again, so the feature fills this in without touching the
 * page. Until then it draws nothing. Outside the form on purpose: it saves on
 * its own, after its confirmation, rather than with "Save changes".
 *
 * `changed` hands back the event as it stands after a write, read again
 * from GET /organizer/events/{id} once the part's own call has answered (the
 * API puts each feature's field on it: OrganizerEventExtras). Settings keeps
 * it without refilling its form, so unsaved edits there survive.
 *
 * `contents`, so an empty part adds no box and no gap to the page.
 */
@Component({
  selector: 'app-pay-later-part',
  host: { class: 'contents' },
  template: ``,
})
export class PayLaterPart {
  readonly event = input.required<OrganizerEventDetail>();
  readonly changed = output<OrganizerEventDetail>();
}
