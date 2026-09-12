import {
  ApplicationConfig,
  isDevMode,
  provideBrowserGlobalErrorListeners,
  provideZonelessChangeDetection,
} from '@angular/core';
import { provideServiceWorker } from '@angular/service-worker';
import { provideRouter, withInMemoryScrolling } from '@angular/router';
import { provideHttpClient, withFetch, withInterceptors } from '@angular/common/http';
import { routes } from './app.routes';
import { authInterceptor } from './core/api';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideZonelessChangeDetection(),
    provideRouter(routes, withInMemoryScrolling({ scrollPositionRestoration: 'top' })),
    // The interceptor attaches the token and clears the session on a 401, so
    // an expired token produces a sign-in screen rather than a console that
    // looks signed in and fails every action.
    provideHttpClient(withFetch(), withInterceptors([authInterceptor])),
    // Keeps the console itself available with no signal: a door phone that
    // reloads the page in a basement gets the door back, not the browser's
    // offline dinosaur. The ticket list and the queued scans live in
    // IndexedDB (core/door-offline.ts); this is only the app shell. API
    // requests are deliberately not cached — a stale answer about a ticket
    // is worse than no answer. Off under `ng serve`, which it would fight.
    provideServiceWorker('ngsw-worker.js', {
      enabled: !isDevMode(),
      registrationStrategy: 'registerWhenStable:30000',
    }),
  ],
};
