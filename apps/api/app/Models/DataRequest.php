<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One person's request to see what is held about them, or to be forgotten.
 *
 * @see config/personal_data.php for what either one touches.
 */
class DataRequest extends Model
{
    use HasUuids;

    /** How long an unproved request stays open before it means nothing. */
    public const VERIFY_HOURS = 24;

    /** How long an export can be downloaded before it is deleted. */
    public const DOWNLOAD_DAYS = 7;

    /** What the law allows for answering. Recorded, not waited for. */
    public const DUE_DAYS = 30;

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'verified_at' => UtcDateTime::class,
            'due_at' => UtcDateTime::class,
            'completed_at' => UtcDateTime::class,
            'expires_at' => UtcDateTime::class,
            'outcome' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (DataRequest $request) => $request->token ??= Str::random(48));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDownloadable(): bool
    {
        return $this->kind === 'export'
            && $this->status === 'completed'
            && $this->file_path !== null
            && $this->expires_at?->isFuture() === true;
    }

    public function awaitingProof(): bool
    {
        return $this->status === 'pending'
            && $this->created_at?->gt(now()->subHours(self::VERIFY_HOURS)) === true;
    }
}
