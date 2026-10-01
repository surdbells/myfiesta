import { Component, inject } from '@angular/core';
import { Seo } from '../../core/seo';

/**
 * One flex pass on an organizer's page, and buying it: o/:slug/passes/:pass.
 *
 * Under the organizer's own address, since a pass is theirs and spans their
 * nights. Belongs to the passes feature, which fills it in; until then it is
 * a heading, and answers 404 and stays out of search as the address did
 * before.
 */
@Component({
  selector: 'mf-pass',
  template: `
    <section class="wrap frame-[720px] pb-24 pt-8">
      <h1>Flex pass</h1>
    </section>
  `,
})
export class Pass {
  constructor() {
    inject(Seo).notFound('Flex pass');
  }
}
