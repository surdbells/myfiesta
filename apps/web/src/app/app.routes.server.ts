import { RenderMode, ServerRoute } from '@angular/ssr';

/**
 * How each route reaches a crawler.
 *
 * Event pages cannot be prerendered: organizers publish continuously, and a
 * build-time snapshot would mean a link shared an hour after publishing unfurls
 * as a 404. They are rendered per request instead, which is the whole reason
 * this app runs a server at all — a shared link that shows no title, image, or
 * price reads as broken, and that link is the primary sales channel.
 *
 * The listing is rendered per request too, since it reflects what is on sale
 * right now.
 */
export const serverRoutes: ServerRoute[] = [
  {
    path: '',
    renderMode: RenderMode.Server,
  },
  {
    path: 'events',
    renderMode: RenderMode.Server,
  },
  {
    // An organizer page, for the same reason as an event page: it is a link
    // meant to be pasted into a bio, and it has to unfurl.
    path: 'o/:slug',
    renderMode: RenderMode.Server,
  },
  {
    path: ':slug',
    renderMode: RenderMode.Server,
  },
  {
    path: '**',
    renderMode: RenderMode.Server,
  },
];
