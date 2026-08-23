<?php

namespace App\Services\Legacy;

use App\Models\Event;
use App\Models\EventImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The posters, which are 96% of the old database.
 *
 * `events._poster` is a longblob. 286 rows hold 288 MB — roughly a megabyte of
 * JPEG per event, inside the table that every listing query reads. Selecting a
 * page of events meant dragging their posters through the connection whether
 * or not anybody was going to look at one.
 *
 * They move to object storage here, which is the actual fix. The row keeps a
 * path.
 *
 * Separate from the row import on purpose: this is a quarter of a gigabyte of
 * transfer that will fail partway at least once, and it should be restartable
 * without re-examining 2,694 orders to find out where it got to. It also means
 * a cutover can move the rows first — the platform is usable without posters,
 * and not usable without events — and backfill the images while it runs.
 */
class LegacyPosterImporter
{
    public function __construct(
        private readonly LegacyMap $map,
        private readonly ?\Closure $progress = null,
    ) {}

    /**
     * @return array{moved: int, empty: int, failed: int, bytes: int}
     */
    public function run(?int $limit = null): array
    {
        $moved = $empty = $failed = 0;
        $bytes = 0;

        // Ids first, blobs one at a time. Streaming the whole column would
        // hold 288 MB in PHP's memory to write it out a megabyte at a time.
        $ids = DB::connection('legacy')->table('events')->orderBy('id')->pluck('id');

        foreach ($ids as $legacyId) {
            if ($limit !== null && $moved >= $limit) {
                break;
            }

            $eventId = $this->map->find('events', $legacyId);

            if (! $eventId) {
                continue;
            }

            if ($this->map->find('event_poster', $legacyId)) {
                continue;
            }

            $row = DB::connection('legacy')->table('events')
                ->where('id', $legacyId)
                ->select(['_poster', '_poster_url'])
                ->first();

            $blob = $row->_poster ?? null;

            if ($blob === null || strlen($blob) < 100) {
                // Under a hundred bytes is not an image. Several rows hold an
                // empty string or a stray byte where an upload failed years
                // ago, and the placeholder filename in `_poster_url` is the
                // tell.
                $empty++;

                continue;
            }

            try {
                $extension = $this->extensionFor($blob);
                $path = 'events/'.$eventId.'/banner-'.Str::random(8).'.'.$extension;

                Storage::disk('public')->put($path, $blob);

                // Read from the bytes rather than left null. These are the
                // figures that let a listing reserve the right space before an
                // image loads, and this is the only moment the bytes are in
                // hand — recovering them later means reading 288 MB back out
                // of object storage.
                $size = @getimagesizefromstring($blob) ?: null;

                $image = EventImage::create([
                    'event_id' => $eventId,
                    // The poster is the banner. There is no separate poster
                    // concept in this schema — events.poster_path was replaced
                    // by an image with this kind.
                    'kind' => 'banner',
                    'path' => $path,
                    'width' => $size[0] ?? null,
                    'height' => $size[1] ?? null,
                    'byte_size' => strlen($blob),
                    'mime' => $size['mime'] ?? null,
                    'position' => 0,
                ]);

                $this->map->record('event_poster', $legacyId, 'event_image', $image->id);

                $moved++;
                $bytes += strlen($blob);
            } catch (\Throwable $e) {
                // One unreadable poster does not stop the other 285. The event
                // is already imported and shows without an image.
                $failed++;
            }

            if ($this->progress) {
                ($this->progress)($moved, $empty, $failed);
            }
        }

        return ['moved' => $moved, 'empty' => $empty, 'failed' => $failed, 'bytes' => $bytes];
    }

    /**
     * What kind of image this is, from its first bytes.
     *
     * Not from `_poster_url`, which is a filename somebody typed and is
     * 'placeholder.png' on most rows regardless of what the blob holds.
     */
    private function extensionFor(string $blob): string
    {
        return match (true) {
            str_starts_with($blob, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($blob, "\x89PNG") => 'png',
            str_starts_with($blob, 'GIF8') => 'gif',
            str_starts_with($blob, 'RIFF') && str_contains(substr($blob, 0, 16), 'WEBP') => 'webp',
            default => 'bin',
        };
    }
}
