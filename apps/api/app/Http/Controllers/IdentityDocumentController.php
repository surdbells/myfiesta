<?php

namespace App\Http\Controllers;

use App\Enums\PlatformRole;
use App\Models\OrganizationIdentityDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an identity document image from the private disk.
 *
 * The file is never web-reachable. Reaching it needs a signed URL that expires
 * in minutes, and the signature alone is not enough — the caller must still be
 * platform staff with the right to review identity. A signed link that leaked
 * would otherwise be a permanent handle on someone government ID.
 */
class IdentityDocumentController extends Controller
{
    public function show(Request $request, OrganizationIdentityDocument $document): StreamedResponse
    {
        abort_unless(
            $request->user()?->hasPlatformRole(PlatformRole::Admin, PlatformRole::Support) ?? false,
            403,
            'Identity documents are restricted to reviewers.',
        );

        abort_unless(filled($document->document_path), 404);

        $disk = Storage::disk('private');

        abort_unless($disk->exists($document->document_path), 404);

        // Inline rather than an attachment: this is reviewed on screen, and a
        // download leaves a copy outside the audited path.
        return $disk->response(
            $document->document_path,
            name: 'identity-document',
            headers: [
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
