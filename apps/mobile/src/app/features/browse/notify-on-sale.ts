import { Component, input } from '@angular/core';
import type { EventPage } from '../../core/discovery';

/**
 * "Tell me when tickets go on sale", for a night with nothing on sale yet,
 * from the page's `notify_on_sale`.
 *
 * A place held under the tickets so the feature that fills it never has to
 * edit the event screen. It shows nothing yet, and takes no room while it
 * does: the tickets column keeps its spacing.
 */
@Component({
  selector: 'mf-notify-on-sale',
  template: ``,
  styles: `
    :host {
      display: contents;
    }
  `,
})
export class MfNotifyOnSale {
  /** The night on screen. */
  readonly event = input.required<EventPage>();
}
