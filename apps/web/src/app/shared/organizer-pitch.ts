import { Component, inject } from '@angular/core';
import { CONSOLE_URL } from '../core/console-url';

/**
 * The other half of the business, addressed on the pages buyers read.
 *
 * Every organizer on this platform arrived as somebody who bought a ticket
 * first, looked at how the night was sold, and went looking for the door. That
 * door was not on the site anywhere.
 *
 * The headline is the fee model, because since the pricer was corrected it is
 * the strongest true thing there is to say: the service charge is added at
 * checkout and paid by the buyer, so the price an organizer sets is the amount
 * they are paid. Most of the market deducts theirs from the organizer's side.
 *
 * A component rather than markup in the home page, because it belongs at the
 * foot of the listing screen too — somebody who has just scrolled forty events
 * is somebody thinking about what it takes to be on that list.
 */
@Component({
  selector: 'app-organizer-pitch',
  templateUrl: './organizer-pitch.html',
  styleUrl: './organizer-pitch.css',
})
export class OrganizerPitch {
  readonly consoleUrl = inject(CONSOLE_URL);
}
