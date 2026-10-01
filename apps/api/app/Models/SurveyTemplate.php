<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A list of questions to send after a night: myFiesta's own (no
 * organization), or one an organizer wrote.
 *
 * Each question is {id, type, label, options, required}; SurveyQuestions
 * says what each type takes. Archived rather than deleted, because a night
 * sent with it is compared with the next one sent with it.
 */
class SurveyTemplate extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isPlatform(): bool
    {
        return $this->organization_id === null;
    }

    /** myFiesta's own, the one a night is sent unless it chose another. */
    public static function platformDefault(): ?self
    {
        return static::query()
            ->whereNull('organization_id')
            ->whereNull('archived_at')
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Templates an organization can choose from: its own, still in use.
     *
     * @param  Builder<SurveyTemplate>  $query
     * @return Builder<SurveyTemplate>
     */
    public function scopeOfOrganization(Builder $query, string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId)->whereNull('archived_at');
    }
}
