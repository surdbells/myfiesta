import { Injectable, inject } from '@angular/core';
import type { Brand, BrandSocials, SocialNetwork } from '@myfiesta/api-types';
import { Api } from '../../core/api';

/** What one save of the brand screen sends; anything left out stays as it is. */
export interface BrandChanges {
  name?: string;
  description?: string | null;
  /** Any of them, as typed; null takes one off. */
  socials?: Partial<BrandSocials>;
}

/**
 * Saving how the organization appears, where else to find it included.
 *
 * Its own file rather than a wider Organizer.saveBrand in core/, which the
 * features built at the same time leave alone. The same PATCH
 * /api/organizer/brand, so a name and a link changed together are saved, or
 * refused, together.
 */
@Injectable({ providedIn: 'root' })
export class OrgBrandApi {
  private readonly api = inject(Api);

  save(changes: BrandChanges): Promise<Brand> {
    return this.api.request<Brand>('PATCH', '/api/organizer/brand', changes);
  }
}

/** Each box on the screen, in the order the organizer page shows them. */
export const SOCIAL_FIELDS: {
  network: SocialNetwork;
  label: string;
  placeholder: string;
  hint: string;
  type: string;
}[] = [
  {
    network: 'instagram',
    label: 'Instagram',
    placeholder: '@lagosnights',
    hint: 'Your username, or your profile’s address.',
    type: 'text',
  },
  {
    network: 'tiktok',
    label: 'TikTok',
    placeholder: '@lagosnights',
    hint: 'Your username, or your profile’s address.',
    type: 'text',
  },
  {
    network: 'x',
    label: 'X',
    placeholder: '@lagosnights',
    hint: 'Your username, or your profile’s address.',
    type: 'text',
  },
  {
    network: 'facebook',
    label: 'Facebook',
    placeholder: 'facebook.com/lagosnights',
    hint: 'Your page’s address, or its name.',
    type: 'text',
  },
  {
    network: 'website',
    label: 'Website',
    placeholder: 'https://lagosnights.com',
    hint: 'Your own site, on https.',
    type: 'url',
  },
];
