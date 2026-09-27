<?php

namespace App\Models;

use App\Services\Disputes\ActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketTransfer extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /**
     * Every transfer goes into the ticket's history, however it was made —
     * by its holder, or by support moving it — pointing at this row rather
     * than copying it (ActivityLog). In the same transaction as the transfer.
     */
    protected static function booted(): void
    {
        static::created(fn (self $transfer) => app(ActivityLog::class)->transferred($transfer));
    }

    protected function casts(): array
    {
        return ['transferred_at' => 'datetime'];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
