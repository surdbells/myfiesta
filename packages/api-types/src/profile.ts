/** A profile photo and a time zone on the account. */

/**
 * The person as signing in describes them: the `user` of a `Session`.
 *
 * `avatar_url` and `timezone` come from POST /api/auth/login, so the phone's
 * home screen greets with a face at the right time of day from its first
 * screen. A session made by joining an organization by invitation leaves them
 * out — a brand-new account has neither — so they are optional here.
 */
export interface SessionUser {
  name: string;
  email: string;
  /** The account's photo, 256 pixels square, or null for initials. */
  avatar_url?: string | null;
  /** The IANA zone the account chose, or null to use the device's own. */
  timezone?: string | null;
}

/** What uploading (POST) or removing (DELETE) /api/auth/avatar answers with. */
export interface AccountPhoto {
  /** Where the photo is served from; a new address for every photo. Null once removed. */
  avatar_url: string | null;
}

/*
 * Fields this feature adds to a shape of index.ts, declared here rather than
 * there so no two features edit that file (TypeScript merges the two).
 */
declare module './index' {
  interface Account {
    /**
     * The account's photo, 256 pixels square, or null for initials. Seen only
     * by the person it belongs to: the phone's home greeting and its settings.
     */
    avatar_url: string | null;
  }
}
