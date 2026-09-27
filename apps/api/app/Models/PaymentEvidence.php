<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The processor's own record of an order's payment, as it was when it landed.
 *
 * Pending until the processor has been asked, then fixed: the database
 * refuses to change a captured row, and deletes one only inside the retention
 * prune (see the migration). ProcessorEvidence is what writes it.
 *
 * @property string $status
 * @property int $attempts
 * @property array<string, mixed>|null $checkout
 * @property array<string, mixed>|null $facts
 */
class PaymentEvidence extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const CAPTURED = 'captured';

    /** Asked as often as it is worth asking, and never answered. */
    public const GAVE_UP = 'gave_up';

    protected $table = 'payment_evidence';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'checkout' => 'array',
            'facts' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => UtcDateTime::class,
            'captured_at' => UtcDateTime::class,
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isCaptured(): bool
    {
        return $this->status === self::CAPTURED;
    }
}
