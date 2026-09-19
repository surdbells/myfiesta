<?php

namespace App\Http\Controllers;

use App\Models\DataRequest;
use App\Services\PersonalData\Requests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The pages behind the link in a privacy email.
 *
 * Served here rather than on the site app for the same reason the unsubscribe
 * pages are: they have to work with no account, no session and no JavaScript,
 * for somebody who may be doing this from a mail client's own browser.
 *
 * A GET never acts. Mail scanners follow links in messages, and an erasure
 * that ran on a prefetch would delete somebody's account because their
 * employer's security software was doing its job.
 */
class DataRequestPageController extends Controller
{
    public function __construct(private readonly Requests $requests) {}

    public function show(string $token): Response
    {
        $request = $this->find($token);

        if ($request === null) {
            return response()->view('privacy-request', ['state' => 'unknown'], 404);
        }

        return response()->view('privacy-request', [
            'state' => $request->status === 'pending' ? 'confirm' : 'done',
            'request' => $request,
            'token' => $token,
        ]);
    }

    public function confirm(Request $httpRequest, string $token): Response
    {
        $request = $this->find($token);

        if ($request === null) {
            return response()->view('privacy-request', ['state' => 'unknown'], 404);
        }

        if ($request->awaitingProof()) {
            $request = $this->requests->fulfil($request);
        }

        return response()->view('privacy-request', [
            'state' => 'done',
            'request' => $request,
            'token' => $token,
        ]);
    }

    /** The export itself, straight from the private disk. */
    public function download(string $token): StreamedResponse|Response
    {
        $request = $this->find($token);

        if ($request === null || ! $request->isDownloadable()) {
            return response()->view('privacy-request', ['state' => 'unknown'], 404);
        }

        return Storage::disk('private')->download($request->file_path, 'myfiesta-your-data.json');
    }

    private function find(string $token): ?DataRequest
    {
        return $token === '' ? null : DataRequest::where('token', $token)->first();
    }
}
