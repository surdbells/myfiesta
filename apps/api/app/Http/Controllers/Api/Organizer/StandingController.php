<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Organizations\WhileSuspended;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Whether the platform is selling for this organization, for the console's
 * banner.
 *
 * Asked on its own rather than carried in the sign-in, because a suspension
 * lands while people are signed in — and a banner that only appears after
 * the next sign-in is one nobody sees on the day it matters. Any member may
 * ask, door staff and staff sessions included: everybody working in the
 * console should know why the publish button refuses.
 *
 * The reason is given only when staff chose to share it; the admin panel
 * always has it.
 */
class StandingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        return response()->json([
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'suspended' => $organization->isSuspended(),
            'suspension' => $organization->isSuspended() ? [
                'since' => $organization->suspended_at?->toIso8601String(),
                'reason' => $organization->suspension_reason_shared ? $organization->suspension_reason : null,
                'support_email' => WhileSuspended::supportEmail(),
            ] : null,
        ]);
    }

    /**
     * The organization the console is working in, checked against membership
     * and then read afresh — the membership list may have been loaded before
     * the suspension it is being asked about.
     */
    private function organization(Request $request): Organization
    {
        $memberships = $request->user()->organizations;
        $asked = $request->header('X-Organization');

        $membership = filled($asked) ? $memberships->firstWhere('id', $asked) : $memberships->first();

        abort_unless($membership instanceof Organization, 403, 'You are not a member of that organization.');

        return Organization::query()->findOrFail($membership->id);
    }
}
