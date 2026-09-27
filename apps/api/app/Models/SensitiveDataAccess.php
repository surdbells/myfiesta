<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who looked at banking or identity data, and when.
 *
 * Encryption covers a stolen dump. This covers the case it cannot: someone with
 * a legitimate login reading records they had no business opening.
 */
class SensitiveDataAccess extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(
        ?User $user,
        string $subjectType,
        string $subjectId,
        string $action,
        ?string $ip = null,
    ): self {
        return static::create([
            'user_id' => $user?->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'ip_address' => $ip,
            'occurred_at' => now(),
        ]);
    }
}
