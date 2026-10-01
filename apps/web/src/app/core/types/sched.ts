/** Going on sale at a set time, and repeating nights that put themselves on sale. */

import type { Availability } from '@myfiesta/shared/availability';

/**
 * Another date of a repeating night, under "More dates" on its event page
 * (`other_dates`, null for a night that does not repeat). Until the feature
 * fills it, the API always sends null.
 */
export interface OtherDate {
  slug: string;
  starts_at: string;
  /** As a card says it: "Almost sold out", "Sold out". Null when there is nothing to say. */
  availability: Availability | null;
}
