<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A picture belonging to an event — the banner, or one of the gallery.
 *
 * Rows hold paths. URLs are derived here, at read time, from whatever disk is
 * configured, so moving from the local disk to R2 changes a config value rather
 * than every row ever written.
 */
class EventImage extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['renditions' => 'array'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    /**
     * A named rendition, falling back to the original.
     *
     * Falling back rather than returning null matters: an imported row has no
     * renditions at all, and a gallery that renders nothing for those is worse
     * than one that renders a full-size image slowly.
     */
    public function renditionUrl(string $name): string
    {
        $path = $this->renditions[$name] ?? null;

        return Storage::disk('public')->url($path ?? $this->path);
    }

    /** Every stored file, so deleting the row can delete the bytes too. */
    public function paths(): array
    {
        return array_values(array_filter([$this->path, ...array_values($this->renditions ?? [])]));
    }
}
