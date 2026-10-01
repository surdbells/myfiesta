import { DOCUMENT } from '@angular/common';
import { Injectable, inject } from '@angular/core';

/** The one address whose policy lets YouTube's player in (security-headers.ts). */
export const VIDEOS_PATH = '/help/videos';

/** The query parameter that names the video to open once help/videos has loaded. */
export const PLAY_PARAM = 'play';

/**
 * Whether this page may show YouTube's player, and a way to make sure it can.
 *
 * The server lets frames from youtube-nocookie.com in on help/videos and on no
 * other address. A browser keeps the policy of the address it first loaded,
 * though, so somebody who arrived at the home page and clicked through to the
 * videos inside the app still has the home page's policy, which frames
 * nothing. Their player would be a blank box. For them, pressing play loads
 * help/videos as a page of its own and opens the video there.
 */
@Injectable({ providedIn: 'root' })
export class PlayerFrame {
  private readonly document = inject(DOCUMENT);

  /** True when this document was loaded at help/videos. */
  allowed(): boolean {
    const view = this.document.defaultView;
    const entry = view?.performance?.getEntriesByType?.('navigation')?.[0];

    // A browser that does not say where the page was loaded from is given the
    // benefit of the doubt: the worst case is the blank box, not a leak.
    if (!entry?.name) return true;

    try {
      return new URL(entry.name).pathname.replace(/\/+$/, '') === VIDEOS_PATH;
    } catch {
      return true;
    }
  }

  /**
   * Load help/videos afresh, to open the video with this id there.
   *
   * The id goes in the query, not after a #. The address bar already says
   * help/videos, and an address that differs only after the # is a jump
   * within the same page: nothing would load, the policy would stay the
   * home page's, and play would do nothing however often it was pressed. A
   * different query is a new page. The server decides the policy on the path
   * alone (security-headers.ts), so the query does not change it.
   */
  reloadToPlay(youtubeId: string): void {
    this.document.defaultView?.location.assign(
      `${VIDEOS_PATH}?${PLAY_PARAM}=${encodeURIComponent(youtubeId)}`,
    );
  }
}
