import { Routes } from '@angular/router';
import { inject, isDevMode } from '@angular/core';
import { Router } from '@angular/router';
import { SessionStore } from './core/session';
import { introFirst } from './core/intro';

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

/** The organizer screens, for accounts whose token carries the organizer scope. */
const organizerOnly = () => {
  const session = inject(SessionStore);
  const router = inject(Router);

  return session.canSeeSales() ? true : router.createUrlTree(['/']);
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
    // A first launch opens on the introduction instead (core/intro.ts).
    canActivate: [introFirst],
    loadComponent: () => import('./features/browse/home').then((m) => m.Home),
  },
  {
    // What the app does, for going out and for running events. Shown once, on
    // a first launch, and again from Settings or signing in — so no guard:
    // somebody signed out has to reach it. Its own chunk: most launches never
    // load it.
    path: 'welcome',
    loadComponent: () => import('./features/intro/intro').then((m) => m.IntroScreen),
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
    /*
     * The organizer's side of the app: everything the console does, from a
     * phone. One lazy chunk, so somebody who only ever buys tickets never
     * downloads any of it.
     */
    path: 'manage',
    canActivate: [signedIn, organizerOnly],
    loadChildren: () => import('./features/manage/manage.routes').then((m) => m.MANAGE_ROUTES),
  },
  // Where the organizer screens used to live, for links already out there.
  { path: 'events', redirectTo: 'manage/events', pathMatch: 'full' },
  { path: 'events/:id', redirectTo: 'manage/events/:id' },
  {
    path: 'settings',
    canActivate: [signedIn],
    loadComponent: () => import('./features/settings/settings').then((m) => m.Settings),
  },
  {
    // Fiesta Points: what somebody has, and what it bought. An account's
    // own, so it needs one. Nothing links here yet.
    path: 'points',
    canActivate: [signedIn],
    loadComponent: () => import('./features/points/points').then((m) => m.Points),
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
