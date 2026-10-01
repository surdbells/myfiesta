<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An event kept as a starting point for the next one.
 *
 * The payload is the event's shape as EventBlueprint writes it; the migration
 * says why it is one document, and EventTemplates how one is kept and used.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property array<string, mixed> $payload
 * @property string|null $banner_path
 * @property string|null $source_event_id
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class EventTemplate extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The event it was kept from, even once that event is in the bin: a
     * takedown on it still holds after it is deleted.
     *
     * @return BelongsTo<Event, $this>
     */
    public function sourceEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'source_event_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Every file the template holds, so deleting it can delete the bytes too.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $renditions = $this->payload['banner']['renditions'] ?? [];

        return array_values(array_unique(array_filter([
            $this->banner_path,
            ...array_values(is_array($renditions) ? $renditions : []),
        ], fn ($path) => is_string($path) && $path !== '')));
    }
}
