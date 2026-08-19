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
    path: 'events/:id',
    canActivate: [requireSession],
    loadComponent: () => import('./features/events/event-detail').then((m) => m.EventDetail),
  },
  { path: '', pathMatch: 'full', redirectTo: 'events' },
  { path: '**', redirectTo: 'events' },
];
