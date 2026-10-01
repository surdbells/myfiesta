/** Sending a ticket to somebody else, from the tickets page. */

import type { TicketAccess } from '../api.types';

declare module '../api.types' {
  interface TicketAccess {
    /**
     * Whether this is one ticket somebody was sent, opened by the link that
     * came with it, rather than an order's own page. Such a page has the
     * ticket and nothing else of the order's: no receipt, no add-ons, no
     * reference and no order status.
     */
    sent: boolean;
  }
}

/** Who a ticket is going to. The address finds their account however it is typed. */
export interface TicketTransferRequest {
  email: string;
  name: string;
}

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
