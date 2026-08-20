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
    path: 'events/:id/edit',
    canActivate: [requireSession],
    loadComponent: () => import('./features/events/event-edit').then((m) => m.EventEdit),
  },
  {
    path: 'events/:id/pictures',
    canActivate: [requireSession],
    loadComponent: () => import('./features/events/event-pictures').then((m) => m.EventPictures),
  },
  {
    path: 'events/:id/orders',
    canActivate: [requireSession],
    loadComponent: () => import('./features/events/event-orders').then((m) => m.EventOrders),
  },
  {
    path: 'events/:id/codes',
    canActivate: [requireSession],
    loadComponent: () => import('./features/events/event-codes').then((m) => m.EventCodes),
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
