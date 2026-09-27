<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\Cache;

/**
 * The last time something that runs on its own was seen running.
 *
 * The scheduler and the queue worker both fail silently: nothing errors when
 * they stop, the emails just never go and the abandoned baskets never close.
 * So each writes the time into the cache as it goes — the scheduler every
 * minute (app:heartbeat), the worker whenever it runs the small job the
 * scheduler sends it (QueueHeartbeat) — and the readiness check reads how long
 * ago that was.
 *
 * In the cache rather than the database because it is written every minute
 * and nobody needs its history, and because the cache is shared by every
 * container, which is the point: the web container is the one asked.
 */
final class Heartbeat
{
    public const SCHEDULER = 'scheduler';

    public const QUEUE = 'queue';

    /** The last good nightly backup. Written by backup:run, not every minute. */
    public const BACKUP = 'backup';

    public static function beat(string $name): void
    {
        Cache::forever(self::key($name), now()->getTimestamp());
    }

    /** Seconds since it was last seen, or null if it never has been. */
    public static function secondsSince(string $name): ?int
    {
        $at = Cache::get(self::key($name));

        return is_numeric($at) ? max(0, now()->getTimestamp() - (int) $at) : null;
    }

    private static function key(string $name): string
    {
        return 'ops:heartbeat:'.$name;
    }
}
