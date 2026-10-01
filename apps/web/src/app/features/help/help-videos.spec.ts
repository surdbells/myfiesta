import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { RESPONSE_INIT } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Meta } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { API_BASE_URL } from '../../core/api-base';
import { HelpVideo } from '../../core/api.types';
import { HelpVideos } from './help-videos';
import { PlayerFrame } from './player-frame';

/**
 * The how-to videos: a picture and a play button each, and YouTube's player
 * from the no-cookie domain only once somebody asks for it.
 */
describe('HelpVideos', () => {
  let http: HttpTestingController;
  let response: ResponseInit;
  let harness: RouterTestingHarness;
  let frame: { allowed: ReturnType<typeof vi.fn>; reloadToPlay: ReturnType<typeof vi.fn> };

  const buying: HelpVideo = {
    id: '0199a1b2-0000-7000-8000-000000000001',
    title: 'Buying a ticket on your phone',
    youtube_id: 'dQw4w9WgXcQ',
    description: 'From the event page to the ticket in your inbox.',
    audience: 'buyers',
  };

  const scanning: HelpVideo = {
    id: '0199a1b2-0000-7000-8000-000000000002',
    title: 'Scanning tickets at the door',
    youtube_id: 'a_b-c1234XY',
    description: null,
    audience: 'organizers',
  };

  async function open(videos: HelpVideo[], url = '/help/videos'): Promise<HTMLElement> {
    harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(url, HelpVideos);

    http.expectOne('https://api.myfiesta.test/api/help/videos').flush({ data: videos });
    harness.detectChanges();

    return harness.routeNativeElement!;
  }

  function playButton(page: HTMLElement, title: string): HTMLButtonElement {
    return page.querySelector<HTMLButtonElement>(`button[aria-label="Play: ${title}"]`)!;
  }

  beforeEach(() => {
    response = { status: 200 };
    frame = { allowed: vi.fn(() => true), reloadToPlay: vi.fn() };

    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'help/videos', component: HelpVideos }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
        { provide: RESPONSE_INIT, useValue: response },
        { provide: PlayerFrame, useValue: frame },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('shows each video as its thumbnail, under who it is for, with nothing from YouTube but the picture', async () => {
    const page = await open([buying, scanning]);

    expect([...page.querySelectorAll('h2')].map((h) => h.textContent?.trim())).toEqual([
      'Buying tickets',
      'Running your events',
    ]);
    expect(page.querySelector('iframe')).toBeNull();
    expect(page.querySelector('img')?.getAttribute('src')).toBe(
      'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
    );
    expect(page.textContent).toContain('From the event page to the ticket in your inbox.');
  });

  it('loads the player from youtube-nocookie.com only when play is pressed, one at a time', async () => {
    const page = await open([buying, scanning]);

    playButton(page, buying.title).click();
    harness.detectChanges();

    const players = page.querySelectorAll('iframe');
    expect(players.length).toBe(1);
    expect(players[0].getAttribute('src')).toBe(
      'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0',
    );
    expect(players[0].getAttribute('title')).toBe(buying.title);

    playButton(page, scanning.title).click();
    harness.detectChanges();

    expect(page.querySelectorAll('iframe').length).toBe(1);
    expect(page.querySelector('iframe')?.getAttribute('src')).toContain('/embed/a_b-c1234XY?');
    expect(playButton(page, buying.title)).not.toBeNull();
  });

  it('loads the page afresh to play, when it was reached inside the app and so cannot show a player', async () => {
    frame.allowed.mockReturnValue(false);
    const page = await open([buying]);

    playButton(page, buying.title).click();
    harness.detectChanges();

    expect(frame.reloadToPlay).toHaveBeenCalledWith('dQw4w9WgXcQ');
    expect(page.querySelector('iframe')).toBeNull();
  });

  it('opens the video it was loaded afresh to play', async () => {
    const page = await open([buying, scanning], '/help/videos?play=a_b-c1234XY');

    expect(page.querySelector('iframe')?.getAttribute('src')).toContain('/embed/a_b-c1234XY?');
  });

  it('says there are none yet, and keeps the empty page out of search', async () => {
    const page = await open([]);

    expect(page.textContent).toContain('There are no videos here yet');
    expect(page.querySelector('h2')).toBeNull();
    expect(TestBed.inject(Meta).getTag('name="robots"')?.content).toBe('noindex');
  });

  it('never builds an address from an id that is not YouTube’s shape', async () => {
    const page = await open([{ ...buying, youtube_id: 'x" onerror="alert(1)' }, scanning]);

    expect(page.querySelectorAll('li').length).toBe(1);
    expect(page.textContent).not.toContain(buying.title);
  });

  it('answers 503 when the API did not answer, rather than saying there are none', async () => {
    harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/help/videos', HelpVideos);

    http
      .expectOne('https://api.myfiesta.test/api/help/videos')
      .error(new ProgressEvent('error'), { status: 0, statusText: 'Unknown Error' });
    harness.detectChanges();

    expect(response.status).toBe(503);
    expect(harness.routeNativeElement?.textContent).toContain('could not be loaded');
    expect(harness.routeNativeElement?.textContent).not.toContain('no videos here yet');
  });
});
