import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Overflows } from './overflows';

@Component({
  imports: [Overflows],
  template: `<ul appOverflows #row="overflows"><li>One</li></ul>
    <p>{{ row.overflows() ? 'scrolls' : 'fits' }}</p>`,
})
class Host {}

/**
 * Whether a sideways row has anything off-screen. jsdom lays nothing out, so
 * the widths are the ones a browser would measure: a shelf of five 280px
 * cards is 1480px, in a frame 1152px wide at 1280 and 1728px wide at 1920.
 */
describe('Overflows', () => {
  let content: number;
  let box: number;
  let resized: () => void;

  beforeEach(() => {
    content = 1480;
    box = 1152;
    vi.spyOn(Element.prototype, 'scrollWidth', 'get').mockImplementation(() => content);
    vi.spyOn(Element.prototype, 'clientWidth', 'get').mockImplementation(() => box);
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  function render() {
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    fixture.detectChanges();

    return { fixture, said: () => (fixture.nativeElement as HTMLElement).querySelector('p')!.textContent };
  }

  it('says it scrolls where nothing can measure, as on the server', () => {
    // A box the row fits in, so a measurement would answer "fits": only the
    // unmeasured default can say "scrolls" here.
    box = 1728;

    expect(render().said()).toBe('scrolls');
  });

  it('says so while the row is wider than its box, and not once the window holds all of it', () => {
    vi.stubGlobal(
      'ResizeObserver',
      class {
        constructor(callback: () => void) {
          resized = callback;
        }
        observe() {}
        disconnect() {}
      },
    );
    const { fixture, said } = render();

    expect(said()).toBe('scrolls');

    box = 1728;
    resized();
    fixture.detectChanges();

    expect(said()).toBe('fits');
  });
});
