import { ApplicationConfig, inject, provideAppInitializer, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { provideIonicAngular } from '@ionic/angular';
import { routes } from './app.routes';
import { SessionStore } from './core/session';
import { Theme } from './core/theme';

/**
 * Ionic is configured once, and configured down.
 *
 * `mode: 'md'` pins the platform's structural behaviour so a screen does not
 * shift by a few pixels between iOS and Android for reasons nobody can see in
 * the code — the look is ours either way. Ripples and Ionic's focus outline
 * are off: the app's own controls answer a press themselves.
 */
export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideIonicAngular({
      mode: 'md',
      rippleEffect: false,
      animated: true,
    }),
    provideRouter(routes, withComponentInputBinding()),

    /*
     * The saved theme and the saved session, before the first route resolves.
     *
     * Both live in native storage, which is asynchronous, and routing that
     * starts first makes two visible mistakes: a signed-in phone shows the
     * sign-in screen for a moment, and a phone set to dark paints one white
     * frame into somebody's face at a dark venue.
     */
    provideAppInitializer(async () => {
      const theme = inject(Theme);
      const session = inject(SessionStore);

      await theme.restore();
      await session.restore();
    }),
  ],
};
