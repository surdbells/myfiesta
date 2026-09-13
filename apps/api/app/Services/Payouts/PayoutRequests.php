<?php

namespace App\Services\Payouts;

use App\Enums\PlatformRole;
use App\Mail\PayoutRequestDecided;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * An organizer asks to be paid; staff pay it or say why not.
 *
 * The rules, in the order they bite:
 *
 * - An organizer asks for money they are owed, never more. Asking for an
 *   advance is a conversation, not a form field.
 * - They need somewhere to send it first. A request with no payout details
 *   is one nobody here can act on.
 * - One open request per currency, so the same balance cannot be asked for
 *   twice and paid twice.
 * - Paying goes through SettlementRecorder like every payout, so the ledger
 *   entry, the verification check and the audit trail are the same ones.
 * - Paying more than is owed — an overdraft — is possible here and nowhere
 *   else, and only by an administrator, with a reason.
 */
class PayoutRequests
{
    public function __construct(
        private readonly SettlementRecorder $settlements,
        private readonly Auditor $auditor,
    ) {}

    /** What the organization is owed in this currency, now. */
    public function available(Organization $organization, string $currency): Money
    {
        return LedgerEntry::balancesFor($organization)[$currency] ?? Money::zero($currency);
    }

    /** @throws PayoutRequestRefused */
    public function request(Organization $organization, User $by, Money $amount, ?string $note = null): PayoutRequest
    {
        if ($amount->amount <= 0) {
            throw PayoutRequestRefused::because('Ask for an amount above zero.');
        }

        $destination = OrganizationPayoutDetail::query()
            ->where('organization_id', $organization->id)
            ->where('currency', $amount->currency)
            ->first();

        if ($destination === null) {
            throw PayoutRequestRefused::because('Add where to send the money first — your Interac email or bank account — then ask to be paid.');
        }

        return DB::transaction(function () use ($organization, $by, $amount, $note) {
            // Serialised per organization, so two requests from two tabs
            // cannot both read the same balance.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

            $available = $this->available($organization, $amount->currency);

            if ($available->amount <= 0) {
                throw PayoutRequestRefused::because('There is nothing owed to you in '.$amount->currency.' right now.');
            }

            if ($amount->amount > $available->amount) {
                throw PayoutRequestRefused::because('You can ask for up to '.$available->format().', which is what you are owed right now.');
            }

            if (PayoutRequest::query()->where('organization_id', $organization->id)->where('currency', $amount->currency)->where('status', 'pending')->exists()) {
                throw PayoutRequestRefused::because('You already have a payout request waiting. Withdraw it first if you want to change the amount.');
            }

            try {
                $request = PayoutRequest::create([
                    'organization_id' => $organization->id,
                    'currency' => $amount->currency,
                    'amount' => $amount->amount,
                    'balance_at_request' => $available->amount,
                    'note' => filled($note) ? trim($note) : null,
                    'requested_by' => $by->id,
                    'status' => 'pending',
                ]);
            } catch (UniqueConstraintViolationException) {
                throw PayoutRequestRefused::because('You already have a payout request waiting.');
            }

            $this->auditor->record('payout_request.created', $request, $by, $organization->id, [
                'amount' => $amount->amount,
                'currency' => $amount->currency,
                'balance' => $available->amount,
            ]);

            return $request;
        });
    }

    /** @throws PayoutRequestRefused */
    public function cancel(PayoutRequest $request, User $by): void
    {
        $this->decide($request, function (PayoutRequest $locked) use ($by) {
            $locked->update(['status' => 'cancelled', 'decided_by' => $by->id, 'decided_at' => now()]);

            $this->auditor->record('payout_request.cancelled', $locked, $by, $locked->organization_id);
        });
    }

    /**
     * Pay it: record the settlement and close the request.
     *
     * The amount may differ from what was asked — less when the balance has
     * dropped since (a refund came in), more only as an overdraft.
     *
     * @throws PayoutRequestRefused|SettlementRefused
     */
    public function pay(PayoutRequest $request, User $by, Money $amount, string $rail, ?string $note = null): PayoutRequest
    {
        if (! $by->platform_role?->canSettle()) {
            throw PayoutRequestRefused::because('Only platform administrators and finance can pay payout requests.');
        }

        if ($amount->currency !== $request->currency) {
            throw PayoutRequestRefused::because('A request in '.$request->currency.' is paid in '.$request->currency.'.');
        }

        return $this->decide($request, function (PayoutRequest $locked) use ($by, $amount, $rail, $note) {
            $organization = $locked->organization;
            $available = $this->available($organization, $amount->currency);
            $overdraft = $amount->amount > $available->amount;

            // The one place an overdraft can be given, and only by an admin.
            if ($overdraft && $by->platform_role !== PlatformRole::Admin) {
                throw PayoutRequestRefused::because(
                    'That is more than they are owed ('.$available->format().'). Only an administrator can pay more than is owed.'
                );
            }

            $settlement = $this->settlements->record(
                $organization,
                $amount,
                $rail,
                $note,
                $by,
                overdraftApproved: $overdraft,
            );

            $locked->update([
                'status' => 'paid',
                'paid_amount' => $amount->amount,
                'settlement_id' => $settlement->id,
                'decision_note' => filled($note) ? trim($note) : null,
                'decided_by' => $by->id,
                'decided_at' => now(),
            ]);

            $this->auditor->record('payout_request.paid', $locked, $by, $organization->id, [
                'requested' => $locked->amount,
                'paid' => $amount->amount,
                'currency' => $amount->currency,
                'overdraft' => $overdraft,
                'settlement_id' => $settlement->id,
            ]);

            return $locked;
        }, notify: true);
    }

    /** @throws PayoutRequestRefused */
    public function reject(PayoutRequest $request, User $by, string $reason): PayoutRequest
    {
        if (! $by->platform_role?->canSettle()) {
            throw PayoutRequestRefused::because('Only platform administrators and finance can decide payout requests.');
        }

        if (trim($reason) === '') {
            throw PayoutRequestRefused::because('Say why, so the organizer knows what to do next.');
        }

        return $this->decide($request, function (PayoutRequest $locked) use ($by, $reason) {
            $locked->update([
                'status' => 'rejected',
                'decision_note' => trim($reason),
                'decided_by' => $by->id,
                'decided_at' => now(),
            ]);

            $this->auditor->record('payout_request.rejected', $locked, $by, $locked->organization_id, [
                'amount' => $locked->amount,
                'currency' => $locked->currency,
            ]);

            return $locked;
        }, notify: true);
    }

    /**
     * Lock the request, check it is still open, then act.
     *
     * Two staff opening the same request at once is exactly how one gets paid
     * twice; the second finds it already decided.
     */
    private function decide(PayoutRequest $request, callable $act, bool $notify = false): PayoutRequest
    {
        $decided = DB::transaction(function () use ($request, $act) {
            $locked = PayoutRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw PayoutRequestRefused::because('This request was already '.$locked->status.'.');
            }

            return $act($locked) ?? $locked;
        });

        if ($notify && $decided->requester?->email) {
            Mail::to($decided->requester->email)->send(new PayoutRequestDecided($decided));
        }

        return $request->refresh();
    }
}
