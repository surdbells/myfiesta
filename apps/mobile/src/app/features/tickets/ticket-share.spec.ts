import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ShareLink } from '@myfiesta/api-types';
import { ApiError, type Ticket } from '../../core/api';
import { ToastStore } from '../../ui';
import { ShareApi } from './share-api';
import { MfTicketShare } from './ticket-share';

const shared = vi.hoisted(() => ({ calls: [] as unknown[] }));

vi.mock('@capacitor/share', () => ({
  Share: {
    share: async (options: unknown) => {
      shared.calls.push(options);
    },
  },
}));

function link(overrides: Partial<ShareLink> = {}): ShareLink {
  return {
    url: 'https://myfiesta.test/afro-fest?ref=fabcdefghij',
    discount_bps: 1500,
    rewards_earned: 0,
    rewards_left: 5,
    organizer: 'Lagos Nights',
    ...overrides,
  };
}

function ticket(overrides: Partial<Ticket> = {}): Ticket {
  return {
    id: 'tk-1',
    code: 'QAFREE000001',
    status: 'valid',
    type: 'General',
    holder_name: 'Ada Okafor',
    event: { slug: 'afro-fest', title: 'Afro Fest', starts_at: '2026-11-14T01:00:00Z', timezone: 'America/Toronto', city: 'Toronto' },
    share_link: link(),
    ...overrides,
  };
}

/**
 * Friend buys, both save, on the phone's ticket: the holder's link goes out
 * through the phone's share sheet, never the ticket's code; somebody handed a
 * ticket asks for a link of their own; a night with no offer shows nothing.
 */
describe('MfTicketShare', () => {
  let asked: string[];
  let answer: () => Promise<ShareLink>;
  let toasts: string[];

  beforeEach(() => {
    shared.calls = [];
    asked = [];
    toasts = [];
    answer = async () => link();

    TestBed.configureTestingModule({
      providers: [
        {
          provide: ShareApi,
          useValue: {
            link: (slug: string) => {
              asked.push(slug);

              return answer();
            },
          },
        },
        { provide: ToastStore, useValue: { show: (text: string) => toasts.push(text) } },
      ],
    });
  });

  async function render(held: Ticket) {
    const fixture = TestBed.createComponent(MfTicketShare);
    fixture.componentRef.setInput('ticket', held);
    fixture.detectChanges();
    await fixture.whenStable();

    return fixture;
  }

  const text = (fixture: { nativeElement: HTMLElement }) => (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ').trim();

  function button(fixture: { nativeElement: HTMLElement }, label: string): HTMLButtonElement {
    const found = [...fixture.nativeElement.querySelectorAll<HTMLButtonElement>('button')].find((candidate) =>
      candidate.textContent?.replace(/\s+/g, ' ').trim().startsWith(label),
    );

    if (!found) throw new Error(`No "${label}" button. It says: ${text(fixture)}`);

    return found;
  }

  it('shares the link, never the ticket code, through the share sheet', async () => {
    const fixture = await render(ticket());

    expect(text(fixture)).toContain('A friend who buys with your link gets 15% off their tickets');
    expect(text(fixture)).toContain('from Lagos Nights');
    expect(text(fixture)).not.toContain('QAFREE000001');

    button(fixture, 'Share your link').click();
    await fixture.whenStable();

    expect(shared.calls).toHaveLength(1);
    const options = shared.calls[0] as { url: string; text: string };
    expect(options.url).toBe('https://myfiesta.test/afro-fest?ref=fabcdefghij');
    expect(options.text).not.toContain('QAFREE000001');
  });

  it('asks for a link for somebody handed a ticket, and then offers to share it', async () => {
    const fixture = await render(ticket({ share_link: link({ url: null }) }));

    button(fixture, 'Get my link').click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(asked).toEqual(['afro-fest']);
    expect(text(fixture)).toContain('https://myfiesta.test/afro-fest?ref=fabcdefghij');
    expect(button(fixture, 'Share your link')).toBeTruthy();
  });

  it('says why when the server will not make one', async () => {
    answer = async () => {
      throw new ApiError('This night has no friend discount to share.', 422);
    };
    const fixture = await render(ticket({ share_link: link({ url: null }) }));

    button(fixture, 'Get my link').click();
    await fixture.whenStable();

    expect(toasts).toEqual(['This night has no friend discount to share.']);
  });

  it('counts what the link has earned, and says when it has earned all it can', async () => {
    expect(text(await render(ticket({ share_link: link({ rewards_earned: 2, rewards_left: 3 }) })))).toContain(
      'Earned so far: 2 codes. 3 more friends can earn you one.',
    );
    expect(text(await render(ticket({ share_link: link({ rewards_earned: 5, rewards_left: 0 }) })))).toContain(
      'Your link has earned all 5 of its codes. Friends still save with it.',
    );
  });

  it('shows nothing for a night with no offer, or a ticket given back', async () => {
    expect(text(await render(ticket({ share_link: null })))).toBe('');
    expect(text(await render(ticket({ share_link: undefined })))).toBe('');
    expect(text(await render(ticket({ status: 'refunded' })))).toBe('');
  });
});
