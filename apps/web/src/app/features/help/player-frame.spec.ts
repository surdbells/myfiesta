import { DOCUMENT } from '@angular/common';
import { Injector } from '@angular/core';
import { PlayerFrame } from './player-frame';

/**
 * Whether YouTube's player can show here, which the browser decided by the
 * address this document was first loaded at, and the way round it when not.
 */
describe('PlayerFrame', () => {
  let assign: ReturnType<typeof vi.fn>;

  function frameLoadedAt(name: string | null): PlayerFrame {
    assign = vi.fn();
    const entries = name === null ? [] : [{ name }];
    const document = {
      defaultView: {
        performance: { getEntriesByType: vi.fn(() => entries) },
        location: { assign },
      },
    };

    return Injector.create({
      providers: [
        { provide: DOCUMENT, useValue: document },
        { provide: PlayerFrame, useClass: PlayerFrame },
      ],
    }).get(PlayerFrame);
  }

  it.each([
    ['https://myfiesta.ca/help/videos', true],
    ['https://myfiesta.ca/help/videos/', true],
    ['https://myfiesta.ca/help/videos#abc', true],
    ['https://myfiesta.ca/help/videos?play=dQw4w9WgXcQ', true],
    ['https://myfiesta.ca/help', false],
    ['https://myfiesta.ca/events/x', false],
    ['https://myfiesta.ca/help/videos-and-more', false],
    ['https://myfiesta.ca/', false],
  ])('a document first loaded at %s may show the player: %s', (name, allowed) => {
    expect(frameLoadedAt(name).allowed()).toBe(allowed);
  });

  it('gives a browser that does not say where it loaded from the benefit of the doubt', () => {
    expect(frameLoadedAt(null).allowed()).toBe(true);
  });

  it('asks for help/videos as a new page, not a jump within the one already showing', () => {
    frameLoadedAt('https://myfiesta.ca/').reloadToPlay('dQw4w9WgXcQ');

    expect(assign).toHaveBeenCalledWith('/help/videos?play=dQw4w9WgXcQ');

    // The address bar already says help/videos (the router put it there). An
    // address that differs from it only after a # would load nothing, so the
    // one asked for has to differ before it.
    const target = new URL(assign.mock.calls[0][0], 'https://myfiesta.ca/help/videos');
    expect(target.pathname).toBe('/help/videos');
    expect(target.search).not.toBe('');
    expect(target.hash).toBe('');
  });

  it('opens on help/videos once loaded there, so the page it asks for can play', () => {
    frameLoadedAt('https://myfiesta.ca/').reloadToPlay('dQw4w9WgXcQ');
    const target: string = assign.mock.calls[0][0];

    expect(frameLoadedAt(`https://myfiesta.ca${target}`).allowed()).toBe(true);
  });
});
