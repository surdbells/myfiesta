<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Audit\Auditor;
use App\Services\Images\ImageRejected;
use App\Services\Images\ImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * What an organization looks like on the pages it sells from.
 *
 * Until now these three fields — the name, the description and the mark —
 * could only be set by the legacy importer, so every organizer who signed up
 * after the migration had a blank card on every event page they published,
 * with no way to fill it in.
 *
 * The slug is not here and never will be. It is in links organizers have
 * already handed out, and a display name somebody wants to tidy up should not
 * quietly break them.
 */
class BrandController extends Controller
{
    public function __construct(
        private readonly ImageStore $images,
        private readonly Auditor $auditor,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->present($this->organization($request, read: true)));
    }

    public function update(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            // Plain text. This is read on an event page beside the organizer's
            // name, not rendered as markup — an organizer who pastes HTML here
            // should see what they pasted, not a broken page.
            'description' => ['sometimes', 'nullable', 'string', 'max:600'],
        ]);

        $before = $organization->only(['name', 'description']);

        $organization->fill($data)->save();

        $this->auditor->record(
            'organization.brand_updated',
            $organization,
            $request->user(),
            metadata: ['changed' => array_keys(array_diff_assoc($organization->only(['name', 'description']), $before))],
        );

        return response()->json($this->present($organization));
    }

    public function storeLogo(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $request->validate([
            // 8MB. A logo is smaller than any photograph, and the mimes rule
            // reads the file rather than the name — ImageStore checks the
            // bytes again before anything is written.
            'file' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ]);

        try {
            $this->images->logo($organization, $request->file('file'));
        } catch (ImageRejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->auditor->record('organization.logo_updated', $organization, $request->user());

        return response()->json($this->present($organization->fresh()));
    }

    public function destroyLogo(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $this->images->removeLogo($organization);

        $this->auditor->record('organization.logo_removed', $organization, $request->user());

        return response()->json($this->present($organization->fresh()));
    }

    /**
     * The organization this request is about, and whether it may be changed.
     *
     * Taken from the header the console sends and checked against membership
     * rather than trusted, the same way the dashboard does it.
     *
     * Reading is for any staff member — the console shows this card on a
     * settings screen everybody can open. Changing it is the owner's, like the
     * team: this is the identity every event is published under.
     */
    private function organization(Request $request, bool $read = false): Organization
    {
        $memberships = $request->user()->organizations()->get();
        $asked = $request->header('X-Organization');

        $organization = $asked
            ? $memberships->firstWhere('id', $asked)
            : $memberships->first();

        abort_if($organization === null, 403, 'No organization.');

        if (! $read && ! $request->user()->hasPermissionIn($organization->id, Permission::BrandManage)) {
            throw new AccessDeniedHttpException('Only an owner can change how the organization appears.');
        }

        return $organization;
    }

    private function present(Organization $organization): array
    {
        return [
            'name' => $organization->name,
            'slug' => $organization->slug,
            'description' => $organization->description,
            'logo_url' => $organization->logo_path
                ? Storage::disk('public')->url($organization->logo_path)
                : null,
            'is_verified' => $organization->verified_at !== null,
        ];
    }
}
