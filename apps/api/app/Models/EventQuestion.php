<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something an event asks the people coming to it.
 *
 * One model for both halves of the product. A wedding asks its guests about
 * dietary requirements when they RSVP; a club night asks its buyers for the
 * name that goes on each ticket. The question is the same shape either way —
 * a label, how it is answered, whether it must be, and whether it is asked
 * once or asked of each person — so there is one table, one editor and one
 * validator rather than two of each drifting apart.
 *
 * Soft-deleted, because answers outlive the question. An organizer tidying
 * their form the week after the night would otherwise delete the dietary
 * requirements they collected it for.
 */
class EventQuestion extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    /** How an answer is given. The database holds the same list as a check constraint. */
    public const TYPES = ['text', 'choice', 'multi_choice', 'boolean'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            // Asked once for the whole order, or once about each person on it.
            'per_attendee' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** Whether the answer is chosen from a list rather than typed. */
    public function isChoice(): bool
    {
        return in_array($this->type, ['choice', 'multi_choice'], true);
    }
}
