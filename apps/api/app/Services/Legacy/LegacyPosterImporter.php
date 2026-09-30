<?php

namespace App\Services\Legacy;

use App\Models\EventImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The posters, which are 96% of the old database.
 *
 * `events._poster` is a longblob holding 288 MB across 286 rows, inside the
 * table every listing query reads. Selecting a page of events meant dragging
 * their posters through the connection whether or not anybody was going to
 * look at one.
 *
 * And it is not even images: each row holds the *text* of a data URI, so a
 * third of that quarter-gigabyte is base64 overhead on top of pictures that
 * should never have been in a table in the first place.
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
     * How many events a run would look at for a poster, for a dry run.
     *
     * Counted without reading a single poster: that is the quarter of a
     * gigabyte a dry run is there to avoid. Some of them hold nothing, which
     * only reading them would tell.
     */
    public function toMove(): int
    {
        return DB::connection('legacy')->table('events')->orderBy('id')->pluck('id')
            ->filter(fn ($id) => $this->map->find('events', $id) && ! $this->map->find('event_poster', $id))
            ->count();
    }

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

            // Not the raw column. It holds the text of a data URI, so it is
            // decoded to actual image bytes first — see LegacyRules.
            $image = LegacyRules::decodeImage($row->_poster ?? null);

            if ($image === null) {
                // Several rows hold an empty string or a stray byte where an
                // upload failed years ago, and the placeholder filename in
                // `_poster_url` is the tell.
                $empty++;

                continue;
            }

            $blob = $image['bytes'];
            $path = null;

            try {
                $extension = LegacyRules::extensionFor($image['mime']);
                $path = 'events/'.$eventId.'/banner-'.Str::random(8).'.'.$extension;

                Storage::disk('public')->put($path, $blob);

                // Read from the bytes rather than left null. These are the
                // figures that let a listing reserve the right space before an
                // image loads, and this is the only moment the bytes are in
                // hand — recovering them later means reading 288 MB back out
                // of object storage.
                $size = @getimagesizefromstring($blob) ?: null;

                // The image row and its map row together, so a poster is
                // either moved and known to be moved or not moved at all.
                $this->map->atomically(function () use ($eventId, $path, $size, $blob, $image, $legacyId) {
                    $record = EventImage::create([
                        'event_id' => $eventId,
                        // The poster is the banner. There is no separate poster
                        // concept in this schema — events.poster_path was replaced
                        // by an image with this kind.
                        'kind' => 'banner',
                        'path' => $path,
                        'width' => $size[0] ?? null,
                        'height' => $size[1] ?? null,
                        'byte_size' => strlen($blob),
                        'mime' => $image['mime'],
                        'position' => 0,
                    ]);

                    $this->map->record('event_poster', $legacyId, 'event_image', $record->id);

                    // With the rows, so a poster that came across on a retry
                    // is never left listed as outstanding.
                    $this->map->resolved('event_poster', $legacyId);
                });

                $moved++;
                $bytes += strlen($blob);
            } catch (\Throwable $e) {
                // One unreadable poster does not stop the other 285. The event
                // is already imported and shows without an image, the file
                // that nothing points at is taken back, and the reason is
                // written down for the next run to be judged against.
                if ($path !== null) {
                    Storage::disk('public')->delete($path);
                }

                $this->map->failed('event_poster', $legacyId, LegacyRules::failureReason($e));

                $failed++;
            }

            if ($this->progress) {
                ($this->progress)($moved, $empty, $failed);
            }
        }

        return ['moved' => $moved, 'empty' => $empty, 'failed' => $failed, 'bytes' => $bytes];
    }
}
