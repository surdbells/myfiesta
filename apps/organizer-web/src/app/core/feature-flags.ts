import type { LucideIconData } from 'lucide-angular';
import type { OrganizerEventDetail } from './api.types';
import type { SessionStore } from './session';

/*
 * Screens that arrive switched off.
 *
 * Several features are built at once, each by somebody working only in its
 * own folder. Their routes are registered up front (app.routes.ts) and their
 * sidebar entries and event tabs are listed up front (app.ts, the event
 * workspace), so nobody building one has to open a file somebody building
 * another is also changing. What each entry says — whether it is on, its
 * label, where it sits, who sees it — lives in that feature's own
 * features/<feature>/enabled.ts, and switching a feature on is one line there.
 * Its calls to the API live in its own folder too, never in core/api.ts (the
 * Api class says how).
 *
 * Off, an entry is simply absent: no link, no tab, no hint that something is
 * coming. Its route still answers, with a heading and nothing else, for
 * whoever types the address.
 */

/** A sidebar entry a feature adds. */
export interface FeatureNavEntry {
  /** Whether the feature has shipped. Until it has, the sidebar leaves it out. */
  readonly enabled: boolean;
  readonly label: string;
  readonly link: string;
  readonly glyph: LucideIconData;
  /**
   * The entry it follows, by link. `/` and `/events` are there for everybody;
   * any other may be hidden from this person, and then the entry goes last.
   */
  readonly after: string;
  /** Whether this person may open it. Hidden rather than disabled, like every entry. */
  readonly allowed: (session: SessionStore) => boolean;
}

/** A tab a feature adds to one event's workspace. */
export interface FeatureTab {
  /** Whether the feature has shipped. Until it has, the tab is left out. */
  readonly enabled: boolean;
  readonly label: string;
  /** Its child path under events/:id, as app.routes.ts registers it. */
  readonly path: string;
  /**
   * The tab it follows, by path ('' is the Overview, there for everybody).
   * When that one is hidden from this person, the tab goes last.
   */
  readonly after: string;
  /** Whether this person sees it, for this event (null while it loads). */
  readonly allowed: (session: SessionStore, event: OrganizerEventDetail | null) => boolean;
  /** Whether its forms are switched off while myFiesta reviews the event (the API answers 423). */
  readonly lockedInReview?: boolean;
}

/**
 * The switched-on entries, each placed after the one it names.
 *
 * Two entries naming the same one keep the order they were given in, so the
 * lists in app.ts and the workspace decide ties, not whichever loaded first.
 * An entry may name another feature's entry, when that one comes earlier in
 * the list.
 */
export function placeAfter<T, E extends { readonly after: string }>(
  items: readonly T[],
  entries: readonly E[],
  keyOf: (item: T) => string,
  toItem: (entry: E) => T,
): T[] {
  const placed = [...items];
  const anchors = new Map<T, string>();

  for (const entry of entries) {
    const item = toItem(entry);
    const anchor = placed.findIndex((existing) => keyOf(existing) === entry.after);
    let at = anchor < 0 ? placed.length : anchor + 1;

    while (at < placed.length && anchors.get(placed[at]) === entry.after) at++;

    placed.splice(at, 0, item);
    anchors.set(item, entry.after);
  }

  return placed;
}
