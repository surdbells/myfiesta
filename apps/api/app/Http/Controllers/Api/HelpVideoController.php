<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HelpVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The how-to videos on help/videos: how to buy, get in, and run a night.
 *
 * Published ones only, in staff's order. Each is named by YouTube's id and
 * nothing else; the page builds the thumbnail and the player's address
 * itself, the player on the no-cookie domain and only once somebody asks for
 * it, so opening the page tells YouTube nothing.
 *
 * The same list for everybody and changed only from the admin, so it is kept
 * for an hour and forgotten the moment a video changes (HelpVideo::booted).
 */
class HelpVideoController extends Controller
{
    private const KEEP_SECONDS = 3600;

    public function __invoke(): JsonResponse
    {
        $videos = Cache::remember(HelpVideo::CACHE_KEY, self::KEEP_SECONDS, fn () => HelpVideo::query()
            ->shown()
            ->get()
            ->map(fn (HelpVideo $video) => [
                'id' => $video->id,
                'title' => $video->title,
                'youtube_id' => $video->youtube_id,
                'description' => $video->description,
                'audience' => $video->audience,
            ])
            ->all());

        return response()
            ->json(['data' => $videos])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
