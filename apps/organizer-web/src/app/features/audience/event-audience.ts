import { Component } from '@angular/core';

/**
 * Who bought tickets to one event, the Audience tab, filled in by the
 * INSIGHT track.
 *
 * A heading and nothing else until then. The child route under events/:id is
 * registered already, and the tab stays out of the strip while
 * audience/enabled.ts has it switched off.
 */
@Component({
  selector: 'app-event-audience',
  template: `<h1 class="mb-6 text-2xl">Audience</h1>`,
})
export class EventAudience {}
