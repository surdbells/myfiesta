/** Going on sale at a set time, and repeating nights that put themselves on sale. */

import type { Availability } from '@myfiesta/shared/availability';
import type { Series } from './index';

/**
 * Another date of a repeating night, under "More dates" on its event page
 * (`other_dates`, null for a night that does not repeat): on sale, still to
 * come, soonest first, at most eight.
 */
export interface OtherDate {
  slug: string;
  starts_at: string;
  /** As a card says it: "Almost sold out", "Sold out". Null when there is nothing to say. */
  availability: Availability | null;
}

/** How often a series repeats, as the console offers it. */
export type SeriesFrequency = 'weekly' | 'fortnightly' | 'monthly';

/**
 * What PATCH /organizer/events/{id}/series changes. Every field is optional:
 * only what is sent changes.
 *
 * `count` and `until` are how it ends, one or the other, or both null for a
 * series that repeats until it is stopped. `frequency` can only be sent as
 * it already is: a different one is refused ("Stop this series and start a
 * new one"). `auto_publish` and `on_sale_days_before` need the permission to
 * put events on sale.
 */
export interface SeriesSettings {
  frequency?: SeriesFrequency;
  /** Every date in the series, the first included: 2 to 104. */
  count?: number | null;
  /** The last day, as "2026-12-18", in the venue's zone. That day's night is kept. */
  until?: string | null;
  /** Each new date goes on sale by itself. */
  auto_publish?: boolean;
  /** How many days before its night each date goes on sale; null is as soon as it is made. */
  on_sale_days_before?: number | null;
}

/** What changing a series did, said for the organizer in `message`. */
export interface SeriesUpdated {
  series: Series;
  /** Dates past the new end that nobody had bought into, now gone. */
  removed: number;
  /** Dates past the new end kept because somebody holds a ticket for them. */
  kept: number;
  /** Dates newly inside a longer run, made now. */
  created: number;
  message: string;
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

  /*
   * Optional, each of them, for a series a client kept from before the API
   * said.
   */
  interface Series {
    /** The choice that made the rule; null for a rule written some other way. */
    frequency?: SeriesFrequency | null;
    /** Every date it runs for, the first included, when it ends after so many. */
    count?: number | null;
    /** Its last day, in the venue's zone, when it ends on a day. */
    until?: string | null;
    /** Whether each date goes on sale by itself. */
    auto_publish?: boolean;
    /** How many days before its night each date goes on sale; null is as soon as it is made. */
    on_sale_days_before?: number | null;
  }

  interface SeriesOccurrence {
    /** When a draft date goes on sale by itself (ISO 8601), or null. */
    publish_at?: string | null;
  }
}
