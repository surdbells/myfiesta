<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Audit\Auditor;
use App\Services\Images\ImageRejected;
use App\Services\Images\ImageStore;
use App\Services\Organizations\Socials;
use Closure;
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
 * Where else to find them — Instagram, TikTok, X, Facebook and a website — is
 * set here too, and shown on their organizer page. Each is checked and kept
 * the way Socials reads it, so the page never links to something an
 * organizer did not mean.
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
            // Where else to find them. Each one may be the username, the
            // username with its @, or the address copied from the browser —
            // whichever an organizer has to hand — and is kept as the part
            // that names the account (Socials). Null or empty takes it off.
            'socials' => ['sometimes', 'array:'.implode(',', Socials::NETWORKS)],
            'socials.instagram' => ['nullable', 'string', 'max:255', $this->readableAs('instagram',
                'That is not an Instagram username. It is up to 30 letters, numbers, full stops and underscores — the part after instagram.com/.')],
            'socials.tiktok' => ['nullable', 'string', 'max:255', $this->readableAs('tiktok',
                'That is not a TikTok username. It is 2 to 24 letters, numbers, full stops and underscores — the part after tiktok.com/@.')],
            'socials.x' => ['nullable', 'string', 'max:255', $this->readableAs('x',
                'That is not an X username. It is up to 15 letters, numbers and underscores — the part after x.com/.')],
            'socials.facebook' => ['nullable', 'string', 'max:255', $this->readableAs('facebook',
                'That is not a Facebook page. Paste the page’s address, or the name after facebook.com/.')],
            'socials.website' => ['nullable', 'string', 'max:255', $this->readableAs('website',
                'That is not a website address we can link to. It has to be an https:// address, like https://lagosnights.com.')],
        ]);

        $tracked = ['name', 'description', ...array_values(Socials::COLUMNS)];
        $before = $organization->only($tracked);

        foreach ($data['socials'] ?? [] as $network => $value) {
            $organization->setAttribute(Socials::COLUMNS[$network], Socials::normalise($network, $value));
        }

        unset($data['socials']);

        $organization->fill($data)->save();

        // Worth its own line in the record: this is the moment a tick stopped
        // being shown, and somebody will ask why.
        if ($organization->awaitsRenameCheck() && $before['name'] !== $organization->name) {
            $this->auditor->record(
                'organization.renamed_after_verification',
                $organization,
                $request->user(),
                metadata: ['from' => $before['name'], 'to' => $organization->name],
            );
        }

        $this->auditor->record(
            'organization.brand_updated',
            $organization,
            $request->user(),
            metadata: ['changed' => array_keys(array_diff_assoc($organization->only($tracked), $before))],
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
            'is_verified' => $organization->isVerified(),
            // A rename does not destroy the verification, it suspends the
            // claim. Said here so the screen can explain rather than leave
            // somebody wondering where their tick went.
            'verification_pending_name' => $organization->awaitsRenameCheck(),
            // As the form should start from: each one made sense of, or null
            // where nothing is kept or what is kept cannot be read.
            'socials' => Socials::of($organization),
        ];
    }

    /**
     * A rule that the value names an account on that network.
     *
     * Checked by reading it the way it will be kept, so whatever passes here
     * is exactly what the page links to.
     */
    private function readableAs(string $network, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($network, $message): void {
            if (is_string($value) && trim($value) !== '' && Socials::normalise($network, $value) === null) {
                $fail($message);
            }
        };
    }
}
