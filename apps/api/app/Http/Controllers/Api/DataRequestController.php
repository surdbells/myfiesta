<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PersonalData\Requests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Send me what you hold about me", or "forget me".
 *
 * Open to anybody with an address, because guest checkout means most people
 * holding a ticket have no account to ask from. Nothing happens until the
 * link emailed to that address comes back.
 */
class DataRequestController extends Controller
{
    public function __construct(private readonly Requests $requests) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'kind' => ['required', Rule::in(['export', 'erasure'])],
        ]);

        $this->requests->open($data['kind'], $data['email'], $request->ip());

        // The same answer whoever asked, known address or not: anything else
        // turns this into a way to find out who has an account here.
        return response()->json([
            'message' => 'If that address is on myFiesta, a link is on its way to it. '
                .'The link is how we know the request is yours; nothing happens until you follow it.',
        ], 202);
    }
}
