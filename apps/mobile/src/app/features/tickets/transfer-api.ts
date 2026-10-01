import { Injectable, inject } from '@angular/core';
import type { TicketSent, TicketTransferRequest } from '@myfiesta/api-types';
import { Api } from '../../core/api';

/**
 * Sending a held ticket to somebody else, on the server.
 *
 * Its own file rather than more of core/api.ts, so the features built at the
 * same time never edit the same lines.
 */
@Injectable({ providedIn: 'root' })
export class TicketTransferApi {
  private readonly api = inject(Api);

  /**
   * Only what was done comes back, never the ticket: as it now is, it carries
   * the new holder's code, which this phone must not be handed.
   */
  send(ticketId: string, to: TicketTransferRequest): Promise<TicketSent> {
    return this.api.request('POST', `/api/tickets/${encodeURIComponent(ticketId)}/transfer`, to);
  }
}
