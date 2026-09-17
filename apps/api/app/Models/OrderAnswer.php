<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What somebody buying tickets answered.
 *
 * Either for the order as a whole — how did you hear about this — or about one
 * person on it, in which case it carries the line they are on and which of
 * that line's tickets they are. The database refuses half of that pair.
 *
 * Written when the order is created, which is before any ticket exists: the
 * buyer answers on the way to the payment page and the tickets are minted by
 * the webhook that follows. `ticket_id` is stamped on at that point, so a door
 * reading a scanned code does not have to work backwards through an order.
 */
class OrderAnswer extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        // Always a list in storage, as on the RSVP side, so reporting never
        // has to branch on whether a question happened to be multi-choice.
        return ['value' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function question(): BelongsTo
    {
        // Including deleted ones: a question an organizer has since removed is
        // still what this answer is an answer to, and without its label the
        // answer is a value in a column with no heading.
        return $this->belongsTo(EventQuestion::class, 'event_question_id')->withTrashed();
    }

    /**
     * The answer as one line of text.
     *
     * For a guest list, an export, and a door screen — all three want a
     * string, and all three would otherwise write this themselves.
     */
    public function asText(): string
    {
        $values = array_map(
            fn ($value) => match (true) {
                $value === true => 'Yes',
                $value === false => 'No',
                default => (string) $value,
            },
            $this->value ?? [],
        );

        return implode(', ', $values);
    }
}
