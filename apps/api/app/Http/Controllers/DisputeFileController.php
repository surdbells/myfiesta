<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Services\Disputes\DisputeDesk;
use App\Services\StaffSupport\StaffActionRefused;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * One of a dispute's documents, to Admin or Finance, from the dispute's page.
 *
 * The documents name the buyer and say where they were and when, so they are
 * never on a public disk and never at an address that works on its own: the
 * link is signed and lasts minutes, and the person following it must still be
 * somebody who can answer the dispute. Support can read the dispute's page but
 * not open these — the same line as downloading an export.
 *
 * Inline for a preview, as an attachment for a download; never cached.
 */
class DisputeFileController extends Controller
{
    public function __invoke(Request $request, Dispute $dispute, string $file): Response
    {
        abort_unless(DisputeDesk::mayAnswer($request->user()), 403, 'Only Admin and Finance can open a dispute\'s documents.');

        try {
            $document = app(DisputeDesk::class)->file($dispute, $file);
        } catch (StaffActionRefused) {
            abort(404);
        }

        $name = Str::of($document->name)->replaceMatches('/[^A-Za-z0-9._-]/', '')->value();

        return response($document->bytes, 200, [
            'Content-Type' => $document->mimeType,
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
