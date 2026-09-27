<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * KYC evidence. Encrypted at rest, reviewed by a human.
 *
 * The previous platform collected all of this and never looked at it. A review
 * outcome turns a drawer into a decision.
 *
 * document_path points at the private disk and is served only through an
 * authorising controller with a short-lived signed URL. It is never
 * web-reachable.
 */
class OrganizationIdentityDocument extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = [
        'legal_first_name', 'legal_last_name', 'date_of_birth',
        'document_number', 'expires_on', 'document_path',
    ];

    protected function casts(): array
    {
        return [
            'legal_first_name' => 'encrypted',
            'legal_last_name' => 'encrypted',
            'date_of_birth' => 'encrypted',
            'document_number' => 'encrypted',
            'expires_on' => 'encrypted',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
