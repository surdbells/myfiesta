<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationFollow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Organizers somebody wants to hear from.
 *
 * The organizer is told how many follow them and never who. A follower list is
 * a mailing list somebody built without asking, and nobody follows a party
 * expecting to end up on one.
 *
 * Following is what a later "they announced a night" notification is built on,
 * which is why it is a list of organizations rather than a flag on a profile.
 */
class FollowController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $following = Organization::query()
            ->join('organization_follows', 'organization_follows.organization_id', '=', 'organizations.id')
            ->where('organization_follows.user_id', $request->user()->id)
            ->orderBy('organizations.name')
            ->select('organizations.*')
            ->get()
            ->map(fn (Organization $organization) => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'is_verified' => $organization->isVerified(),
            ]);

        return response()->json(['data' => $following]);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $organization = $this->organization($slug);

        // Through the model, not a raw upsert: the row carries the token that
        // an announcement email's way out is built on, and the model is where
        // that gets generated.
        OrganizationFollow::firstOrCreate([
            'user_id' => $request->user()->id,
            'organization_id' => $organization->id,
        ]);

        return response()->json(['following' => true]);
    }

    public function destroy(Request $request, string $slug): JsonResponse
    {
        $organization = $this->organization($slug);

        DB::table('organization_follows')
            ->where('user_id', $request->user()->id)
            ->where('organization_id', $organization->id)
            ->delete();

        return response()->json(['following' => false]);
    }

    private function organization(string $slug): Organization
    {
        $organization = Organization::query()->where('slug', $slug)->first();

        if ($organization === null) {
            throw new NotFoundHttpException('Organizer not found.');
        }

        return $organization;
    }
}
