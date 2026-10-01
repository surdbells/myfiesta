<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One night's survey: whether it goes, which questions, how long after the
 * night, and when it went.
 *
 * Most nights never get a row until they are sent: a night without one is
 * surveyed with myFiesta's own questions, 18 hours after it ends. The row
 * appears when an organizer changes something, or when it is sent.
 */
class EventSurvey extends Model
{
    use HasUuids;

    public const DEFAULT_DELAY_HOURS = 18;

    protected $guarded = ['id'];

    protected $attributes = [
        'enabled' => true,
        'send_delay_hours' => self::DEFAULT_DELAY_HOURS,
    ];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'enabled' => 'boolean',
            'send_delay_hours' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<SurveyTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SurveyTemplate::class, 'template_id');
    }

    /** @return HasMany<SurveyInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(SurveyInvitation::class);
    }

    /** @return HasMany<SurveyResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }
}
