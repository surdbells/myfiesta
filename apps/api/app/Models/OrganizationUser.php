<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class OrganizationUser extends Pivot
{
    use HasUuids;

    public $incrementing = false;

    protected $table = 'organization_user';

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
