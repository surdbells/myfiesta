/** An organizer's page (socials, past events page by page) and the how-to videos. */

import type { EventSummary } from '../api.types';

/** The networks an organizer can point to, in the order a page shows them. */
export type SocialNetwork = 'instagram' | 'tiktok' | 'x' | 'facebook' | 'website';

/**
 * One place an organizer can be found besides here.
 *
 * The address comes from the API, which builds it from the part that names
 * the account rather than passing on whatever was typed, so a link here only
 * ever leads to that network (or, for a website, to an https address).
 */
export interface SocialLink {
  network: SocialNetwork;
  /** What the link says: @username, a Facebook page's name, or a site's host. */
  label: string;
  url: string;
}

/** One page of an organizer's nights: GET /api/organizers/{slug}/events. */
export interface OrganizerEvents {
  data: EventSummary[];
  meta: { page: number; per_page: number; has_more: boolean };
}

/** Who a how-to video is for. */
export type HelpVideoAudience = 'buyers' | 'organizers';

/**
 * A how-to video, named by YouTube's id and nothing else. The page builds the
 * thumbnail's address and the player's (on youtube-nocookie.com) itself.
 */
export interface HelpVideo {
  id: string;
  title: string;
  youtube_id: string;
  description: string | null;
  audience: HelpVideoAudience;
}

/*
 * Optional here though the API always sends both: a page rendered and cached
 * before it did has neither, and should show no links and no "Show more"
 * rather than fail.
 */
declare module '../api.types' {
  interface OrganizerPage {
    /** Whether there are older nights than `past`, from page 2 of /events?when=past. */
    past_has_more?: boolean;
    /** Where else to find them. Empty when there is nowhere. */
    socials?: SocialLink[];
  }
}
