import { Component } from '@angular/core';

/**
 * A way to the how-to videos on the site, opened in the system browser,
 * under the introduction in "The app".
 *
 * A place held in that card so the feature that fills it never has to edit
 * the settings template, which another feature is changing at the same time.
 * It shows nothing yet, and takes no room while it does.
 */
@Component({
  selector: 'mf-how-to-videos',
  template: ``,
  styles: `
    :host {
      display: contents;
    }
  `,
})
export class MfHowToVideos {}
