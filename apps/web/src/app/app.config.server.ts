import {
  ApplicationConfig,
  CSP_NONCE,
  DOCUMENT,
  REQUEST_CONTEXT,
  inject,
  mergeApplicationConfig,
} from '@angular/core';
import { BEFORE_APP_SERIALIZED } from '@angular/platform-server';
import { provideServerRendering, withRoutes } from '@angular/ssr';
import { appConfig } from './app.config';
import { serverRoutes } from './app.routes.server';

/**
 * The nonce server.ts put in this response's Content-Security-Policy.
 *
 * Angular gives it to the inline scripts it writes while rendering — the one
 * that replays a tap made before the page came alive is the one that matters.
 * Null when there is no request, which means nothing to match either.
 */
function requestNonce(): string | null {
  const context = inject(REQUEST_CONTEXT, { optional: true }) as { cspNonce?: string } | null;

  return context?.cspNonce ?? null;
}

/**
 * The nonce where Angular does not put it itself, just before the page is
 * written out.
 *
 * On the event-dispatch script the build puts in every page, which the replay
 * script needs to have run first. And as ngCspNonce on the root element: the
 * step that inlines the critical CSS reads it from there, and loads the rest
 * of the stylesheet with a script carrying it rather than an onload handler
 * the policy would refuse — which would leave every page half-styled. The
 * browser half reads it from there too.
 */
function stampNonce(): () => void {
  const nonce = inject(CSP_NONCE);
  const document = inject(DOCUMENT);

  return () => {
    if (!nonce) return;

    document.getElementById('ng-event-dispatch-contract')?.setAttribute('nonce', nonce);
    document.querySelector('app-root')?.setAttribute('ngCspNonce', nonce);
  };
}

const serverConfig: ApplicationConfig = {
  providers: [
    provideServerRendering(withRoutes(serverRoutes)),
    { provide: CSP_NONCE, useFactory: requestNonce },
    { provide: BEFORE_APP_SERIALIZED, useFactory: stampNonce, multi: true },
  ],
};

export const config = mergeApplicationConfig(appConfig, serverConfig);
