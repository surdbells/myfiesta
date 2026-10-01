import { TestBed } from '@angular/core/testing';
import { TicketAccess } from '../../../core/api.types';
import { ShareLink } from '../../../core/types/share';
import { SharePart } from './share-part';

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

function order(...links: (ShareLink | null)[]): TicketAccess {
  return {
    reference: 'MF-7Q2K',
    tickets: links.map((share_link, index) => ({ id: `tk-${index}`, code: `SECRET${index}`, share_link })),
  } as unknown as TicketAccess;
}

/**
 * Friend buys, both save, on the buyer's tickets page: their own link, to
 * pass on through the share sheet or the clipboard — never this page's own
 * address, which is the tickets themselves.
 */
describe('SharePart', () => {
  const original = { share: navigator.share, clipboard: navigator.clipboard };

  afterEach(() => {
    Object.defineProperty(navigator, 'share', { value: original.share, configurable: true });
    Object.defineProperty(navigator, 'clipboard', { value: original.clipboard, configurable: true });
  });

  function render(access: TicketAccess) {
    const fixture = TestBed.createComponent(SharePart);
    fixture.componentRef.setInput('order', access);
    fixture.detectChanges();

    return fixture;
  }

  const text = (fixture: { nativeElement: HTMLElement }) => (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ').trim();

  it('draws nothing, and adds no box, for a night with no offer', () => {
    const fixture = render(order(null, null));
    const host = fixture.nativeElement as HTMLElement;

    expect(host.textContent?.trim()).toBe('');
    expect(host.children.length).toBe(0);
    expect(host.classList).toContain('contents');
  });

  it('says what both save, and whose next tickets the reward is for', () => {
    const fixture = render(order(link(), link()));

    expect(text(fixture)).toContain('Bring a friend, and you both save');
    expect(text(fixture)).toContain('They get 15% off their tickets, and once they have paid you get 15% off your next tickets from Lagos Nights');
    expect(text(fixture)).toContain('This is not the link to your tickets');
    expect(fixture.nativeElement.querySelectorAll('section').length).toBe(1);
  });

  it('counts what the link has earned, and says when it has earned all it can', () => {
    expect(text(render(order(link({ rewards_earned: 1, rewards_left: 4 }))))).toContain(
      'Earned so far: 1 code. 4 more friends can earn you one.',
    );
    expect(text(render(order(link({ rewards_earned: 5, rewards_left: 0 }))))).toContain(
      'Your link has earned all 5 of its codes. Friends still save with it.',
    );
  });

  it('hands the friend link, not this page, to the share sheet', async () => {
    const shared: ShareData[] = [];
    Object.defineProperty(navigator, 'share', {
      value: async (data: ShareData) => {
        shared.push(data);
      },
      configurable: true,
    });

    const fixture = render(order(link()));
    await fixture.componentInstance.share('https://myfiesta.test/afro-fest?ref=fabcdefghij');

    expect(shared).toHaveLength(1);
    expect(shared[0].url).toBe('https://myfiesta.test/afro-fest?ref=fabcdefghij');
    expect(shared[0].text).not.toContain('SECRET');
  });

  it('copies it where there is no share sheet, and shows it where the clipboard is refused', async () => {
    const copied: string[] = [];
    Object.defineProperty(navigator, 'share', { value: undefined, configurable: true });
    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText: async (value: string) => void copied.push(value) },
      configurable: true,
    });

    const fixture = render(order(link()));
    await fixture.componentInstance.share('https://myfiesta.test/afro-fest?ref=fabcdefghij');
    fixture.detectChanges();

    expect(copied).toEqual(['https://myfiesta.test/afro-fest?ref=fabcdefghij']);
    expect(text(fixture)).toContain('Link copied');

    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText: () => Promise.reject(new DOMException('Write permission denied.', 'NotAllowedError')) },
      configurable: true,
    });

    await fixture.componentInstance.share('https://myfiesta.test/afro-fest?ref=fabcdefghij');
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>('#friend-link')?.value).toBe(
      'https://myfiesta.test/afro-fest?ref=fabcdefghij',
    );
  });
});
