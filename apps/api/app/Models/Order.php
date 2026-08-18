<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An immutable record of what was charged.
 *
 * Amounts are snapshotted rather than recomputed from current prices, and the
 * arithmetic is asserted by a database check constraint, so a row that cannot
 * be reconciled cannot be written.
 *
 * user_id is null for guest checkout, which is the primary path — buying
 * requires no account.
 */
class Order extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(Code::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    protected function subtotal(): Attribute
    {
        return Attribute::get(fn () => new Money($this->subtotal_amount, $this->currency));
    }

    protected function discount(): Attribute
    {
        return Attribute::get(fn () => new Money($this->discount_amount, $this->currency));
    }

    protected function tax(): Attribute
    {
        return Attribute::get(fn () => new Money($this->tax_amount, $this->currency));
    }

    protected function total(): Attribute
    {
        return Attribute::get(fn () => new Money($this->total_amount, $this->currency));
    }

    protected function commission(): Attribute
    {
        return Attribute::get(fn () => new Money($this->commission_amount, $this->currency));
    }

    /**
     * A zero-total order has no gateway at all.
     *
     * Full-value codes, comps, RSVPs, and free events all land here and route
     * straight to fulfilment. Checkout that assumes a payment session exists
     * breaks the first time an organizer comps someone.
     */
    public function requiresPayment(): bool
    {
        return $this->total_amount > 0;
    }
}
