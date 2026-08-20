<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A slot the series skips.
 *
 * Recorded rather than simply deleted, because the generator runs again — and
 * without this it would helpfully put Boxing Day back.
 */
class EventSeriesException extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurs_at' => UtcDateTime::class];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(EventSeries::class, 'series_id');
    }
}
