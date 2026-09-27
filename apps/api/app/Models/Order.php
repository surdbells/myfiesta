<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An immutable record of what was charged.
 *
 * Amounts are snapshotted rather than recomputed from current prices, and the
 * arithmetic is asserted by a database check constraint, so a row that cannot
 * be reconciled cannot be written.
 *
 * user_id is null for guest checkout, which is the primary path — buying
 * requires no account.
 *
 * The terms columns are named here because the migration that adds them
 * loops over three tables, and Larastan reads a migration without running it.
 *
 * @property string|null $terms_version
 * @property Carbon|null $terms_accepted_at
 */
class Order extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    /**
     * Every order gets the token its buyer will reach it by.
     *
     * Here rather than at the call site. The column is NOT NULL because a paid
     * order whose buyer cannot open their tickets is not a state worth being
     * able to represent — and making each caller remember is how one of them
     * eventually does not. Twenty-four tests found that out immediately, which
     * is a cheap version of a checkout doing it in production.
     *
     * Not derived from the id or the reference: it must confirm nothing about
     * the order if it leaks, and it must survive both of those being printed
     * on something public.
     */
    protected static function booted(): void
    {
        static::creating(function (self $order) {
            $order->access_token ??= Str::random(44);
            $order->reference ??= self::newReference();
        });
    }

    /**
     * A reference a buyer can read out over the phone.
     *
     * Generated here rather than by whoever is creating the order. The column
     * is not nullable, so every caller that forgot it got a constraint
     * violation instead of an order — which is what happened the first time
     * anything other than checkout created one, and is exactly how
     * `access_token` behaved before it moved here too.
     *
     * The alphabet omits characters that are ambiguous spoken or written: no
     * O against 0, no I against 1, no S against 5.
     */
    public static function newReference(): string
    {
        $alphabet = 'ACDEFGHJKLMNPQRTUVWXY346789';

        do {
            $reference = '';

            for ($i = 0; $i < 8; $i++) {
                $reference .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (self::where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * Where an order may go from where it is, when a processor says so.
     *
     * Notices arrive late, twice, and out of order, and each one used to write
     * its status over whatever the order said: a "payment failed" retried
     * after the buyer had paid on a second card made a paid order failed, and
     * a partial refund made in Stripe's dashboard marked the whole order
     * refunded while every ticket still worked. So a notice can only move an
     * order forward, and only along these lines.
     *
     * Paid and refunded are decided elsewhere too (Fulfiller, RefundService),
     * under the order's lock, and follow the same lines. Nothing leaves
     * refunded or failed: a failed order that is then paid is for a person to
     * look at (Fulfiller says so out loud).
     */
    private const MOVES = [
        'pending' => ['paid', 'failed', 'cancelled', 'refunded'],
        // A payment page closed, or a basket given up on, whose money arrived
        // anyway; and the refund of it when there was no room left.
        'cancelled' => ['paid', 'refunded'],
        'paid' => ['partially_refunded', 'refunded'],
        'partially_refunded' => ['partially_refunded', 'refunded'],
        'failed' => [],
        'refunded' => [],
    ];

    /** Whether a processor's notice may move this order to $status. */
    public function mayBecome(string $status): bool
    {
        return in_array($status, self::MOVES[$this->status] ?? [], true);
    }

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            // What the buyer agreed to, and when. Null for a door sale. See Terms.
            'terms_accepted_at' => 'datetime',
            // How it was taxed and who sold it, as it was. See Pricer.
            'tax_lines' => 'array',
            'pricing_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Code, $this> */
    public function code(): BelongsTo
    {
        return $this->belongsTo(Code::class);
    }

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /** @return HasMany<OrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * What the buyer answered: for the order, and about each person on it.
     *
     * @return HasMany<OrderAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(OrderAnswer::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * The stock set aside for this order while its buyer was paying.
     *
     * @return HasMany<InventoryHold, $this>
     */
    public function holds(): HasMany
    {
        return $this->hasMany(InventoryHold::class);
    }

    /** @return Attribute<Money, never> */
    protected function subtotal(): Attribute
    {
        return Attribute::get(fn () => new Money($this->subtotal_amount, $this->currency));
    }

    /** @return Attribute<Money, never> */
    protected function discount(): Attribute
    {
        return Attribute::get(fn () => new Money($this->discount_amount, $this->currency));
    }

    /** @return Attribute<Money, never> */
    protected function tax(): Attribute
    {
        return Attribute::get(fn () => new Money($this->tax_amount, $this->currency));
    }

    /** @return Attribute<Money, never> */
    protected function total(): Attribute
    {
        return Attribute::get(fn () => new Money($this->total_amount, $this->currency));
    }

    /**
     * What the organizer earned. Not reduced by the service charge.
     *
     * @return Attribute<Money, never>
     */
    protected function netRevenue(): Attribute
    {
        return Attribute::get(fn () => new Money($this->net_revenue_amount, $this->currency));
    }

    /**
     * What the buyer paid the platform, on top of the ticket price.
     *
     * @return Attribute<Money, never>
     */
    protected function serviceCharge(): Attribute
    {
        return Attribute::get(fn () => new Money($this->service_charge_amount, $this->currency));
    }

    /**
     * What the processor took, once the payment settled.
     *
     * Null until then, and null is not zero: an unsettled order has an unknown
     * cost, not a free one.
     *
     * @return Attribute<Money|null, never>
     */
    protected function gatewayFee(): Attribute
    {
        return Attribute::get(fn () => $this->gateway_fee_amount === null
            ? null
            : new Money($this->gateway_fee_amount, $this->currency));
    }

    /**
     * A zero-total order has no gateway at all.
     *
     * Full-value codes, comps, RSVPs, and free events all land here and route
     * straight to fulfilment. Checkout that assumes a payment session exists
     * breaks the first time an organizer comps someone.
     */
    /** Sold in a doorway, cash or terminal, by somebody standing there. */
    public function soldAtDoor(): bool
    {
        return $this->channel === 'door';
    }

    /** @return BelongsTo<User, $this> */
    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by_user_id');
    }

    /** @return BelongsTo<DoorPass, $this> */
    public function doorPass(): BelongsTo
    {
        return $this->belongsTo(DoorPass::class);
    }

    public function requiresPayment(): bool
    {
        return $this->total_amount > 0;
    }
}
