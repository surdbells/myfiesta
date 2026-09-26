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
  // Before events/:id, or "new" is read as an event id.
  { path: 'events/new', loadComponent: () => import('./event-create').then((m) => m.EventCreate) },
  { path: 'events/:id', loadComponent: () => import('./event-hub').then((m) => m.EventHub) },
  { path: 'events/:id/tickets', loadComponent: () => import('./event-tickets').then((m) => m.EventTickets) },
  { path: 'events/:id/extras', loadComponent: () => import('./event-extras').then((m) => m.EventExtras) },
  { path: 'events/:id/questions', loadComponent: () => import('./event-questions').then((m) => m.EventQuestions) },
  { path: 'events/:id/guests', loadComponent: () => import('./event-guests').then((m) => m.EventGuests) },
  { path: 'events/:id/orders', loadComponent: () => import('./event-orders').then((m) => m.EventOrders) },
  { path: 'events/:id/codes', loadComponent: () => import('./event-codes').then((m) => m.EventCodes) },
  { path: 'events/:id/messages', loadComponent: () => import('./event-messages').then((m) => m.EventMessages) },
  { path: 'events/:id/waitlist', loadComponent: () => import('./event-waitlist').then((m) => m.EventWaitlist) },
  { path: 'events/:id/reminders', loadComponent: () => import('./event-reminders').then((m) => m.EventReminders) },
  { path: 'events/:id/door', loadComponent: () => import('./event-door').then((m) => m.EventDoor) },
  { path: 'events/:id/pictures', loadComponent: () => import('./event-pictures').then((m) => m.EventPictures) },
  { path: 'events/:id/sales', loadComponent: () => import('./event-sales').then((m) => m.EventSales) },
  { path: 'events/:id/edit', loadComponent: () => import('./event-edit').then((m) => m.EventEdit) },
];
