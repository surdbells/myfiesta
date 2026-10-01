import type { Routes } from '@angular/router';
import { EventPerks } from './event-perks';

/**
 * Everything under one event's Perks tab, events/:id/perks.
 *
 * Here rather than in app.routes.ts, which mounts this list once, so a screen
 * this feature adds below it is added in this folder.
 */
export const PERKS_ROUTES: Routes = [{ path: '', component: EventPerks }];
