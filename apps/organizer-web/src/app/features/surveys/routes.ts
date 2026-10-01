import type { Routes } from '@angular/router';
import { EventFeedback } from './event-feedback';
import { Surveys } from './surveys';

/*
 * Here rather than in app.routes.ts, which mounts these lists once, so a
 * screen this feature adds below them is added in this folder.
 */

/** Everything under /surveys. */
export const SURVEYS_ROUTES: Routes = [{ path: '', component: Surveys }];

/** Everything under one event's Feedback tab, events/:id/feedback. */
export const FEEDBACK_ROUTES: Routes = [{ path: '', component: EventFeedback }];
