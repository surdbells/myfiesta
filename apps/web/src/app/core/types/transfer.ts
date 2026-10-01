/** Sending a ticket to somebody else, from the tickets page. */

import type { TicketAccess } from '../api.types';

/**
 * What became of something done to a ticket from the tickets page, as the
 * part that did it hands it back: what to say, and the page as the server
 * now draws it, or null when nothing changed (a refusal, a failure). The
 * page says the one and redraws from the other, as it does for giving a
 * ticket back.
 */
export interface TicketAnswer {
  message: string;
  access: TicketAccess | null;
}
