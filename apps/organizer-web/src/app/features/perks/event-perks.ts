import { Component } from '@angular/core';

/**
 * One event's perks and who has claimed them, the Perks tab, filled in by the
 * POINTS track.
 *
 * A heading and nothing else until then. The child route under events/:id is
 * registered already, and the tab stays out of the strip while
 * perks/enabled.ts has it switched off.
 */
@Component({
  selector: 'app-event-perks',
  template: `<h1 class="mb-6 text-2xl">Perks</h1>`,
})
export class EventPerks {}
