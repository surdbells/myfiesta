import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it } from 'vitest';
import type { ShareOffer } from '@myfiesta/api-types';
import type { EventPage } from '../../core/discovery';
import { ShareApi } from '../tickets/share-api';
import { MfFriendDiscount } from './friend-discount';

/**
 * The event screen opened from a friend's link says what the friend
 * discount saves — once the server says the link takes money off, and never
 * for a promoter's link or a night with no offer.
 */
describe('MfFriendDiscount', () => {
  let asked: { slug: string; ref: string }[];
  let answer: () => Promise<ShareOffer>;

  beforeEach(() => {
    asked = [];
    answer = async () => ({ discount_bps: 1500 });

    TestBed.configureTestingModule({
      providers: [
        {
          provide: ShareApi,
          useValue: {
            friendDiscount: (slug: string, ref: string) => {
              asked.push({ slug, ref });

              return answer();
            },
          },
        },
      ],
    });
  });

  async function render(offer: ShareOffer | null, ref: string | null): Promise<HTMLElement> {
    const fixture = TestBed.createComponent(MfFriendDiscount);
    fixture.componentRef.setInput('event', { slug: 'afro-fest', share_offer: offer } as unknown as EventPage);
    fixture.componentRef.setInput('ref', ref);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it('says what a working friend’s link saves, in the server’s figure', async () => {
    answer = async () => ({ discount_bps: 1250 });

    const host = await render({ discount_bps: 2000 }, 'fabcdefgh23');

    expect(asked).toEqual([{ slug: 'afro-fest', ref: 'fabcdefgh23' }]);
    expect(host.querySelector('[role="status"]')?.textContent).toContain('A friend sent you this, so you save 12.5%');
  });

  it('says nothing for a link that only looks like a friend’s', async () => {
    answer = async () => {
      throw new Error('That link takes nothing off this night.');
    };

    const host = await render({ discount_bps: 1500 }, 'fridaynight');

    expect(asked).toHaveLength(1);
    expect(host.textContent?.trim()).toBe('');
  });

  it('asks nothing for a promoter’s link, no link, or a night with no offer', async () => {
    expect((await render({ discount_bps: 1500 }, 'dj-kay')).textContent?.trim()).toBe('');
    expect((await render({ discount_bps: 1500 }, null)).textContent?.trim()).toBe('');
    expect((await render(null, 'fabcdefgh23')).textContent?.trim()).toBe('');

    expect(asked).toEqual([]);
  });
});
