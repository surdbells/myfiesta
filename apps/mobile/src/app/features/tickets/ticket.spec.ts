import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Ticket } from '../../core/api';
import { HeldTicketStore } from '../../core/held-tickets';
import { SessionStore } from '../../core/session';
import { TicketDetail } from './ticket';

function held(event: Partial<Ticket['event']> = {}): Ticket {
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
      ...event,
    },
    receipt: null,
  };
}

/**
 * Where to go, on the ticket shown at the door.
 *
 * It said "Where: Toronto". The site's ticket page for the same order named
 * the venue and its street; now the phone does too, and a ticket saved on the
 * phone before the API sent the venue still shows its city.
 */
describe('TicketDetail, where', () => {
  let tickets: Ticket[];

  beforeEach(() => {
    // The screen measures its header; jsdom has nothing to measure with.
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
        { provide: HeldTicketStore, useValue: { list: async () => ({ tickets, stale: false }) } },
        { provide: SessionStore, useValue: { session: signal({ name: 'Ada Okafor' }), clear: async () => undefined } },
      ],
    });
  });

  async function where(): Promise<string[]> {
    const fixture = TestBed.createComponent(TicketDetail);
    fixture.componentRef.setInput('id', 'tk-1');
    fixture.detectChanges();
    await new Promise((resolve) => setTimeout(resolve));
    await fixture.whenStable();
    fixture.detectChanges();

    const row = [...(fixture.nativeElement as HTMLElement).querySelectorAll('dl div')].find(
      (div) => div.querySelector('dt')?.textContent?.trim() === 'Where',
    );

    // Line by line: the venue and its street are drawn one under the other.
    const dd = row?.querySelector('dd');
    const lines = dd?.children.length ? [...dd.children].map((line) => line.textContent ?? '') : [dd?.textContent ?? ''];

    return lines.map((line) => line.replace(/\s+/g, ' ').trim());
  }

  it('names the venue and its street', async () => {
    tickets = [held({ venue: { name: 'Harbourfront Loft', address: '8 Queens Quay West' } })];

    expect(await where()).toEqual(['Harbourfront Loft', '8 Queens Quay West, Toronto']);
  });

  it('keeps to the city for a ticket saved before the venue came with it', async () => {
    tickets = [held()];

    expect(await where()).toEqual(['Toronto']);
  });
});

/**
 * Sending it on, placed on the ticket screen: offered where the server says
 * the ticket can go, and said in words where it still works but cannot.
 */
describe('TicketDetail, sending it on', () => {
  let tickets: Ticket[];

  beforeEach(() => {
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
        { provide: HeldTicketStore, useValue: { list: async () => ({ tickets, stale: false }) } },
        { provide: SessionStore, useValue: { session: signal({ name: 'Ada Okafor' }), clear: async () => undefined } },
      ],
    });
  });

  async function screen(): Promise<string> {
    const fixture = TestBed.createComponent(TicketDetail);
    fixture.componentRef.setInput('id', 'tk-1');
    fixture.detectChanges();
    await new Promise((resolve) => setTimeout(resolve));
    await fixture.whenStable();
    fixture.detectChanges();

    return ((fixture.nativeElement as HTMLElement).textContent ?? '').replace(/\s+/g, ' ');
  }

  it('offers to send a ticket the server says can go', async () => {
    tickets = [{ ...held(), transferable: true }];

    expect(await screen()).toContain('Send to somebody else');
  });

  it('says so for a working ticket that cannot go, rather than leaving the button out', async () => {
    tickets = [{ ...held(), transferable: false }];

    const text = await screen();
    expect(text).not.toContain('Send to somebody else');
    expect(text).toContain('This ticket can no longer be sent to somebody else.');
  });
});
