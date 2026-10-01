import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const browsed = vi.hoisted(() => [] as string[]);

vi.mock('@capacitor/browser', () => ({
  Browser: {
    open: async ({ url }: { url: string }) => {
      browsed.push(url);
    },
  },
}));

import { Discover } from '../../core/discovery';
import { MfHowToVideos } from './how-to-videos';

/**
 * Settings' way to the how-to videos: the site's page, in the phone's own
 * browser, at whichever site this build talks to.
 */
describe('MfHowToVideos', () => {
  beforeEach(() => {
    browsed.length = 0;

    TestBed.configureTestingModule({
      providers: [
        {
          provide: Discover,
          useValue: { siteBase: () => 'https://staging.myfiesta.ca' },
        },
      ],
    });
  });

  it('opens help/videos on the site in the system browser', async () => {
    const fixture = TestBed.createComponent(MfHowToVideos);
    fixture.detectChanges();

    const button = (fixture.nativeElement as HTMLElement).querySelector('button')!;
    expect(button.textContent?.trim()).toBe('Watch the how-to videos');

    button.click();
    await fixture.whenStable();

    expect(browsed).toEqual(['https://staging.myfiesta.ca/help/videos']);
  });
});
