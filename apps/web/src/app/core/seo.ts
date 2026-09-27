import { DOCUMENT, Injectable, RESPONSE_INIT, inject } from '@angular/core';
import { Meta, Title } from '@angular/platform-browser';
import { EventDetail, OrganizerPage } from './api.types';
import { formatMoney } from './money';

/**
 * Open Graph tags for a shared link.
 *
 * This is why the site is server-rendered. The organizer's link is the primary
 * sales channel — pasted into WhatsApp, an Instagram bio, a story — and a link
 * that unfurls with no title, image, or price reads as broken. Client-rendered
 * Angular gives a crawler an empty shell, which is exactly what the previous
 * platform did.
 */
@Injectable({ providedIn: 'root' })
export class Seo {
  private readonly title = inject(Title);
  private readonly meta = inject(Meta);
  private readonly document = inject(DOCUMENT);

  /**
   * The response being rendered, while there is one.
   *
   * Only the server has it: Angular provides it while rendering a request and
   * the browser gets null, because by the time the browser runs the status
   * has long since been sent.
   */
  private readonly response = inject(RESPONSE_INIT, { optional: true });

  forEvent(event: EventDetail, url: string): void {
    const when = new Intl.DateTimeFormat('en-CA', {
      dateStyle: 'full',
      timeStyle: 'short',
      timeZone: event.timezone,
    }).format(new Date(event.starts_at));

    const price = event.from_price ? `From ${formatMoney(event.from_price)}. ` : '';
    const where = event.venue?.name ?? event.city;
    // description_text, never description: this lands in a WhatsApp preview
    // and a search snippet, where HTML is shown as the tags it is made of.
    const description = `${when} at ${where}. ${price}${event.description_text ?? ''}`.trim();

    this.title.setTitle(`${event.title} — ${event.city}`);

    this.set([
      { name: 'description', content: description.slice(0, 200) },
      { property: 'og:type', content: 'website' },
      { property: 'og:title', content: event.title },
      { property: 'og:description', content: description.slice(0, 200) },
      { property: 'og:url', content: url },
      { property: 'og:site_name', content: 'myFiesta' },
      // Without an image the card collapses to a line of text, which converts
      // far worse than a poster.
      //
      // The dedicated 1200×630 crop, falling back to the display rendition.
      // Handing the networks a 16:9 image means each of them crops it their own
      // way, and the one that matters most crops the middle out.
      ...(this.cardImage(event)
        ? [
            { property: 'og:image', content: this.cardImage(event)! },
            { property: 'og:image:width', content: '1200' },
            { property: 'og:image:height', content: '630' },
            // Read aloud by screen readers on the platforms that support it.
            { property: 'og:image:alt', content: `Poster for ${event.title}` },
          ]
        : []),
      { name: 'twitter:card', content: this.cardImage(event) ? 'summary_large_image' : 'summary' },
    ]);

    this.indexable();
    this.canonical(url);
    this.structuredData(event, url);
  }

  /**
   * A page that is somebody's own: their tickets, their order.
   *
   * The address is the credential and the page carries names and codes, so it
   * is kept out of search results even if the link turns up somewhere a
   * crawler can see it. The server sends the same as a header; this covers
   * arriving here by navigating inside the app.
   */
  forPrivatePage(title: string): void {
    this.title.setTitle(`${title} — myFiesta`);
    this.meta.updateTag({ name: 'robots', content: 'noindex, nofollow' }, 'name="robots"');
    this.jsonLd(null);
  }

  /**
   * A page that is not there: an unpublished event, a mistyped slug, a path
   * nothing answers to.
   *
   * The friendly page still renders — somebody who followed a stale link
   * deserves a way on — but the server answers 404 instead of 200. A "not
   * found" page that answers 200 is a soft 404: a search engine keeps it,
   * under whatever the link said, and an unpublished event goes on turning up
   * in results as a page announcing that it does not exist.
   *
   * noindex as well, for the browser: navigating here inside the app sends no
   * status at all, and the tag is what is left.
   */
  notFound(title: string): void {
    this.unlisted(title);
    this.status(404);
  }

  /**
   * A page that could not be built just now — the API was down or slow.
   *
   * 503, not 404. Telling a crawler an event is gone because the API had a bad
   * minute is how every event page drops out of search at once; a 503 says
   * come back later, and it will.
   */
  unavailable(title: string): void {
    this.unlisted(title);
    this.status(503);
  }

  /** A title, no index, and nothing left over from the page before. */
  private unlisted(title: string): void {
    this.title.setTitle(`${title} — myFiesta`);
    this.meta.updateTag({ name: 'robots', content: 'noindex' }, 'name="robots"');
    this.jsonLd(null);
    // A canonical pointing at the last event would tell a crawler this page
    // is that one.
    this.document.querySelector('link[rel="canonical"]')?.remove();
  }

  /** Set the status of the response being rendered, on the server only. */
  private status(code: number): void {
    if (this.response) this.response.status = code;
  }

  /** Undo forPrivatePage when the app moves on to a public page. */
  private indexable(): void {
    this.meta.removeTag('name="robots"');
  }

