import { TestBed } from '@angular/core/testing';
import { Meta } from '@angular/platform-browser';
import { DOCUMENT, RESPONSE_INIT } from '@angular/core';
import { EventDetail, OrganizerPage } from './api.types';
import { Seo } from './seo';

/**
 * What a shared link shows.
 *
 * The description is HTML now — sanitized, formatted, rendered on the page —
 * and a meta tag is not a page. Markup in og:description is shown to a
 * WhatsApp group as the tags it is made of, so these pin that only the plain
 * text ever reaches a tag.
 */
function event(overrides: Partial<EventDetail> = {}): EventDetail {
  return {
    slug: 'afrobeats-rooftop',
    title: 'Afrobeats Rooftop',
    city: 'Toronto',
    timezone: 'America/Toronto',
    starts_at: '2026-08-28T22:00:00Z',
    ends_at: null,
    currency: 'CAD',
    from_price: { amount: 2000, currency: 'CAD' },
    poster_url: null,
    og_image_url: null,
    description: '<h3>Lineup</h3><ul><li><strong>DJ Spinall</strong></li></ul>',
    description_text: 'Lineup DJ Spinall',
    venue: null,
    organizer: { name: 'Lagos Nights', slug: 'lagos-nights', description: null, is_verified: true, logo_url: null },
    ticket_types: [],
    gallery: [],
    ...overrides,
  } as EventDetail;
}

/** An organizer, with a night on sale and a mark of their own. */
function organizer(overrides: Partial<OrganizerPage> = {}): OrganizerPage {
  return {
    slug: 'lagos-nights',
    name: 'Lagos Nights',
    description: null,
    is_verified: true,
    logo_url: 'https://cdn.test/logo.png',
    following: false,
    upcoming: [{ ...event(), poster_url: 'https://cdn.test/poster.jpg' }],
    past: [],
    ...overrides,
  };
}

