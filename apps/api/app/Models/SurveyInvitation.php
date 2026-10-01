<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One person asked about one night. The token in their link is the whole
 * credential, and the address never leaves the API: an organizer sees how
 * many were asked and what was said, never who said it.
 */
class SurveyInvitation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EventSurvey, $this> */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(EventSurvey::class, 'event_survey_id');
    }

    /** @return HasOne<SurveyResponse, $this> */
    public function response(): HasOne
    {
        return $this->hasOne(SurveyResponse::class, 'invitation_id');
    }
}
