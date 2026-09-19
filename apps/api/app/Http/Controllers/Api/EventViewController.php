<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Somebody opened an event page.
 *
 * Counted, not recorded: one number per event per day, incremented. Nothing
 * about who — no address, no cookie, no id — so there is nothing here to
 * export, erase or leak, and nothing a visitor has to be asked about.
 *
 * The site sends it once per page per browsing session, from the browser, so
 * a crawler reading the rendered HTML is not counted and a buyer flicking
 * between the event and its checkout is counted once. It is a guide, and the
 * console says so: a count anybody can add to with a script is not a number
 * to pay a promoter on.
 */
class EventViewController extends Controller
{
    public function __invoke(Request $request, string $slug): Response
    {
        $embed = $request->boolean('embed');

        $eventId = Event::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->value('id');

        if ($eventId !== null) {
            $column = $embed ? 'embed_views' : 'views';

            DB::statement(
                "INSERT INTO event_views (event_id, day, views, embed_views)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT (event_id, day) DO UPDATE SET {$column} = event_views.{$column} + 1",
                [$eventId, now()->toDateString(), $embed ? 0 : 1, $embed ? 1 : 0],
            );
        }

        // The same answer whether or not the event exists, so this is not a
        // way to find out which slugs are drafts.
        return response()->noContent();
    }
}
