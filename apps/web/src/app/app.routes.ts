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
    path: '',
    loadComponent: () => import('./features/events/event-list').then((m) => m.EventList),
  },
  {
    path: 'events',
    loadComponent: () => import('./features/events/event-list').then((m) => m.EventList),
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
    path: ':slug',
    loadComponent: () => import('./features/events/event-detail').then((m) => m.EventDetail),
  },
];
