import { Routes } from '@angular/router';

/**
 * Every organizer screen, under /manage.
 *
 * Mirrors the console's structure — the organization's tools, then one event
 * and everything done to it — so somebody who knows one knows the other.
 */
export const MANAGE_ROUTES: Routes = [
  { path: '', loadComponent: () => import('./hub').then((m) => m.ManageHub) },
  { path: 'events', loadComponent: () => import('./events').then((m) => m.ManageEvents) },
  { path: 'events/:id', loadComponent: () => import('./event-hub').then((m) => m.EventHub) },
  { path: 'events/:id/tickets', loadComponent: () => import('./event-tickets').then((m) => m.EventTickets) },
  { path: 'events/:id/extras', loadComponent: () => import('./event-extras').then((m) => m.EventExtras) },
  { path: 'events/:id/questions', loadComponent: () => import('./event-questions').then((m) => m.EventQuestions) },
  { path: 'events/:id/guests', loadComponent: () => import('./event-guests').then((m) => m.EventGuests) },
  { path: 'events/:id/orders', loadComponent: () => import('./event-orders').then((m) => m.EventOrders) },
];
