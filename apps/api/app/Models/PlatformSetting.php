<?php

namespace App\Models;

use App\Services\Settings\PlatformSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One setting staff have chosen, overriding its configured default.
 *
 * Read through PlatformSettings, never directly: that is where the defaults,
 * the cache and the audit trail live. Saving or deleting a row here by any
 * route still clears the cache, so a change made from a console is not
 * ignored until the next deploy.
 */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => PlatformSettings::forget());
        static::deleted(fn () => PlatformSettings::forget());
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
