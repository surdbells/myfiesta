import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../core/api-base';
import { TicketAccess, TicketTransferRequest } from '../../core/api.types';

/**
 * Sending a ticket to somebody else, from a ticket link.
 *
 * Its own injectable beside the tickets page rather than another method on
 * core/api, so this feature never edits a file the others are changing too.
 */
@Injectable({ providedIn: 'root' })
export class TransferApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  /**
   * On the link's own credential, like giving one back. The answer carries
   * the page as it now is, without the ticket that went.
   */
  send(token: string, ticketId: string, to: TicketTransferRequest): Observable<{ message: string; access: TicketAccess }> {
    return this.http.post<{ message: string; access: TicketAccess }>(
      `${this.base}/api/tickets/${encodeURIComponent(token)}/transfer/${encodeURIComponent(ticketId)}`,
      to,
    );
  }
}
