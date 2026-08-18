<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RsvpAnswer extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        // jsonb, so a multi-choice answer stays a list and stays queryable —
        // "how many vegetarians" has to be answerable.
        return ['value' => 'array'];
    }

    public function rsvp(): BelongsTo
    {
        return $this->belongsTo(Rsvp::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(RsvpQuestion::class, 'rsvp_question_id');
    }
}
