import { Component } from '@angular/core';

/**
 * Who is signed in, over "What's on": their photo, or their initials, beside
 * a greeting for the time of day where they are and their first name. Tapping
 * it opens Settings.
 *
 * A place held on the home screen so the feature that fills it never has to
 * edit that screen's template. It shows nothing yet, and takes no room while
 * it does. It is marked for the screen's `screenLead` slot, which puts it
 * above the heading once the screen has one; until then it falls into the
 * screen's body, where an empty part changes nothing.
 */
@Component({
  selector: 'mf-profile-chip',
  template: ``,
  styles: `
    :host {
      display: contents;
    }
  `,
})
export class MfProfileChip {}
