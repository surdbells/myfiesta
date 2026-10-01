import type { Routes } from '@angular/router';
import { Templates } from './templates';

/**
 * Everything under /templates, loaded with the screen.
 *
 * Here rather than in app.routes.ts, which mounts this list once, so a
 * template's own page (/templates/:id, say) is added in this folder.
 */
export const TEMPLATES_ROUTES: Routes = [{ path: '', component: Templates }];
