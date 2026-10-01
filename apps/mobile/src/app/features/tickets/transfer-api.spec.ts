import { TestBed } from '@angular/core/testing';
import { describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { TicketTransferApi } from './transfer-api';

/**
 * The request the phone makes: the ticket's own path, signed in, with only
 * who it goes to. The answer is passed back as it came, a sentence and no
 * ticket.
 */
describe('TicketTransferApi', () => {
  it('posts who it goes to, to the ticket being sent', async () => {
    const request = vi.fn().mockResolvedValue({ message: 'Sent to chioma@example.com.' });

    TestBed.configureTestingModule({ providers: [{ provide: Api, useValue: { request } }] });

    const answer = await TestBed.inject(TicketTransferApi).send('tk-1', { email: 'chioma@example.com', name: 'Chioma Eze' });

    expect(request).toHaveBeenCalledWith('POST', '/api/tickets/tk-1/transfer', {
      email: 'chioma@example.com',
      name: 'Chioma Eze',
    });
    expect(answer).toEqual({ message: 'Sent to chioma@example.com.' });
  });
});
