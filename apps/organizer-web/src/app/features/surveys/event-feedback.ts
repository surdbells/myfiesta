import { Component } from '@angular/core';

/**
 * One event's survey and its results, the Feedback tab, filled in by the
 * SURVEY track.
 *
 * A heading and nothing else until then. The child route under events/:id is
 * registered already, and the tab stays out of the strip while
 * surveys/enabled.ts has it switched off.
 */
@Component({
  selector: 'app-event-feedback',
  template: `<h1 class="mb-6 text-2xl">Feedback</h1>`,
})
export class EventFeedback {}
