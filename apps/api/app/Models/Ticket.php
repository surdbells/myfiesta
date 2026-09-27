<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One admission.
 *
 * Accounts are optional; ownership is not. Every ticket belongs to an identity,
 * claimed or unclaimed, which is what makes transfer an authenticated act
 * rather than a public endpoint keyed on a sequential integer.
 */
class Ticket extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<TicketType, $this> */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * What this ticket's holder was asked at checkout.
     *
     * Only the per-person answers reach a ticket. What the buyer answered for
     * the order as a whole belongs to the order.
     *
     * @return HasMany<OrderAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(OrderAnswer::class);
    }

    /** @return HasMany<TicketScan, $this> */
    public function scans(): HasMany
    {
        return $this->hasMany(TicketScan::class);
    }

    /** @return HasMany<TicketTransfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(TicketTransfer::class);
    }

    /**
     * A ticket code, from a cryptographically secure source.
     *
     * The previous generator used str_shuffle, which is not a CSPRNG, over a
     * table with no uniqueness constraint. Codes imported from that platform
     * keep their original format so tickets already in inboxes still scan.
     *
     * Excludes characters that are misread aloud or on a screen: 0/O, 1/I.
     */
    public static function generateCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $out = '';

        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return substr($out, 0, 4).'-'.substr($out, 4);
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    public function isValid(): bool
    {
        return $this->status === 'valid';
    }
}
