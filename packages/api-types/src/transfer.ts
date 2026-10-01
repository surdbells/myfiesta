/** Sending a ticket to somebody else. */

/** Who a ticket is going to. The address finds their account however it is typed. */
export interface TicketTransferRequest {
  email: string;
  name: string;
}

/**
 * What the phone is told once its holder has sent a ticket on.
 *
 * Only what was done: the ticket as it now is carries the new holder's code,
 * which the phone that sent it must never be handed.
 */
export interface TicketSent {
  message: string;
}
