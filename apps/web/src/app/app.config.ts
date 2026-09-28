import { provideHttpClient, withFetch } from '@angular/common/http';
import { ApplicationConfig, ErrorHandler, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideClientHydration, withEventReplay } from '@angular/platform-browser';
import { provideRouter, withInMemoryScrolling } from '@angular/router';

import { routes } from './app.routes';
import { ReportingErrorHandler } from './core/error-reporting';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    // Angular's handler, then a report to Sentry when the page names one.
    // Inert on the server and wherever no DSN is set.
    { provide: ErrorHandler, useClass: ReportingErrorHandler },
    provideRouter(
      routes,
      // Back from an event page returns to where the list was, rather than to
      // the top of it.
      withInMemoryScrolling({ scrollPositionRestoration: 'enabled', anchorScrolling: 'enabled' }),
    ),
    // withFetch so requests made during server rendering are transferred to the
    // client rather than being repeated the moment the page hydrates. Only an
    // answer that may be kept is carried over: Angular drops one marked
    // private, no-cache or no-store, which is why the API marks its public
    // reads public (apps/api/routes/api.php).
    provideHttpClient(withFetch()),
    provideClientHydration(withEventReplay()),
  ],
};
