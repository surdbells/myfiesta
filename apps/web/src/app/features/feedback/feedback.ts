import { Component, inject } from '@angular/core';
import { Seo } from '../../core/seo';

/**
 * The survey somebody is sent after a night: tickets/feedback/:token.
 *
 * Under tickets/ like the other pages a link in an email opens, and the
 * token in it is the whole credential, so it is drawn in the browser rather
 * than on the server (app.routes.server.ts). Belongs to the surveys feature,
 * which fills it in; until then it is a heading, and answers 404 as the
 * address did before.
 */
@Component({
  selector: 'mf-feedback',
  template: `
    <section class="wrap frame-[720px] pb-24 pt-8">
      <h1>How was it?</h1>
    </section>
  `,
})
export class Feedback {
  constructor() {
    inject(Seo).notFound('How was it?');
  }
}