describe('Seo', () => {
  let seo: Seo;
  let meta: Meta;
  let document: Document;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    seo = TestBed.inject(Seo);
    meta = TestBed.inject(Meta);
    document = TestBed.inject(DOCUMENT);
  });

  it('puts the plain-text description in meta tags, never the markup', () => {
    seo.forEvent(event(), 'https://myfiesta.ca/afrobeats-rooftop');

    for (const selector of ['name="description"', 'property="og:description"']) {
      const content = meta.getTag(selector)?.content ?? '';

      expect(content).toContain('Lineup DJ Spinall');
      expect(content).not.toMatch(/[<>]/);
    }
  });

  /** What the page is telling Google, parsed back out of the tag. */
  const structured = (): Record<string, unknown> =>
    JSON.parse(document.querySelector('script[type="application/ld+json"]')?.textContent ?? '{}');

  it('says the event happens in a place, rather than leaving it to be guessed', () => {
    seo.forEvent(event(), 'https://myfiesta.ca/afrobeats-rooftop');

    // Every event on this platform is somebody standing in a room. Leaving the
    // mode out earns a warning on the rich result, and a warned result is one
    // that may not be shown at all.
    expect(structured()['eventAttendanceMode']).toBe('https://schema.org/OfflineEventAttendanceMode');
  });

  it('carries the organizer mark when they have one, and nothing when they do not', () => {
    seo.forEvent(event({ organizer: { name: 'Lagos Nights', slug: 'lagos-nights', description: null, is_verified: true, logo_url: 'https://cdn.test/logo.png' } } as Partial<EventDetail>), 'https://myfiesta.ca/afrobeats-rooftop');
    expect(structured()['organizer']).toMatchObject({ name: 'Lagos Nights', logo: 'https://cdn.test/logo.png' });

    seo.forEvent(event(), 'https://myfiesta.ca/afrobeats-rooftop');
    expect(structured()['organizer']).not.toHaveProperty('logo');
  });

  it('keeps markup out of the structured data Google reads', () => {
    seo.forEvent(event(), 'https://myfiesta.ca/afrobeats-rooftop');

    const script = document.querySelector('script[type="application/ld+json"]');
    const data = JSON.parse(script?.textContent ?? '{}');

    expect(data['@type']).toBe('Event');
    expect(data.description).toBe('Lineup DJ Spinall');
  });

  it('still says when, where and how much when there is no description', () => {
    seo.forEvent(event({ description: null, description_text: null }), 'https://myfiesta.ca/afrobeats-rooftop');

    const content = meta.getTag('property="og:description"')?.content ?? '';

    expect(content).toContain('Toronto');
    expect(content).toContain('From $20.00');
    expect(content).not.toContain('null');
  });

  it('keeps previews within the length the networks show', () => {
    seo.forEvent(event({ description_text: 'x'.repeat(1000) }), 'https://myfiesta.ca/afrobeats-rooftop');

    expect((meta.getTag('property="og:description"')?.content ?? '').length).toBeLessThanOrEqual(200);
  });

  it('keeps private pages out of search results, and lets the next public page back in', () => {
    seo.forPrivatePage('Your tickets');
    expect(meta.getTag('name="robots"')?.content).toBe('noindex, nofollow');

    // Moving on inside the app must not leave the event page unindexable.
    seo.forEvent(event(), 'https://myfiesta.ca/afrobeats-rooftop');
    expect(meta.getTag('name="robots"')).toBeNull();
  });

  it('points the organizer in an event result at their own page', () => {
    seo.forEvent(event(), 'https://myfiesta.ca/afrobeats-rooftop');

    expect(structured()['organizer']).toMatchObject({
      url: 'https://myfiesta.ca/o/lagos-nights',
    });
  });

  it('unfurls an organizer link with their next night, not their logo', () => {
    // A logo is a small square that arrives as a thumbnail beside a line of
    // text. The poster fills the card, and it is what they are selling.
    seo.forOrganizer(organizer(), 'https://myfiesta.ca/o/lagos-nights');

    expect(meta.getTag('property="og:image"')?.content).toBe('https://cdn.test/poster.jpg');
    expect(meta.getTag('name="twitter:card"')?.content).toBe('summary_large_image');
  });

  it('falls back to the mark when they have no night on sale', () => {
    seo.forOrganizer(organizer({ upcoming: [] }), 'https://myfiesta.ca/o/lagos-nights');

    expect(meta.getTag('property="og:image"')?.content).toBe('https://cdn.test/logo.png');
    expect(meta.getTag('name="description"')?.content).toContain('Lagos Nights');
  });

  it('describes the page as one thing at a time', () => {
    seo.forEvent(event(), 'https://myfiesta.ca/afrobeats-rooftop');
    seo.forOrganizer(organizer(), 'https://myfiesta.ca/o/lagos-nights');

    // Navigating from an event to the organizer happens in the browser with no
    // reload. Two blocks left in the head would tell a crawler this page is
    // both a party next Friday and a company.
    expect(document.querySelectorAll('script[type="application/ld+json"]').length).toBe(1);
    expect(structured()).toMatchObject({ '@type': 'Organization', name: 'Lagos Nights' });
  });

  it('takes the event structured data away again on a page that is neither', () => {
    seo.forEvent(event(), 'https://myfiesta.ca/afrobeats-rooftop');
    seo.forListing("What's on", 'Everything on sale.', 'https://myfiesta.ca/events');

    expect(document.querySelector('script[type="application/ld+json"]')).toBeNull();
  });

  it('keeps a missing page out of search, and leaves nothing of the last event behind', () => {
    seo.forEvent(event(), 'https://myfiesta.ca/afrobeats-rooftop');
    seo.notFound('Event not found');

    expect(meta.getTag('name="robots"')?.content).toBe('noindex');
    expect(document.title).toBe('Event not found — myFiesta');
    expect(document.querySelector('script[type="application/ld+json"]')).toBeNull();
    // A canonical still naming the event would tell a crawler this is it.
    expect(document.querySelector('link[rel="canonical"]')).toBeNull();
  });

  it('does nothing to a response in the browser, where there is none', () => {
    // RESPONSE_INIT is only provided while rendering on the server.
    expect(() => seo.notFound('Event not found')).not.toThrow();
    expect(() => seo.unavailable('Event unavailable')).not.toThrow();
  });
});

describe('Seo, while rendering on the server', () => {
  let seo: Seo;
  let response: ResponseInit;

  beforeEach(() => {
    response = { status: 200 };

    TestBed.configureTestingModule({
      providers: [{ provide: RESPONSE_INIT, useValue: response }],
    });

    seo = TestBed.inject(Seo);
  });

  it('answers 404 for a page that is not there', () => {
    // A "not found" page that answers 200 is a soft 404, and search engines
    // keep it under whatever the link said.
    seo.notFound('Event not found');

    expect(response.status).toBe(404);
  });

  it('answers 503 when the page could not be built, so crawlers come back', () => {
    // A 404 here would drop every event out of search the first time the API
    // had a bad minute.
    seo.unavailable('Event unavailable');

    expect(response.status).toBe(503);
  });

  it('leaves an ordinary page at 200', () => {
    seo.forEvent(event(), 'https://myfiesta.ca/afrobeats-rooftop');

    expect(response.status).toBe(200);
  });
});
