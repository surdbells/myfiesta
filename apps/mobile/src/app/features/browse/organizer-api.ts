import { Injectable, inject } from '@angular/core';
import type { SocialLink } from '@myfiesta/api-types';
import { Api } from '../../core/api';
import type { EventCard } from '../../core/discovery';

/*
 * What the organizer page carries besides what core/discovery.ts declares,
 * added here (TypeScript merges the two) so this feature never edits a file
 * the others are changing too. Optional: an older API sends neither, and the
 * screen then shows no links and no "Show more".
 */
declare module '../../core/discovery' {
  interface OrganizerPage {
    /** Where else to find them, as links the API built. Empty when there is nowhere. */
    socials?: SocialLink[];
    /** Whether there are older nights than `past`. */
    past_has_more?: boolean;
  }
}

/** One page of an organizer's nights. */
export interface OrganizerNights {
  data: EventCard[];
  meta: { page: number; per_page: number; has_more: boolean };
}

/**
 * An organizer's nights beyond the twelve their page carries.
 *
 * Public and the same for everybody, so no token goes with it.
 */
@Injectable({ providedIn: 'root' })
export class OrganizerApi {
  private readonly api = inject(Api);

  /** Twelve at a time, newest first. Page 1 is what the page already shows. */
  past(slug: string, page: number): Promise<OrganizerNights> {
    return this.api.public<OrganizerNights>(`/api/organizers/${encodeURIComponent(slug)}/events`, {
      when: 'past',
      page: String(page),
    });
  }
}

/** What each link is called beside the account it names. */
export const NETWORK_NAMES: Record<SocialLink['network'], string> = {
  instagram: 'Instagram',
  tiktok: 'TikTok',
  x: 'X',
  facebook: 'Facebook',
  website: 'Website',
};
