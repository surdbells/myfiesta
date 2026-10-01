import { Component, inject } from '@angular/core';
import { Seo } from '../../core/seo';

/**
 * How-to videos, for buyers and for organizers: help/videos.
 *
 * Under help/ rather than a word of its own at the root, which an event's
 * slug could otherwise take (ReservedSlugMirrorTest). Belongs to the public
 * site's feature, which fills it in; until then it is a heading, and answers
 * 404 and stays out of search as the address did before.
 */
@Component({
  selector: 'mf-help-videos',
  template: `
    <section class="wrap frame-[720px] pb-24 pt-8">
      <h1>How-to videos</h1>
    </section>
  `,
})
export class HelpVideos {
  constructor() {
    inject(Seo).notFound('How-to videos');
  }
}
