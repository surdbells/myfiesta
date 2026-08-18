<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

class ProcessedWebhook extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    public static function alreadyHandled(string $gateway, string $eventId): bool
    {
        return $eventId !== ''
            && static::where('gateway', $gateway)->where('event_id', $eventId)->exists();
    }

    /**
     * Swallows a duplicate-key collision on purpose.
     *
     * Two simultaneous deliveries of the same event both pass the exists()
     * check; the unique index is what actually decides, and the loser has
     * nothing to report.
     */
    public static function remember(string $gateway, string $eventId): void
    {
        if ($eventId === '') {
            return;
        }

        try {
            static::create([
                'gateway' => $gateway,
                'event_id' => $eventId,
                'processed_at' => now(),
            ]);
        } catch (QueryException) {
            // Already recorded by a concurrent delivery.
        }
    }
}
