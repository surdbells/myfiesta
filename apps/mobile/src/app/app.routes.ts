import { Routes } from '@angular/router';
import { inject, isDevMode } from '@angular/core';
import { Router } from '@angular/router';
import { SessionStore } from './core/session';

/**
 * Where a session may go.
 *
 * Convenience, not security: the API refuses what a token may not do whatever
 * the app renders. This exists so a door-staff phone shows a scanner rather
 * than a screen of failed requests, and so somebody signed out sees a sign-in
 * form rather than an empty ticket list.
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

  return session.scope() === 'door' ? true : router.createUrlTree(['/']);
};

export const routes: Routes = [
  {
    path: 'sign-in',
    loadComponent: () => import('./features/auth/sign-in').then((m) => m.SignIn),
  },
  {
    // Where a door link lands: mfiesta://door-pass/… or the pasted secret.
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
  {
    path: '',
    pathMatch: 'full',
    loadComponent: () => import('./features/home').then((m) => m.Home),
  },
  { path: '**', redirectTo: '' },
];
