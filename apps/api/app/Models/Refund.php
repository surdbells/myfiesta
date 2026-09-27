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
 * Or, once in a while, for none: a payment that landed after the last places
 * had been sold to somebody else never became tickets, and all of it goes back
 * as a refund with no tickets attached (RefundService::refundUnfulfilled).
 *
 * Failed attempts are rows too. An organizer who tried to refund somebody and
 * saw it fail will ask what happened, and the answer needs to be in the same
 * place as the successful ones.
 *
 * So are refunds made in the Stripe or Paystack dashboard (source
 * 'processor'), written down when the processor tells us, so the money that
 * went back that way is counted like any other.
 *
 * A pending row is a refund we have asked for and not heard back about. Most
 * are pending for a second. One with unanswered_at set was sent and got no
 * answer — the money may already be back — and waits for the processor's word
 * (refunds:follow-up, or its own notice) rather than being called failed.
 */
class Refund extends Model
{
    use HasUuids;

    /** Started here, by an organizer, by staff, or by the platform itself. */
    public const FROM_PLATFORM = 'platform';

    /** Made in the processor's own dashboard, and heard about afterwards. */
    public const FROM_PROCESSOR = 'processor';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unanswered_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @return BelongsToMany<Ticket, $this> */
    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'refund_tickets')
            ->withTimestamps();
    }

    /** @return Attribute<Money, never> */
    protected function money(): Attribute
    {
        return Attribute::get(fn () => new Money($this->amount, $this->currency));
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
