/**
 * The shared component surface.
 *
 * Consumed as source through a tsconfig path mapping rather than as a built
 * Angular library: both apps live in this repository and compile together, so a
 * build step between them would only add a stale-artifact failure mode.
 *
 * One implementation of each thing. The rule this package exists to enforce is
 * that a second, slightly different version of a button, a card or a status
 * pill never gets written in a screen — which is what 2,368 lines of per-screen
 * CSS turned out to be.
 */

// Actions
export { UiButton } from './button';

// Surfaces
export { UiCard } from './card';
export { UiModal } from './modal';
export { UiDrawer } from './drawer';
export { UiConfirm } from './confirm';

// Navigation
export { UiPageHeader } from './page-header';
export { UiTabs, type TabLink } from './tabs';
export { UiBreadcrumb, type Crumb } from './breadcrumb';

// Data
export { UiTable, UiSortHeader, type Sort, type SortDirection } from './table';
export { UiPagination } from './pagination';

// Forms
export { UiField } from './field';

// Status and feedback
export { UiAlert, type AlertTone } from './alert';
export { UiBadge } from './badge';
export { UiToasts, ToastStore, type Toast } from './toast';

// The states every screen has and few of them designed
export { UiEmpty } from './empty';
export { UiErrorState } from './error-state';
export { UiSpinner } from './spinner';
export { UiSkeleton } from './skeleton';
