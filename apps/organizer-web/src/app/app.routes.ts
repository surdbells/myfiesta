import { Routes } from '@angular/router';
import { requireSession } from './core/auth.guard';

export const routes: Routes = [
  {
    path: 'register',
    loadComponent: () => import('./features/auth/register').then((m) => m.Register),
  },
  {
    path: 'forgot-password',
    loadComponent: () => import('./features/auth/forgot-password').then((m) => m.ForgotPassword),
  },
  {
    // Reached from an emailed link, carrying token and email in the query.
    path: 'reset-password',
    loadComponent: () => import('./features/auth/reset-password').then((m) => m.ResetPassword),
  },
  {
    // Where an invitation email lands. Open to anyone holding the link; the
    // page itself works out whether to sign in, sign up or just accept.
    path: 'join/:token',
    loadComponent: () => import('./features/team/join').then((m) => m.Join),
  },
  {
    // A door link. Open to anyone holding it; the link is the credential.
    path: 'door-pass/:secret',
    loadComponent: () => import('./features/door/door-pass-open').then((m) => m.DoorPassOpen),
  },
  {
    // The door on a phone holding a pass: no session, no sidebar, one screen.
    path: 'scan/:id',
    loadComponent: () => import('./features/door/door-pass-scanner').then((m) => m.DoorPassScanner),
    children: [
      {
        path: '',
        loadComponent: () => import('./features/door/door').then((m) => m.Door),
      },
    ],
  },
  {
    path: 'account',
    canActivate: [requireSession],
    loadComponent: () => import('./features/account/account').then((m) => m.Account),
  },
  {
    path: 'team',
    canActivate: [requireSession],
    loadComponent: () => import('./features/team/team').then((m) => m.Team),
  },
  {
    path: 'sign-in',
    loadComponent: () => import('./features/auth/sign-in').then((m) => m.SignIn),
  },
  {
    path: 'events',
    canActivate: [requireSession],
    loadComponent: () => import('./features/events/event-list').then((m) => m.EventList),
  },
  {
    // Above events/:id, or "new" is read as an event id.
    path: 'events/new',
    canActivate: [requireSession],
    loadComponent: () => import('./features/events/event-create').then((m) => m.EventCreate),
  },
  {
    /*
     * One event, and every screen about it.
     *
     * Children rather than siblings: the workspace loads the event once and
     * keeps its name, date and status on screen while somebody moves between
     * the tickets, the door and the orders. As eight sibling routes each one
     * re-fetched the event, drew its own heading, and offered no way back
     * except the browser.
     */
    path: 'events/:id',
    canActivate: [requireSession],
    loadComponent: () =>
      import('./features/events/event-workspace').then((m) => m.EventWorkspace),
    children: [
      {
        path: '',
        loadComponent: () => import('./features/events/event-detail').then((m) => m.EventDetail),
      },
      {
        path: 'tickets',
        loadComponent: () => import('./features/events/event-tickets').then((m) => m.EventTickets),
      },
      {
        path: 'guests',
        loadComponent: () => import('./features/events/event-guests').then((m) => m.EventGuests),
      },
      {
        path: 'door',
        loadComponent: () => import('./features/door/door').then((m) => m.Door),
      },
      {
        path: 'orders',
        loadComponent: () => import('./features/events/event-orders').then((m) => m.EventOrders),
      },
      {
        path: 'messages',
        loadComponent: () => import('./features/events/event-messages').then((m) => m.EventMessages),
      },
      {
        path: 'codes',
        loadComponent: () => import('./features/events/event-codes').then((m) => m.EventCodes),
      },
      {
        path: 'pictures',
        loadComponent: () => import('./features/events/event-pictures').then((m) => m.EventPictures),
      },
      {
        path: 'edit',
        loadComponent: () => import('./features/events/event-edit').then((m) => m.EventEdit),
      },
    ],
  },
  {
    // Organization-wide, unlike the orders tab inside an event: support
    // arrives with a reference or an address, never with the night.
    path: 'orders',
    canActivate: [requireSession],
    loadComponent: () => import('./features/orders/orders').then((m) => m.Orders),
  },
  {
    path: 'payouts',
    canActivate: [requireSession],
    loadComponent: () => import('./features/payouts/payouts').then((m) => m.Payouts),
  },
  {
    path: '',
    pathMatch: 'full',
    canActivate: [requireSession],
    loadComponent: () => import('./features/dashboard/dashboard').then((m) => m.Dashboard),
  },
  { path: '**', redirectTo: 'events' },
];
