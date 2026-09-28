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
    // A category's and a city's page: indexed, shared, and answering 404
    // for a slug that is not one — all of which only a server render can do.
    path: 'events/category/:category',
    renderMode: RenderMode.Server,
  },
  {
    path: 'events/city/:city',
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
    // A buyer's own tickets and order: private, never unfurled or indexed,
    // so nothing is gained by rendering them on the server — and something
    // is lost. Rendered there, the ticket codes went into the server's HTML
    // and its transfer cache, and the API recorded every "tickets opened"
    // as our own server's address instead of the buyer's, which is the one
    // fact a bank asks about when a buyer says they never got them.
    path: 'tickets/:token',
    renderMode: RenderMode.Client,
  },
  {
    path: 'order/:reference',
    renderMode: RenderMode.Client,
  },
  {
    path: 'embed/order/:reference',
    renderMode: RenderMode.Client,
  },
  {
    path: '**',
    renderMode: RenderMode.Server,
  },
];
