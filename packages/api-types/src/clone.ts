/** Duplicating a night with changes, and the templates to start one from. */

import type { Money } from './index';

/**
 * A change to one tier on the way to a new event, named by the id it has in
 * the event or the template. A field left out is left as it is.
 */
export interface CopyTierChange {
  id: string;
  /** False leaves the tier out. A tier that waited for it in a price ladder then opens like any other. */
  include?: boolean;
  name?: string;
  /** Minor units. */
  price_amount?: number;
  /** Null is unlimited, which is not the same as leaving it out. */
  quantity_available?: number | null;
}

/**
 * What to change on the way to a new event made from an old one
 * (POST /api/organizer/events/{id}/duplicate) or from a template
 * (POST /api/organizer/templates/{id}/events). Everything left out is copied
 * as it is; a template needs a start.
 */
export interface CopyAdjustments {
  starts_at?: string;
  /** Without one the new event is as long as the old. */
  ends_at?: string;
  title?: string;
  /** Replaces the description when given, even empty. */
  description?: string | null;
  ticket_types?: CopyTierChange[];
  include?: { add_ons?: boolean; questions?: boolean; reminders?: boolean };
}

/** The new draft: always a draft, so it goes through review. */
export interface EventCopy {
  id: string;
  slug: string;
  title: string;
  starts_at: string;
  status: 'draft';
}

/** A tier a template keeps, named by the id to change it by. */
export interface EventTemplateTier {
  id: string;
  name: string;
  price: Money;
  quantity_available: number | null;
}

/**
 * An event kept as a starting point. Fixed when it was kept: the event it
 * came from can change or go without touching it.
 */
export interface EventTemplate {
  id: string;
  name: string;
  /** The title a new event starts with. */
  title: string;
  description: string | null;
  currency: Money['currency'];
  timezone: string;
  city: string;
  /** When the night it was kept from started. A new event takes a date of its own. */
  original_starts_at: string | null;
  /** How long that night ran, or null when it had no end. */
  length_minutes: number | null;
  poster_url: string | null;
  ticket_types: EventTemplateTier[];
  /** How many extras, questions and reminder times it keeps. */
  add_ons: number;
  questions: number;
  reminders: number;
  /** Whoever kept it, while their account exists. */
  created_by: string | null;
  created_at: string;
}
