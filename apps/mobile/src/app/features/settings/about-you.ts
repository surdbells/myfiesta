import { Component } from '@angular/core';

/**
 * "About you (optional)": an age range, a gender and the first half of a
 * postal code, shared with organizers only as anonymous totals and only with
 * the person's say-so, which they can take back here.
 *
 * A place held among the account's cards so the feature that fills it never
 * has to edit the settings template. It shows nothing yet, and takes no room
 * while it does: no card with nothing in it.
 */
@Component({
  selector: 'mf-about-you',
  template: ``,
  styles: `
    :host {
      display: contents;
    }
  `,
})
export class MfAboutYou {}
