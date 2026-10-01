<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What one person answered, keyed by question id. Once, and never edited. */
class SurveyResponse extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['answers' => 'array'];
    }

    /** @return BelongsTo<SurveyInvitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(SurveyInvitation::class, 'invitation_id');
    }

    /** @return BelongsTo<EventSurvey, $this> */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(EventSurvey::class, 'event_survey_id');
    }
}
