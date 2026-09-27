<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
}
