import { Component } from '@angular/core';

/**
 * The account's photo at the top of "Signed in as", with Change photo and
 * Remove photo. It is the face on the home screen's greeting too.
 *
 * A place held in that card so the feature that fills it never has to edit
 * the settings template. It shows nothing yet, and takes no room while it
 * does. It decides for itself when to show: a door pass has no photo to
 * change.
 */
@Component({
  selector: 'mf-settings-avatar',
  template: ``,
  styles: `
    :host {
      display: contents;
    }
  `,
})
export class MfSettingsAvatar {}
