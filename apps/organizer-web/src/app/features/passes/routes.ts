import type { Routes } from '@angular/router';
import { Passes } from './passes';

/**
 * Everything under /passes, loaded with the screen.
 *
 * Here rather than in app.routes.ts, which mounts this list once, so a pass's
 * own page (/passes/:id, say) is added in this folder.
 */
export const PASSES_ROUTES: Routes = [{ path: '', component: Passes }];
