import type { Routes } from '@angular/router';
import { Audience } from './audience';
import { EventAudience } from './event-audience';

/*
 * Here rather than in app.routes.ts, which mounts these lists once, so a
 * screen this feature adds below them is added in this folder.
 */

/** Everything under /audience. */
export const AUDIENCE_ROUTES: Routes = [{ path: '', component: Audience }];

/** Everything under one event's Audience tab, events/:id/audience. */
export const EVENT_AUDIENCE_ROUTES: Routes = [{ path: '', component: EventAudience }];
