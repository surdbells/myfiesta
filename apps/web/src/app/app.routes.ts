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
 * renders the not-found state on the detail page itself. Longer paths nothing
 * claims fall to the catch-all after it. Both answer 404 from the server.
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
    // A category's and a city's own page: the listing, fixed to one of them,
    // under its own title — the address somebody searching "comedy in Lagos"
    // should land on. Under /events so they can never be taken by an event
    // slug. The slug is the API's; an unknown one answers 404.
    path: 'events/category/:category',
    data: { collection: 'category' },
    loadComponent: () => import('./features/events/event-list').then((m) => m.EventList),
  },
  {
    path: 'events/city/:city',
    data: { collection: 'city' },
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
    // How-to videos. Under help/, so no word at the root is taken from the
    // events (ReservedSlugMirrorTest).
    path: 'help/videos',
    loadComponent: () => import('./features/help/help-videos').then((m) => m.HelpVideos),
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
    // How refunds actually work, linked from the terms, the footer and the
    // pay button.
    path: 'refunds',
    data: { page: 'refunds' },
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
    // The survey sent once a night is over, from the email's link: under
    // tickets/ with the rest of what a buyer is emailed, the token again the
    // whole credential.
    path: 'tickets/feedback/:token',
    loadComponent: () => import('./features/feedback/feedback').then((m) => m.Feedback),
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
    // One of the organizer's flex passes, under their own page.
    path: 'o/:slug/passes/:pass',
    loadComponent: () => import('./features/passes/pass').then((m) => m.Pass),
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
  {
    // Everything longer than one segment that nothing above claims. Without
    // it the router failed to match, the server gave up on rendering, and the
    // visitor got a bare "Cannot GET" in plain text. Answers 404.
    path: '**',
    loadComponent: () => import('./features/info/not-found').then((m) => m.NotFound),
  },
];
