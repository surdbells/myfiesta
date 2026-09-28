<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An organizer asking to be paid. See the migration for what it is and is not. */
class PayoutRequest extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_at_request' => 'integer',
            'paid_amount' => 'integer',
            'overdraft_amount' => 'integer',
            'decided_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Who approved paying it — for an advance, the person the decision is
     * asked of later.
     *
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<Settlement, $this> */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function money(): Money
    {
        return new Money($this->amount, $this->currency);
    }

    /** The part paid beyond what was owed, when there was one. */
    public function overdraft(): ?Money
    {
        return $this->overdraft_amount !== null ? new Money($this->overdraft_amount, $this->currency) : null;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
