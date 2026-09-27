<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Who operates the platform and how to reach them, for the public site.
 *
 * Served rather than written into the site's templates, so the details are
 * set once per deployment in config/myfiesta.php and staging never shows a
 * production address — or the reverse, which is worse.
 *
 * `complete` is the honest part. The legal pages show a loud notice while it
 * is false, so a launch with the operator's details missing is visible on the
 * page rather than discovered by a regulator.
 */
class ContactController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $contact = config('myfiesta.contact');
        $blank = fn (mixed $value) => filled($value) ? trim((string) $value) : null;

        $addresses = collect($contact['addresses'] ?? [])
            ->map($blank)
            ->filter()
            ->map(fn (string $address, string $country) => ['country' => $country, 'address' => $address])
            ->values();

        $data = [
            'company_name' => $blank($contact['company_name'] ?? null),
            'company_number' => $blank($contact['company_number'] ?? null),
            'support_email' => $blank($contact['support_email'] ?? null),
            'privacy_email' => $blank($contact['privacy_email'] ?? null),
            'phone' => $blank($contact['phone'] ?? null),
            'addresses' => $addresses->all(),
        ];

        // What the legal pages cannot do without. A phone number and a
        // registration number are worth showing when there is one; a name, an
        // inbox and somewhere post arrives are what "reachable" means.
        $data['complete'] = $data['company_name'] !== null
            && $data['support_email'] !== null
            && $addresses->isNotEmpty();

        return response()
            ->json(['data' => $data])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
