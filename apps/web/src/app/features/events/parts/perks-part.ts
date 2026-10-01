import { Component, input } from '@angular/core';
import { EventDetail } from '../../../core/api.types';

/**
 * What Fiesta Points buy at this night (`perks`, empty when it offers
 * nothing).
 *
 * The points feature's own file. The event page places it once, under the
 * words about the night, and never edits it again. Until the feature fills
 * it, it draws nothing.
 *
 * `contents`, so the part adds no box of its own to the column's grid:
 * empty, it takes no row and no gap.
 */
@Component({
  selector: 'app-perks-part',
  host: { class: 'contents' },
  template: ``,
})
export class PerksPart {
  readonly event = input.required<EventDetail>();
}
