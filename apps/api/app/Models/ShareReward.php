<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a link's holder was given when a friend paid through it: a single-use
 * code off their next tickets from the same organizer.
 *
 * One per friend's order (unique on friend_order_id), so a payment announced
 * twice rewards once. Voided, and its code turned off, when the friend's
 * order is refunded in full before the code was spent; a spent one stands,
 * because the holder has already been given what it was worth.
 */
class ShareReward extends Model
{
    use HasUuids;

    public const ISSUED = 'issued';

    public const VOIDED = 'voided';

    protected $guarded = ['id'];

    /** @return BelongsTo<ShareLink, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(ShareLink::class, 'share_link_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function friendOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'friend_order_id');
    }

    /** @return BelongsTo<Code, $this> */
    public function code(): BelongsTo
    {
        return $this->belongsTo(Code::class);
    }
}
