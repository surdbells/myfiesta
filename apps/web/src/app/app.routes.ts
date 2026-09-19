import { Routes } from '@angular/router';

/**
 * Event pages are routed at the root: myfiesta.ca/{slug}.
 *
 * Carried over from the previous platform deliberately. Those URLs are in
 * shared messages, Instagram bios, and printed QR codes, and cannot be edited
 * once they are out there — moving to /e/{slug} would break every link an
 * organizer has ever handed out.
 *
 * The wildcard sits last so real routes win, and any slug that does not resolve
 * renders the not-found state on the detail page itself.
 */
export const routes: Routes = [
  {
    // The front page. Previously this was the same flat search list as
    // /events, so somebody arriving without a link had nothing to browse.
    path: '',
    loadComponent: () => import('./features/home/home').then((m) => m.Home),
  },
  {
    path: 'events',
    loadComponent: () => import('./features/events/event-list').then((m) => m.EventList),
  },
  {
    // Above the wildcard, or these read as event slugs. Each carries its own
    // page name in route data rather than being four near-identical
    // components.
    path: 'help',
    data: { page: 'help' },
    loadComponent: () => import('./features/info/info').then((m) => m.Info),
  },
  {
    path: 'terms',
    data: { page: 'terms' },
    loadComponent: () => import('./features/info/info').then((m) => m.Info),
  },
  {
    path: 'privacy',
    data: { page: 'privacy' },
    loadComponent: () => import('./features/info/info').then((m) => m.Info),
  },
  {
    path: 'contact',
    data: { page: 'contact' },
    loadComponent: () => import('./features/info/info').then((m) => m.Info),
  },
  {
    // Above the wildcard, or an order reference would be read as a slug.
    path: 'order/:reference',
    loadComponent: () => import('./features/orders/order-status').then((m) => m.OrderStatus),
  },
  {
    /*
     * The tickets somebody bought, reached from their confirmation email.
     *
     * Above the wildcard for the same reason as the order reference — below it,
     * the token is read as an event slug and every buyer following their own
     * link lands on "event not found".
     *
     * The token in the path is the whole credential: guest checkout is the
     * primary path, so most people holding a ticket have no account to sign in
     * with.
     */
    path: 'tickets/:token',
    loadComponent: () => import('./features/tickets/tickets').then((m) => m.Tickets),
  },
  {
    // The guessable organizer paths. Accounts live on the console app;
    // without these, myfiesta.ca/register fell through to the slug wildcard
    // and answered "Event not found".
    path: 'register',
    data: { consolePath: '/register' },
    loadComponent: () => import('./features/auth/go-console').then((m) => m.GoConsole),
  },
  {
    path: 'sign-in',
    data: { consolePath: '' },
    loadComponent: () => import('./features/auth/go-console').then((m) => m.GoConsole),
  },
  {
    path: 'login',
    data: { consolePath: '' },
    loadComponent: () => import('./features/auth/go-console').then((m) => m.GoConsole),
  },
  {
    // "My tickets" with no account to sign in to: explains the emailed link,
    // and looks an order reference up.
    path: 'tickets',
    loadComponent: () => import('./features/tickets/find-tickets').then((m) => m.FindTickets),
  },
  /*
   * The buying steps, inside somebody else's page.
   *
   * The same components as the ordinary flow; EmbedMode reads the prefix and
   * changes what is around them. Everything framed lives under /embed/, which
   * is how the server knows these are the only paths another site may frame.
   * The order page first, or its reference would be read as an event slug.
   */
  {
    path: 'embed/order/:reference',
    loadComponent: () => import('./features/orders/order-status').then((m) => m.OrderStatus),
  },
  {
    path: 'embed/:slug/checkout',
    loadComponent: () => import('./features/checkout/checkout').then((m) => m.Checkout),
  },
  {
    path: 'embed/:slug',
    loadComponent: () => import('./features/checkout/ticket-select').then((m) => m.TicketSelect),
  },
  {
    /*
     * An organizer's own page.
     *
     * `/o/` rather than a root slug of their own: event slugs are routed at
     * the root and are imported verbatim from the previous platform, so an
     * organizer named after one of their nights would shadow it. Two segments
     * also keeps this above the wildcard by shape rather than by luck.
     */
    path: 'o/:slug',
    loadComponent: () => import('./features/organizers/organizer').then((m) => m.Organizer),
  },
  {
    // The two checkout steps. Two-segment paths, so they cannot collide with
    // the single-segment slug wildcard below.
    path: ':slug/tickets',
    loadComponent: () => import('./features/checkout/ticket-select').then((m) => m.TicketSelect),
  },
  {
    path: ':slug/checkout',
    loadComponent: () => import('./features/checkout/checkout').then((m) => m.Checkout),
  },
  {
    path: ':slug',
    loadComponent: () => import('./features/events/event-detail').then((m) => m.EventDetail),
  },
];
