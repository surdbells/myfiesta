import { ActivatedRoute } from '@angular/router';

/**
 * The event this screen is about, from wherever `:id` happens to sit.
 *
 * Every screen under an event used to be a sibling route carrying its own
 * `:id`, so each one read it off its own snapshot. They are children of the
 * workspace now and the parameter lives on the parent — which turned every one
 * of those reads into `null`, and every request into
 * "No query results for model [App\Models\Event] null".
 *
 * Walking up rather than reaching for `route.parent` directly, because the
 * depth is not something a screen should have to know: the door is two levels
 * down from the workspace today and a screen should not break the day it moves.
 */
export function eventIdFrom(route: ActivatedRoute): string {
  for (let current: ActivatedRoute | null = route; current; current = current.parent) {
    const id = current.snapshot.paramMap.get('id');

    if (id) return id;
  }

  // Every route that renders one of these screens declares `:id`. Reaching
  // here means the routing table and the screen disagree, and failing loudly
  // beats sending `null` to the API and reading the 404 as a missing event.
  throw new Error('No event id in this route or any of its parents.');
}
