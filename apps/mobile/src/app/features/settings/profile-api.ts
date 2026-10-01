import { Injectable, inject } from '@angular/core';
import type { AccountPhoto } from '@myfiesta/api-types';
import { Api } from '../../core/api';

/**
 * The account's photo, on the server.
 *
 * Its own file rather than more of core/api.ts, so the features built at the
 * same time never edit the same lines. The time zone is not here: it is saved
 * with the rest of the details, through Api.updateProfile.
 */
@Injectable({ providedIn: 'root' })
export class ProfileApi {
  private readonly api = inject(Api);

  /**
   * A new photo, with how much of it has gone. A photo off a phone camera is
   * a few megabytes over whatever signal there is, and a number moving is
   * what stops somebody pressing the button again.
   */
  uploadAvatar(file: File, progress?: (percent: number | null) => void): Promise<AccountPhoto> {
    return this.api.upload('/api/auth/avatar', { file }, progress);
  }

  /** Back to initials; the file is deleted. */
  removeAvatar(): Promise<AccountPhoto> {
    return this.api.request('DELETE', '/api/auth/avatar');
  }
}
