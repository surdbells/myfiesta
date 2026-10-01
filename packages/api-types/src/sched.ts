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

/*
 * Fields this feature adds to a shape of index.ts, declared here rather than
 * there so no two features edit that file (TypeScript merges the two).
 */
declare module './index' {
  interface OrganizerEventDetail {
    /**
     * When it goes on sale by itself (ISO 8601), or null when nothing is
     * scheduled. Optional for a copy the console kept from before the API
     * said.
     */
    publish_at?: string | null;
  }
}
