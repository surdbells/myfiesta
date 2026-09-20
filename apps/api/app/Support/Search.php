<?php

namespace App\Support;

/**
 * Turning what somebody typed into a pattern that only matches what they typed.
 *
 * `%` and `_` are wildcards to LIKE and ordinary characters to the person at
 * the keyboard. Left alone, a search for `%` matches every row and the filter
 * silently stops filtering — the worst kind of failure, because the table
 * still looks like a table and the count still looks like a count. `\` has to
 * go first, or escaping the other two puts backslashes in that then get read
 * as escapes themselves.
 *
 * It lives here rather than in a controller because two endpoints already
 * needed it and wrote it twice, which is how one of them ends up without the
 * backslash case.
 */
final class Search
{
    /** A contains-this pattern, safe for LIKE and ILIKE. */
    public static function contains(?string $term): string
    {
        return '%'.self::escape($term).'%';
    }

    /** The same escaping without the wildcards, for a starts-with or exact match. */
    public static function escape(?string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $term));
    }
}
