import { Component, input } from '@angular/core';
import { OrganizerEventDetail } from '../../../core/api.types';

/**
 * Copying an event with adjustments — its date, title, which tiers and at
 * what price — and keeping it as a template, on its Overview.
 *
 * The CLONE track's own file. The Overview places it once, beside the quick
 * "Same event, new date" copy, and never edits it again, so the feature fills
 * this in without touching the page. Until then it draws nothing. A copy is a
 * new event, so the part goes to it itself; nothing here changes.
 *
 * `contents`, so an empty part adds no box and no gap to the page.
 */
@Component({
  selector: 'app-duplicate-part',
  host: { class: 'contents' },
  template: ``,
})
export class DuplicatePart {
  readonly event = input.required<OrganizerEventDetail>();
}
