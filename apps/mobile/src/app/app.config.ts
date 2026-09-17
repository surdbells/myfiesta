import { ApplicationConfig, inject, provideAppInitializer, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { provideIonicAngular } from '@ionic/angular';
import { routes } from './app.routes';
import { SessionStore } from './core/session';
import { Reminders } from './core/reminders';
import { Theme } from './core/theme';

/**
 * Ionic is configured once, and configured down.
 *
 * `mode: 'md'` pins the platform's structural behaviour so a screen does not
 * shift by a few pixels between iOS and Android for reasons nobody can see in
 * the code — the look is ours either way. Ripples and Ionic's focus outline
 * are off: the app's own controls answer a press themselves.
 */
/**
 * Whether the phone has been asked for less movement.
 *
 * Read once, at startup: iOS and Android both require the app to be relaunched
 * for the setting to take effect elsewhere, so re-reading it mid-session would
 * be the one place in the app that behaves differently from the rest of it.
 */
function prefersLessMotion(): boolean {
  return typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideIonicAngular({
      mode: 'md',
      rippleEffect: false,
      /*
       * Page transitions, unless the phone has been asked for less motion.
       *
       * The stylesheet already flattens every CSS animation in the app when
       * that is set, but Ionic drives its transitions in JavaScript, where a
       * media query cannot reach them — so somebody who turned motion down to
       * stop screens sliding still had the screens sliding.
       */
      animated: !prefersLessMotion(),
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
      const reminders = inject(Reminders);

      await theme.restore();
      await session.restore();
      await reminders.restore();
    }),
  ],
};
