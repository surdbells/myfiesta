import type { Routes } from '@angular/router';
import { Demand } from './demand';

/**
 * Everything under /demand, loaded with the screen.
 *
 * Here rather than in app.routes.ts, which mounts this list once, so a screen
 * this feature adds below it is added in this folder.
 */
export const DEMAND_ROUTES: Routes = [{ path: '', component: Demand }];
