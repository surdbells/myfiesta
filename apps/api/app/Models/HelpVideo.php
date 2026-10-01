<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A short video on how to buy, get in, or run a night, shown on help/videos.
 *
 * Kept as YouTube's id for the video and nothing else. The page builds the
 * address on the no-cookie domain itself, so whatever is typed into the admin
 * can only ever pick a video, never where a reader's browser is sent.
 *
 * @property string $id
 * @property string $title
 * @property string $youtube_id
 * @property string|null $description
 * @property string $audience
 * @property int $sort_order
 * @property bool $published
 */
class HelpVideo extends Model
{
    use HasUuids;

    /** The eleven letters, numbers, hyphens and underscores after watch?v=. */
    public const YOUTUBE_ID = '/^[A-Za-z0-9_-]{11}$/';

    /** Who a video is for, in the order the page shows them. */
    public const AUDIENCES = [
        'buyers' => 'Buying tickets',
        'organizers' => 'Running your events',
    ];

    /** What GET /api/help/videos keeps, forgotten whenever a video changes. */
    public const CACHE_KEY = 'help-videos:v1';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'published' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The public list is cached for an hour. Staff who publish a video
        // and open the page to check it should see it there straight away.
        $forget = fn () => Cache::forget(self::CACHE_KEY);

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * What the page shows, in its order: lowest number first, then by title.
     *
     * @param  Builder<self>  $query
     */
    public function scopeShown(Builder $query): void
    {
        $query->where('published', true)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->orderBy('id');
    }

    /**
     * The id from whatever staff paste: the id itself, or a watch, share,
     * shorts or embed address. Null when there is no id to be found.
     */
    public static function idFrom(?string $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match(self::YOUTUBE_ID, $value) === 1) {
            return $value;
        }

        $patterns = [
            // youtube.com/watch?v=ID, with anything else in the query.
            '~^(?:https?://)?(?:www\.|m\.)?youtube(?:-nocookie)?\.com/watch\?(?:.*&)?v=([A-Za-z0-9_-]{11})(?:[&#].*)?$~',
            // youtu.be/ID, youtube.com/shorts/ID, /embed/ID, /live/ID.
            '~^(?:https?://)?(?:www\.|m\.)?(?:youtu\.be|youtube(?:-nocookie)?\.com/(?:shorts|embed|live))/([A-Za-z0-9_-]{11})(?:[?&#/].*)?$~',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }
}
