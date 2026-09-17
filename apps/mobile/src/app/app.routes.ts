import { Routes } from '@angular/router';
import { inject, isDevMode } from '@angular/core';
import { Router } from '@angular/router';
import { SessionStore } from './core/session';

/**
 * Where a session may go.
 *
 * Convenience, not security: the API refuses what a token may not do whatever
 * the app renders. This exists so a door-staff phone shows a scanner rather
 * than a screen of failed requests, and so somebody signed out lands on
 * something they can use.
 *
 * Browsing is open. Guest checkout is the primary path on this platform, so an
 * account is asked for only where one is genuinely needed — the tickets
 * somebody already holds, and the organizer screens.
 */
const signedIn = () => {
  const session = inject(SessionStore);
  const router = inject(Router);

  if (!session.signedIn()) return router.createUrlTree(['/sign-in']);

  // A door pass has one screen. Anything else it asks for is the scanner.
  return session.locked() ? router.createUrlTree(['/door']) : true;
};

const doorOnly = () => {
  const session = inject(SessionStore);
  const router = inject(Router);

  return session.scope() === 'door' || session.canSeeSales() ? true : router.createUrlTree(['/']);
};

export const routes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    loadComponent: () => import('./features/browse/home').then((m) => m.Home),
  },
  {
    path: 'browse',
    loadComponent: () => import('./features/browse/browse').then((m) => m.Browse),
  },
  {
    // One event. `/e/` rather than `/events/`, which belongs to the organizer
    // screens — and short enough to be a link worth sharing.
    path: 'e/:slug',
    loadComponent: () => import('./features/browse/event').then((m) => m.Event),
  },
  {
    // One organizer: who they are, what is on, what has been. `/o/` matches
    // the public site, so a link shared out of the app and a link opened in
    // it are the same address.
    path: 'o/:slug',
    loadComponent: () => import('./features/browse/organizer').then((m) => m.Organizer),
  },
  {
    // A private list, so it needs an account — but the sign-in it bounces to
    // comes back here rather than dropping somebody on the tickets screen.
    path: 'saved',
    canActivate: [signedIn],
    loadComponent: () => import('./features/browse/saved').then((m) => m.Saved),
  },
  {
    path: 'following',
    canActivate: [signedIn],
    loadComponent: () => import('./features/browse/following').then((m) => m.Following),
  },
  {
    path: 'sign-in',
    loadComponent: () => import('./features/auth/sign-in').then((m) => m.SignIn),
  },
  {
    // Signing up here is for somebody going out. Putting on an event is done
    // in the console, on a screen wide enough to build one.
    path: 'join',
    loadComponent: () => import('./features/auth/join').then((m) => m.Join),
  },
  {
    path: 'forgotten-password',
    loadComponent: () =>
      import('./features/auth/forgot-password').then((m) => m.ForgotPassword),
  },
  {
    // Where a door link lands.
    path: 'door-pass/:secret',
    loadComponent: () => import('./features/door/door-pass').then((m) => m.DoorPassOpen),
  },
  {
    path: 'door',
    canActivate: [doorOnly],
    loadComponent: () => import('./features/door/door').then((m) => m.Door),
  },
  {
    path: 'tickets',
    canActivate: [signedIn],
    loadComponent: () => import('./features/tickets/tickets').then((m) => m.Tickets),
  },
  {
    path: 'tickets/:id',
    canActivate: [signedIn],
    loadComponent: () => import('./features/tickets/ticket').then((m) => m.TicketDetail),
  },
  {
    path: 'events',
    canActivate: [signedIn],
    loadComponent: () => import('./features/organizer/events').then((m) => m.Events),
  },
  {
    path: 'events/:id',
    canActivate: [signedIn],
    loadComponent: () => import('./features/organizer/event').then((m) => m.EventNight),
  },
  {
    path: 'settings',
    canActivate: [signedIn],
    loadComponent: () => import('./features/settings/settings').then((m) => m.Settings),
  },
  {
    // Development only: every control on one screen, in both themes. A design
    // system nobody can see whole is one that drifts.
    path: 'ui',
    canActivate: [() => isDevMode()],
    loadComponent: () => import('./features/gallery').then((m) => m.Gallery),
  },
  { path: '**', redirectTo: '' },
];
