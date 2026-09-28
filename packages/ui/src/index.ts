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

// Icons
export { UiIcon, type IconSize, type LucideIconData } from './icon';

// Actions
export { UiButton } from './button';

// Surfaces
export { UiCard } from './card';
export { UiModal } from './modal';
export { UiDrawer } from './drawer';
// Every action asks first, through this: `inject(ConfirmDialog).confirm({...})`.
// The API and an example are at the top of confirm.ts and in the README.
export {
  UiConfirm,
  ConfirmDialog,
  type ConfirmRequest,
  type ConfirmResult,
  type ConfirmReason,
  type ConfirmTone,
} from './confirm';

// Navigation
export { UiPageHeader } from './page-header';
export { UiTabs, type TabLink } from './tabs';
export { UiBreadcrumb, type Crumb } from './breadcrumb';

// Data
export { UiTable, UiSortHeader, type Sort, type SortDirection } from './table';
// One list's filters, sort, page and columns, kept in the address and this browser.
export {
  createListState,
  type ListState,
  type ListConfig,
  type ListColumn,
  type FilterDef,
  type FilterKind,
  type FilterValue,
  type Density,
} from './list-state';
export { Selection, UiBulkBar } from './selection';
export { sortLocally, type SortKeys } from './local-sort';
export { UiColumnMenu } from './column-menu';
export { UiSavedViews, type ViewChoice } from './saved-views';
export { UiAmountRange, parseAmount, type AmountRange } from './amount-range';

// --- filtering and reading a table ---------------------------------------
export { UiFilterBar, type FilterChip } from './filter-bar';
export { UiDateRange, rangeZone, type DateRange } from './date-range';
export { UiStat } from './stat';
export { UiSparkline } from './sparkline';
export { UiPagination } from './pagination';

// Forms
export { UiField } from './field';
export { UiSelect, type SelectOption } from './select';

// Appearance: light, dark, or the device's
export { ThemeStore, UiThemeToggle, THEME_STORAGE_KEY, type ThemeMode } from './theme';

// Status and feedback
export { UiAlert, type AlertTone } from './alert';
export { UiBadge } from './badge';
export { UiToasts, ToastStore, type Toast } from './toast';

// The states every screen has and few of them designed
export { UiEmpty } from './empty';
export { UiErrorState } from './error-state';
export { UiSpinner } from './spinner';
export { UiSkeleton } from './skeleton';
