<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Somebody who wants to hear when an organizer announces a night.
 *
 * The token is how they stop hearing it from inside the email, without an
 * account and without signing in — most people who follow an organizer bought
 * a ticket as a guest, so anything behind a login is not a way out at all.
 */
class OrganizationFollow extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $follow) {
            $follow->token ??= Str::random(48);
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
