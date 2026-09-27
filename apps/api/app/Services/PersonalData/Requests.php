<?php

namespace App\Services\PersonalData;

use App\Mail\DataRequestDone;
use App\Mail\DataRequestVerify;
use App\Models\DataRequest;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Taking a privacy request, proving it, and carrying it out.
 *
 * Proof first, always. An erasure that ran on whatever address was typed into
 * a form would be a way to delete a stranger's account, and an export that did
 * would be a way to read their orders. So a request does nothing until the
 * link sent to the address comes back, and the link is the whole credential —
 * the same shape as the unsubscribe, because the same people have to be able
 * to use it without an account.
 */
class Requests
{
    public function __construct(
        private readonly Exporter $exporter,
        private readonly Eraser $eraser,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Start one, and email the link that proves it.
     *
     * The caller is told nothing about whether the address is known here: an
     * endpoint that says "no such person" is a way to ask whether somebody has
     * an account, and a privacy feature cannot be the one that answers that.
     */
    public function open(string $kind, string $email, ?string $ip): DataRequest
    {
        $subject = Subject::forEmail($email);

        $request = DataRequest::create([
            'kind' => $kind,
            'email' => $subject->email,
            'user_id' => $subject->user?->id,
            'status' => 'pending',
            'ip_address' => $ip,
        ]);

        // Nothing held about them, nothing to confirm: somebody typing a
        // stranger's address into the form must not be able to send them mail
        // through us. The row is kept either way, because a flood of requests
        // for addresses we do not know is worth being able to see.
        if ($subject->isKnown()) {
            Mail::to($subject->email)->queue(new DataRequestVerify($request));
        }

        return $request;
    }

    /**
     * An erasure asked for by somebody signed in: "Delete my account" in the
     * phone app or the console.
     *
     * The same request the privacy page opens, recorded the same way and
     * carried out by fulfil() below. What differs is the proof. The emailed
     * link shows that whoever typed an address can read its inbox; somebody
     * signed in who has just typed the account's password has shown the
     * account is theirs — and when the account's address is one it proved,
     * that covers the address too, so the link would only be a detour.
     *
     * An address the account never proved is not enough. Guest orders and
     * tickets are found by address, and an account opened under somebody
     * else's before sign-ups waited for their link would otherwise erase that
     * person's tickets along with itself. Then the link goes out exactly as it
     * does from the form, and nothing happens until it comes back.
     *
     * The caller has checked the password, and Eraser::refusal — staff
     * access, or being the only owner of an organization: each is a step
     * they can take now, and a request opened only to be refused would tell
     * them so by email instead.
     */
    public function openForAccount(User $user, ?string $ip): DataRequest
    {
        $subject = new Subject($user->email, $user);

        $request = DataRequest::create([
            'kind' => 'erasure',
            'email' => $subject->email,
            'user_id' => $user->id,
            'status' => 'pending',
            'ip_address' => $ip,
        ]);

        if ($user->email_verified_at === null) {
            Mail::to($subject->email)->queue(new DataRequestVerify($request));

            return $request;
        }

        return $this->fulfil($request);
    }

    /**
     * The link came back. Do the thing.
     *
     * Immediately rather than in thirty days: the law allows a month because
     * some of this is done by hand elsewhere, and none of it is here.
     */
    public function fulfil(DataRequest $request): DataRequest
    {
        if ($request->status !== 'pending') {
            return $request;
        }

        $subject = Subject::forEmail($request->email);

        // All or nothing. An erasure that stopped partway would leave an
        // account with its name gone and its password and tokens still
        // working, and a request stuck at "verified" that nothing retries.
        // Rolled back, the request is still pending and its link still works
        // once whatever stopped it is fixed. The audit entry and the email
        // come after, so they describe something that happened.
        DB::transaction(function () use ($request, $subject): void {
            $request->update([
                'status' => 'verified',
                'verified_at' => now(),
                'due_at' => now()->addDays(DataRequest::DUE_DAYS),
            ]);

            $request->kind === 'export'
                ? $this->export($request, $subject)
                : $this->erase($request, $subject);
        });

        $this->auditor->record("privacy.{$request->kind}", $request, null, metadata: [
            'status' => $request->status,
            'email' => $request->email,
        ]);

        Mail::to($request->email)->queue(new DataRequestDone($request->fresh()));

        return $request->fresh();
    }

    /** Drop an export that has sat past its week, and close requests never proved. */
    public function prune(): array
    {
        $expired = DataRequest::query()
            ->where('kind', 'export')
            ->where('status', 'completed')
            ->whereNotNull('file_path')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expired as $request) {
            Storage::disk('private')->delete($request->file_path);
            $request->update(['file_path' => null, 'status' => 'expired']);
        }

        $unproved = DataRequest::query()
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subHours(DataRequest::VERIFY_HOURS))
            ->update(['status' => 'expired', 'token' => null]);

        return ['exports_removed' => $expired->count(), 'unproved_closed' => $unproved];
    }

    private function export(DataRequest $request, Subject $subject): void
    {
        $request->update([
            'status' => 'completed',
            'completed_at' => now(),
            'file_path' => $this->exporter->write($subject, $request->id),
            'expires_at' => now()->addDays(DataRequest::DOWNLOAD_DAYS),
        ]);
    }

    private function erase(DataRequest $request, Subject $subject): void
    {
        $refusal = $this->eraser->refusal($subject);

        if ($refusal !== null) {
            $request->update([
                'status' => 'refused',
                'completed_at' => now(),
                'outcome' => ['refused' => $refusal],
            ]);

            return;
        }

        $request->update([
            'status' => 'completed',
            'completed_at' => now(),
            'outcome' => ['erased' => $this->eraser->erase($subject)],
        ]);
    }
}
