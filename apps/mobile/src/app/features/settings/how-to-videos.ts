import { Component, inject } from '@angular/core';
import { Browser } from '@capacitor/browser';
import { Discover } from '../../core/discovery';
import { MfButton } from '../../ui';

/**
 * A way to the how-to videos on the site, opened in the system browser,
 * under the introduction in "The app".
 *
 * The site's page rather than a screen here: the videos come from YouTube's
 * player, which the site already shows from its no-cookie domain and only
 * once somebody presses play, and the phone app would otherwise have to open
 * itself up to frames from YouTube to do the same.
 *
 * Its own component in that card, so this feature never edits the settings
 * template, which another feature is changing at the same time.
 */
@Component({
  selector: 'mf-how-to-videos',
  imports: [MfButton],
  template: `
    <button mfButton class="open" variant="secondary" block (click)="open()">Watch the how-to videos</button>
    <p class="hint">A minute or two each, on buying a ticket, getting in at the door, and running your events. Opens in your browser.</p>
  `,
  styles: `
    :host {
      display: contents;
    }

    .open {
      margin-top: var(--space-4);
    }

    .hint {
      margin: var(--space-3) 0 0;
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }
  `,
})
export class MfHowToVideos {
  private readonly discover = inject(Discover);

  open(): void {
    void Browser.open({ url: `${this.discover.siteBase()}/help/videos` }).catch(() => undefined);
  }
}
