<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A way for another system to read an organization's own data.
 *
 * Belongs to the organization, not to whoever made it: a key sitting in a
 * promoter's accounting software should not stop working because the owner
 * who created it left the team.
 *
 * Only the hash is stored. The key itself is shown once, when it is made, and
 * after that only its last four characters — enough to tell two keys apart in
 * a list, never enough to use one.
 */
class ApiKey extends Model
{
    use HasUuids;

    /** Recognisable in a log or a leaked paste, which is how secret scanners find them. */
    public const PREFIX = 'mf_live_';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Make a key and return it with its plain text, which is never seen again.
     *
     * @return array{key: self, plain: string}
     */
    public static function issue(Organization $organization, string $name, ?User $by): array
    {
        $plain = self::PREFIX.Str::random(40);

        $key = self::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'token_hash' => self::hash($plain),
            'last_four' => substr($plain, -4),
            'created_by' => $by?->id,
        ]);

        return ['key' => $key, 'plain' => $plain];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findUsable(string $plain): ?self
    {
        if (! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        return self::query()
            ->where('token_hash', self::hash($plain))
            ->whereNull('revoked_at')
            ->first();
    }
}
