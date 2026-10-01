import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import type { Observable } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import type { Brand, BrandSocials, SocialNetwork } from '../../core/api.types';

/**
 * Where else to find an organization, saved on its own.
 *
 * Here rather than a wider Api.saveBrand in core/api.ts, which the features
 * added since leave alone. The same PATCH /api/organizer/brand; only the
 * accounts go, so a half-edited name in the box above is never saved with
 * them.
 */
@Injectable({ providedIn: 'root' })
export class BrandApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  /** Any of them, as typed; null takes one off. Answers with the brand as kept. */
  saveSocials(socials: Partial<BrandSocials>): Observable<Brand> {
    return this.http.patch<Brand>(`${this.base}/api/organizer/brand`, { socials });
  }
}

/** Each box on the form, in the order the page shows them. */
export const SOCIAL_FIELDS: {
  network: SocialNetwork;
  label: string;
  placeholder: string;
  hint: string;
}[] = [
  {
    network: 'instagram',
    label: 'Instagram',
    placeholder: '@lagosnights',
    hint: 'Your username, or the address of your profile.',
  },
  {
    network: 'tiktok',
    label: 'TikTok',
    placeholder: '@lagosnights',
    hint: 'Your username, or the address of your profile.',
  },
  {
    network: 'x',
    label: 'X',
    placeholder: '@lagosnights',
    hint: 'Your username, or the address of your profile.',
  },
  {
    network: 'facebook',
    label: 'Facebook',
    placeholder: 'facebook.com/lagosnights',
    hint: 'Your page’s address, or its name after facebook.com/.',
  },
  {
    network: 'website',
    label: 'Website',
    placeholder: 'https://lagosnights.com',
    hint: 'Your own site. It has to open on https.',
  },
];
