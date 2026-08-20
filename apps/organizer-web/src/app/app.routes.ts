import { Routes } from '@angular/router';
import { requireSession } from './core/auth.guard';

export const routes: Routes = [
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
    path: 'events/:id/guests',
    canActivate: [requireSession],
    loadComponent: () => import('./features/events/event-guests').then((m) => m.EventGuests),
  },
  {
    path: 'events/:id',
    canActivate: [requireSession],
    loadComponent: () => import('./features/events/event-detail').then((m) => m.EventDetail),
  },
  { path: '', pathMatch: 'full', redirectTo: 'events' },
  { path: '**', redirectTo: 'events' },
];
