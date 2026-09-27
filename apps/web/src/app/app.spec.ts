import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { App } from './app';
import { routes } from './app.routes';
import { CONSOLE_URL } from './core/console-url';

/**
 * The shell, and the routes behind it.
 *
 * Both tests here guard a bug that shipped. Every organizer link on the site —
 * header, footer, the recruiting band — resolved against an empty console URL
 * and pointed back at this site, where /register fell through to the event
 * wildcard and told intending organizers "Event not found".
 */
describe('App shell', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [
        provideRouter([]),
        { provide: CONSOLE_URL, useValue: 'https://console.myfiesta.test' },
      ],
    }).compileComponents();
  });

  it('sends every organizer link to the console, not to this site', async () => {
    const fixture = TestBed.createComponent(App);
    await fixture.whenStable();

    const el = fixture.nativeElement as HTMLElement;
    const sell = el.querySelector<HTMLAnchorElement>('.site-header__sell');
    const signIn = el.querySelector<HTMLAnchorElement>('.site-header__signin');

    expect(sell?.getAttribute('href')).toBe('https://console.myfiesta.test/register');
    expect(signIn?.getAttribute('href')).toBe('https://console.myfiesta.test');

    const footerConsoleLinks = Array.from(el.querySelectorAll<HTMLAnchorElement>('.site-footer a[href^="https://console"]'));
    expect(footerConsoleLinks.length).toBeGreaterThanOrEqual(2);
  });

  it('keeps the legal pages reachable from every page', async () => {
    const fixture = TestBed.createComponent(App);
    await fixture.whenStable();

    // Stripe and Paystack require published terms and a privacy policy, and
    // CASL and the NDPA a reachable operator — linked, not merely present.
    const hrefs = Array.from((fixture.nativeElement as HTMLElement).querySelectorAll('footer a')).map((a) =>
      a.getAttribute('href'),
    );

    expect(hrefs).toContain('/terms');
    expect(hrefs).toContain('/privacy');
    expect(hrefs).toContain('/contact');
    expect(hrefs).toContain('/refunds');
  });
});

describe('Routes', () => {
  const paths = routes.map((route) => route.path);
  const wildcard = paths.indexOf(':slug');

  it('matches the organizer paths before the event wildcard', () => {
    // An event lives at the root, so anything not listed above ':slug' is
    // treated as an event slug. That is how /register became "Event not found".
    for (const path of ['register', 'sign-in', 'login', 'tickets', 'events', 'help', 'terms', 'privacy', 'contact', 'refunds']) {
      expect(paths.indexOf(path), `${path} must come before :slug`).toBeGreaterThanOrEqual(0);
      expect(paths.indexOf(path), `${path} must come before :slug`).toBeLessThan(wildcard);
    }
  });

  it('keeps the event wildcard last among single segments', () => {
    const singleSegmentAfter = paths.slice(wildcard + 1).filter((path) => path && !path.includes('/'));

    expect(singleSegmentAfter).toEqual(singleSegmentAfter.filter((path) => path === '**'));
  });

  it('ends with a catch-all, so a longer unknown path still gets a page', () => {
    // Without it the router failed to match, the server stopped rendering, and
    // the visitor got a plain-text "Cannot GET".
    expect(paths.at(-1)).toBe('**');
  });
});
