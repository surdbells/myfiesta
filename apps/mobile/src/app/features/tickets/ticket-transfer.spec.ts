import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, type Ticket } from '../../core/api';
import { Dialogs, ToastStore } from '../../ui';
import { MfTicketTransfer } from './ticket-transfer';
import { TicketTransferApi } from './transfer-api';

function held(over: Partial<Ticket> = {}): Ticket {
  return {
    id: 'tk-1',
    code: 'QAFREE000001',
    status: 'valid',
    type: 'General',
    holder_name: 'Ada Okafor',
    event: {
      slug: 'qa-free-night-toronto',
      title: 'QA Free Night',
      starts_at: '2026-10-03T23:00:00Z',
      timezone: 'America/Toronto',
      city: 'Toronto',
    },
    receipt: null,
    transferable: true,
    ...over,
  };
}

/**
 * Sending a ticket on from the phone: only where the server says it can go,
 * asked first in words that say what really happens to the code on this
 * phone, and through the handover's own endpoint.
 */
describe('MfTicketTransfer', () => {
  let asked: { title: string; body: string }[];
  let yes: boolean;
  let send: ReturnType<typeof vi.fn>;
  let toasts: string[];

  beforeEach(() => {
    asked = [];
    yes = false;
    toasts = [];
    send = vi.fn(async () => ({ message: 'Sent to bola@example.com. The code on this phone no longer gets anybody in.' }));

    // The sheet measures itself; jsdom has nothing to measure with.
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe() {}
        disconnect() {}
      },
    );

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: Dialogs, useValue: { confirm: async (options: { title: string; body: string }) => (asked.push(options), yes) } },
        { provide: ToastStore, useValue: { show: (message: string) => toasts.push(message) } },
        { provide: TicketTransferApi, useValue: { send } },
      ],
    });
  });

  function mount(ticket: Ticket) {
    const fixture = TestBed.createComponent(MfTicketTransfer);
    fixture.componentRef.setInput('ticket', ticket);
    fixture.detectChanges();

    return fixture;
  }

  function buttons(fixture: ReturnType<typeof mount>): string[] {
    return [...(fixture.nativeElement as HTMLElement).querySelectorAll('button')]
      .map((button) => (button.textContent ?? '').replace(/\s+/g, ' ').trim())
      .filter((label) => label !== '');
  }

  it('offers to send it where the server says it can go', () => {
    expect(buttons(mount(held()))).toContain('Send to somebody else');
  });

  it('says it can no longer go, rather than the button quietly missing', () => {
    const fixture = mount(held({ transferable: false }));
    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';

    expect(buttons(fixture)).not.toContain('Send to somebody else');
    expect(text).toContain('This ticket can no longer be sent to somebody else.');
  });

  it('offers nothing on a ticket already used', () => {
    const fixture = mount(held({ status: 'checked_in', transferable: false }));
    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';

    expect(buttons(fixture)).not.toContain('Send to somebody else');
    expect(text).not.toContain('can no longer be sent');
  });

  it('falls back to whether it still works for a ticket saved before the server said', () => {
    expect(buttons(mount(held({ transferable: undefined })))).toContain('Send to somebody else');
    expect(buttons(mount(held({ transferable: undefined, status: 'checked_in' })))).not.toContain('Send to somebody else');
  });

  it('says the code on this phone stops getting anybody in, and sends nothing on no', async () => {
    const part = mount(held()).componentInstance;
    part.name.set(' Bola Ade ');
    part.email.set(' bola@example.com ');

    await part.send();

    expect(asked[0].title).toBe('Send your General ticket to Bola Ade?');
    expect(asked[0].body).toContain('bola@example.com with a new code');
    expect(asked[0].body).toContain('The code on this phone stops getting anybody in.');
    expect(asked[0].body).not.toContain('stops working on this phone straight away');
    expect(send).not.toHaveBeenCalled();
  });

  it('sends it once confirmed, says what the server said and goes back to the list', async () => {
    const fixture = mount(held());
    const part = fixture.componentInstance;
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    part.open.set(true);
    part.name.set(' Bola Ade ');
    part.email.set(' bola@example.com ');
    yes = true;

    await part.send();

    expect(send).toHaveBeenCalledWith('tk-1', { email: 'bola@example.com', name: 'Bola Ade' });
    expect(toasts).toEqual(['Sent to bola@example.com. The code on this phone no longer gets anybody in.']);
    expect(navigate).toHaveBeenCalledWith(['/tickets'], { replaceUrl: true });
    expect(part.open()).toBe(false);
  });

  it('keeps the sheet open with the reason when the server refuses', async () => {
    const part = mount(held()).componentInstance;
    part.open.set(true);
    part.name.set('Bola Ade');
    part.email.set('bola@example.com');
    yes = true;
    send.mockRejectedValueOnce(
      new ApiError('The event has started, so tickets can no longer be sent to somebody else.', 422),
    );

    await part.send();

    expect(part.error()).toBe('The event has started, so tickets can no longer be sent to somebody else.');
    expect(part.open()).toBe(true);
    expect(part.sending()).toBe(false);
  });
});
