<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Recording what somebody did.
 *
 * One entry point, so the shape of an audit entry is decided once rather than
 * at each call site. Every caller passes the same three things — who, what, and
 * what it happened to — and the details that differ go in metadata.
 *
 * Nothing here throws. An audit write that fails must not roll back the refund
 * it was recording: losing the record of a thing is bad, and failing the thing
 * because the record failed is worse. Failures go to the error log, which is
 * where somebody looking for a missing entry will think to look.
 */
class Auditor
{
    public function record(
        string $action,
        ?Model $subject = null,
        ?User $actor = null,
        ?string $organizationId = null,
        array $metadata = [],
    ): ?AuditLog {
        try {
            return AuditLog::create([
                'organization_id' => $organizationId ?? $this->organizationOf($subject),
                'actor_id' => $actor?->id,
                // Kept alongside the foreign key so the trail survives the
                // account being deleted. Without it, erasing one member blanks
                // the actor on every refund they ever processed.
                'actor_label' => $actor?->name,
                'action' => $action,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'metadata' => $metadata ?: null,
                'ip_address' => $this->ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Audit entry could not be written', [
                'action' => $action,
                'subject' => $subject ? $subject::class.':'.$subject->getKey() : null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The organization the subject belongs to, if it has one.
     *
     * Saves every caller passing it explicitly for the common case, and a
     * caller that knows better can still override.
     */
    private function organizationOf(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        // Directly on the model, or one hop through an event — orders, tickets
        // and refunds all reach an organization that way.
        return $subject->organization_id
            ?? $subject->event?->organization_id
            ?? null;
    }

    /**
     * Where the request came from.
     *
     * Guarded because this also runs from the scheduler and from tinker, where
     * there is no request and asking for one throws.
     */
    private function ip(): ?string
    {
        try {
            return Request::ip();
        } catch (Throwable) {
            return null;
        }
    }
}
