/**
 * The shared component surface.
 *
 * Consumed as source through a tsconfig path mapping rather than as a built
 * Angular library: both apps live in this repository and compile together, so a
 * build step between them would only add a stale-artifact failure mode.
 */
export { UiAlert } from './alert';
export { UiBadge } from './badge';
export { UiEmpty } from './empty';
export { UiSpinner } from './spinner';
export { UiPageHeader } from './page-header';
