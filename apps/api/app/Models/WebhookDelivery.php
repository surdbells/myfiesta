<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to tell somebody something, and what came back.
 *
 * The id doubles as the idempotency key a receiver sees: a delivery retried
 * after a timeout carries the same id, so a receiver that did get the first
 * one can recognise the second and ignore it.
 */
class WebhookDelivery extends Model
{
    use HasUuids;

    /** How long a payload — with a buyer's name and address in it — is kept. */
    public const KEEP_DAYS = 30;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