  /**
   * An organizer's own page.
   *
   * The picture is their next night's poster rather than their logo. A logo is
   * a small square that unfurls as a thumbnail beside a line of text; a poster
   * fills the card — and the link an organizer shares is a link to what they
   * are putting on.
   */
  forOrganizer(organizer: OrganizerPage, url: string): void {
    const on = organizer.upcoming.length;
    const summary =
      organizer.description ??
      (on > 0
        ? `${on} ${on === 1 ? 'night' : 'nights'} on sale from ${organizer.name} on myFiesta.`
        : `Nights by ${organizer.name} on myFiesta.`);

    this.indexable();
    this.title.setTitle(`${organizer.name} — events and tickets`);

    const poster = organizer.upcoming[0]?.poster_url ?? organizer.logo_url;

    this.set([
      { name: 'description', content: summary.slice(0, 200) },
      { property: 'og:type', content: 'profile' },
      { property: 'og:title', content: organizer.name },
      { property: 'og:description', content: summary.slice(0, 200) },
      { property: 'og:url', content: url },
      { property: 'og:site_name', content: 'myFiesta' },
      ...(poster
        ? [
            { property: 'og:image', content: poster },
            { property: 'og:image:alt', content: `${organizer.name} on myFiesta` },
          ]
        : []),
      { name: 'twitter:card', content: poster ? 'summary_large_image' : 'summary' },
    ]);

    this.canonical(url);
    this.jsonLd({
      '@context': 'https://schema.org',
      '@type': 'Organization',
      name: organizer.name,
      url,
      logo: organizer.logo_url ?? undefined,
      description: organizer.description ?? undefined,
    });
  }

  forListing(heading: string, description: string, url: string): void {
    this.indexable();
    // Arriving here from an event page, in the browser: the event's own
    // structured data would otherwise still be in the head, telling a crawler
    // this page is a party in Toronto next Friday.
    this.jsonLd(null);
    this.title.setTitle(`${heading} — myFiesta`);
    this.set([
      { name: 'description', content: description },
      { property: 'og:title', content: heading },
      { property: 'og:description', content: description },
      { property: 'og:url', content: url },
    ]);
    this.canonical(url);
  }

  private set(tags: Array<{ name?: string; property?: string; content: string }>): void {
    for (const tag of tags) {
      const selector = tag.property ? `property="${tag.property}"` : `name="${tag.name}"`;
      this.meta.updateTag(tag, selector);
    }
  }

  /**
   * The picture a shared link unfurls with.
   *
   * Falls back to the poster because an event imported from the old platform
   * has a picture and no 1200×630 crop of it — a card with a badly-cropped
   * image still beats a card with none.
   */
  private cardImage(event: EventDetail): string | null {
    return event.og_image_url ?? event.poster_url;
  }

  private canonical(url: string): void {
    let link = this.document.querySelector<HTMLLinkElement>('link[rel="canonical"]');

    if (!link) {
      link = this.document.createElement('link');
      link.setAttribute('rel', 'canonical');
      this.document.head.appendChild(link);
    }

    link.setAttribute('href', url);
  }

  /**
   * schema.org Event, so search results show the date and venue directly.
   *
   * Cheap to emit and the only way a listing gets a rich result rather than a
   * blue link.
   */
  private structuredData(event: EventDetail, url: string): void {
    this.jsonLd({
      '@context': 'https://schema.org',
      '@type': 'Event',
      name: event.title,
      startDate: event.starts_at,
      endDate: event.ends_at ?? undefined,
      eventStatus: 'https://schema.org/EventScheduled',
      // Said explicitly. Google has warned about events that leave it out
      // since everything moved online in 2020, and a warning on a rich result
      // is a rich result that may not be shown.
      eventAttendanceMode: 'https://schema.org/OfflineEventAttendanceMode',
      url,
      image: event.poster_url ?? undefined,
      // The plain-words version: Google shows this as text, and markup here
      // would put the tags themselves in front of a searcher.
      description: event.description_text ?? undefined,
      location: {
        '@type': 'Place',
        name: event.venue?.name ?? event.city,
        address: {
          '@type': 'PostalAddress',
          streetAddress: event.venue?.address ?? undefined,
          addressLocality: event.city,
          addressRegion: event.subdivision ?? undefined,
          addressCountry: event.country,
        },
      },
      organizer: {
        '@type': 'Organization',
        name: event.organizer.name,
        // Their own page, which is a real address a crawler can follow and
        // the same one a reader sees under "Organized by".
        url: `${this.origin(url)}/o/${event.organizer.slug}`,
        // Their mark, where they have uploaded one.
        logo: event.organizer.logo_url ?? undefined,
      },
      offers: event.from_price
        ? {
            '@type': 'Offer',
            price: (event.from_price.amount / 100).toFixed(2),
            priceCurrency: event.from_price.currency,
            availability: event.is_sold_out
              ? 'https://schema.org/SoldOut'
              : 'https://schema.org/InStock',
            url,
          }
        : undefined,
    });
  }

  /** The site's own origin, taken from the page's canonical address. */
  private origin(url: string): string {
    try {
      return new URL(url).origin;
    } catch {
      return 'https://myfiesta.ca';
    }
  }

  /**
   * The one block of structured data this page is making.
   *
   * One element, replaced rather than added to: navigating from an event to
   * an organizer happens in the browser without a reload, and two blocks in
   * the head would tell a crawler the page is both. Null clears it, for the
   * pages that are neither.
   */
  private jsonLd(data: Record<string, unknown> | null): void {
    const id = 'structured-data';
    this.document.getElementById(id)?.remove();

    if (data === null) return;

    const script = this.document.createElement('script');
    script.id = id;
    script.type = 'application/ld+json';
    script.textContent = JSON.stringify(data);
    this.document.head.appendChild(script);
  }
}
