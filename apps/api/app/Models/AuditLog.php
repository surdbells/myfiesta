<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing somebody did.
 *
 * Append-only, enforced by a database trigger. There is deliberately no
 * update() path and no updated_at — a log application code can rewrite is not
 * evidence of anything.
 */
class AuditLog extends Model
{
    use HasUuids;

    /** Written once. Laravel would otherwise look for a column that is absent. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => UtcDateTime::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Who did it, falling back to the name kept at the time. */
    public function actorName(): string
    {
        return $this->actor?->name ?? $this->actor_label ?? 'Someone no longer on the account';
    }
}
