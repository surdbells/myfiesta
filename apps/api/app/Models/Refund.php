<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Money returned for named tickets.
 *
 * Failed attempts are rows too. An organizer who tried to refund somebody and
 * saw it fail will ask what happened, and the answer needs to be in the same
 * place as the successful ones.
 */
class Refund extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'refund_tickets')
            ->withTimestamps();
    }

    protected function money(): Attribute
    {
        return Attribute::get(fn () => new Money($this->amount, $this->currency));
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
