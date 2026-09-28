<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A list's filters, sort and columns, kept under a name by one person for one
 * organization. See the migration for why it is shaped this way.
 *
 * @property string $id
 * @property string $user_id
 * @property string $organization_id
 * @property string $list
 * @property string $name
 * @property array<string, mixed> $state
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SavedView extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'state' => 'array',
        ];
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
