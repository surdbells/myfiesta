import { Component, input } from '@angular/core';
import type { EventPage } from '../../core/discovery';

/**
 * What Fiesta Points buy at this night, from the page's `perks`.
 *
 * A place held on the event screen so the feature that fills it never has to
 * edit that screen. It shows nothing yet, and takes no room while it does: a
 * night that offers no perks should not carry an empty section about them.
 */
@Component({
  selector: 'mf-event-perks',
  template: ``,
  styles: `
    :host {
      display: contents;
    }
  `,
})
export class MfEventPerks {
  /** The night on screen. */
  readonly event = input.required<EventPage>();
}
