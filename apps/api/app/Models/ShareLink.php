<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person's link to a night that saves a friend money.
 *
 * Whose it is is an address, lowercased, because most buyers have no
 * account; the account is kept beside it when there is one. One per person
 * per night (unique on event and address), so a second order or a second
 * press of "Get my link" hands back the same one, and the rewards it has
 * earned are counted in one place.
 *
 * The slug is what rides on ?ref=, beside a promoter's: 'f' and ten base32
 * characters, which Pricer tries as a friend's link before it tries it as a
 * promoter's.
 */
class ShareLink extends Model
{
    use HasUuids;

    /** Only created_at: a link is made once and its count is all that moves. */
    public const UPDATED_AT = null;

    /** 'f' and ten of a-z2-7: what a friend's link looks like on ?ref=. */
    public const SLUG_PATTERN = '/^f[a-z2-7]{10}$/';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reward_count' => 'integer',
        ];
    }

    /** Whether a ref could be a friend's link at all, before asking the database. */
    public static function looksLikeOne(?string $ref): bool
    {
        return $ref !== null && preg_match(self::SLUG_PATTERN, strtolower(trim($ref))) === 1;
    }

    /** A fresh slug: 'f' and ten random base32 characters, 50 bits. */
    public static function newSlug(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $slug = 'f';

        for ($i = 0; $i < 10; $i++) {
            $slug .= $alphabet[random_int(0, 31)];
        }

        return $slug;
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ShareReward, $this> */
    public function rewards(): HasMany
    {
        return $this->hasMany(ShareReward::class);
    }
}
