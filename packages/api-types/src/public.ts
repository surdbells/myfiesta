/** An organizer's page (socials, past events page by page) and the how-to videos. */

/** The networks an organizer can point to, in the order a page shows them. */
export type SocialNetwork = 'instagram' | 'tiktok' | 'x' | 'facebook' | 'website';

/**
 * Where else to find an organization, as the brand form starts from.
 *
 * Each is kept as the part that names the account: a username without its @
 * for Instagram, TikTok and X; a page's name (or, for a page that never chose
 * one, its address) for Facebook; an https address for a website. Null where
 * nothing is kept, or where what the old platform kept cannot be read.
 *
 * Sent back to PATCH /api/organizer/brand as `socials`, any of them, in any of
 * the forms people paste: the username, @username, or the address. Null or an
 * empty string takes one off.
 */
export type BrandSocials = Record<SocialNetwork, string | null>;

/**
 * One place an organizer can be found besides their page.
 *
 * Built by the API from the kept part, so the address only ever leads to that
 * network, or for a website to an https address. Open it with
 * rel="noopener noreferrer nofollow".
 */
export interface SocialLink {
  network: SocialNetwork;
  /** What the link says: @username, a Facebook page's name, or a site's host. */
  label: string;
  url: string;
}

/** Who a how-to video is for. */
export type HelpVideoAudience = 'buyers' | 'organizers';

/**
 * A how-to video on the site's help/videos, named by YouTube's id alone. The
 * thumbnail is https://i.ytimg.com/vi/{id}/hqdefault.jpg and the player
 * https://www.youtube-nocookie.com/embed/{id}.
 */
export interface HelpVideo {
  id: string;
  title: string;
  youtube_id: string;
  description: string | null;
  audience: HelpVideoAudience;
}

/*
 * Fields this feature adds to a shape of index.ts, declared here rather than
 * there so no two features edit that file (TypeScript merges the two).
 */
declare module './index' {
  interface Brand {
    /** Where else to find them, each made sense of, or null. */
    socials: BrandSocials;
  }
}
