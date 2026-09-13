import { TestBed } from '@angular/core/testing';
import { Meta } from '@angular/platform-browser';
import { DOCUMENT } from '@angular/core';
import { EventDetail } from './api.types';
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
});
