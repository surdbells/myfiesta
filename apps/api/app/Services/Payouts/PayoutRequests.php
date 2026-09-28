<?php

namespace App\Services\Payouts;

use App\Mail\PayoutRequestDecided;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Organizations\Suspension;
use App\Services\Organizations\WhileSuspended;
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
 *   else, by the people who may pay at all (administrators and finance), with
 *   a written reason. The request keeps how much was advanced, why, who
 *   approved it and when; the ledger records the payout exactly as any other,
 *   so the balance goes below zero by the advance and nothing else.
 * - While the balance is not above zero nothing more can be asked for. The
 *   next sales pay the advance back first (see Overdrafts).
 * - Staff on the organization's own team do not decide its requests, so the
 *   person who asked for the money is never the one who pays it
 *   (OwnOrganization).
 * - Nothing is asked for or paid while the organization is suspended. A
 *   request already waiting is held, not rejected (see Suspension), and can
 *   still be withdrawn by the organizer or refused by staff with a reason.
 */
class PayoutRequests
{
    public function __construct(
        private readonly SettlementRecorder $settlements,
        private readonly Auditor $auditor,
        private readonly Overdrafts $overdrafts,
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
            $locked = Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

            // Read from the locked row, so a suspension landing at the same
            // moment is either seen here or holds this request as it lands.
            if ($locked?->suspended_at !== null) {
                throw PayoutRequestRefused::because(WhileSuspended::withContact(WhileSuspended::PAYOUTS));
            }

            $available = $this->available($organization, $amount->currency);

            // Below zero: money was advanced, or refunds overtook sales after a
            // payout, and the next sales go to paying that back first. Said in
            // full, so nobody is left wondering where their sales went.
            if ($available->amount < 0) {
                $position = $this->overdrafts->position($organization, $amount->currency);

                throw PayoutRequestRefused::because(
                    'Your balance in '.$amount->currency.' is below zero. '
                    .($position ? $position->summary(Overdrafts::zoneOf($organization->id)).' ' : '')
                    .'Your next sales pay it back, and you can ask to be paid again once your balance is above zero.'
                );
            }

            if ($available->amount === 0) {
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
     * An overdraft is the difference between what is paid and what is owed
     * at this moment, which is how far below zero the payment takes that
     * balance. It needs a written reason of its own, separate from the note
     * the organizer reads — which still answers for paying to unverified
     * details — and the request keeps both figures and the name.
     *
     * @throws PayoutRequestRefused|SettlementRefused
     */
    public function pay(PayoutRequest $request, User $by, Money $amount, string $rail, ?string $note = null, ?string $overdraftReason = null): PayoutRequest
    {
        if (! $by->platform_role?->canSettle()) {
            throw PayoutRequestRefused::because('Only platform administrators and finance can pay payout requests.');
        }

        // Advance or not: never by somebody on the organization's own team.
        if (OwnOrganization::includes($by, $request->organization_id)) {
            throw PayoutRequestRefused::because(OwnOrganization::DECIDE_REQUEST);
        }

        if ($amount->currency !== $request->currency) {
            throw PayoutRequestRefused::because('A request in '.$request->currency.' is paid in '.$request->currency.'.');
        }

        return $this->decide($request, function (PayoutRequest $locked) use ($by, $amount, $rail, $note, $overdraftReason) {
            // Held, not rejected: it waits, with its place in the queue,
            // until the suspension is lifted. Either test is enough — the
            // flag is what the screens show, the organization is the rule.
            if ($locked->held_at !== null || Suspension::inForce($locked->organization_id)) {
                throw PayoutRequestRefused::because('This organization is suspended, so its payouts are frozen. The request is held, and can be paid once the suspension is lifted.');
            }

            // Held since before the request was (see decide), so repayments
            // and requests for this organization wait.
            $organization = Organization::query()->whereKey($locked->organization_id)->firstOrFail();

            // Sales and refunds do not wait for that lock, so the balance is
            // read once, here, and the same figure is handed to the recorder.
            // Read twice, a sale landing in between would leave the request
            // and the audit trail saying money was advanced while the ledger
            // says the payout was covered, or refuse a payment the balance
            // covered a moment before.
            $available = $this->available($organization, $amount->currency);
            $overdraft = new Money(max(0, $amount->amount - $available->amount), $amount->currency);
            $reason = trim((string) $overdraftReason);

            // The one place an overdraft can be given: by the people who may
            // pay at all (checked above), and never without saying why.
            if (! $overdraft->isZero() && $reason === '') {
                throw PayoutRequestRefused::because(
                    'That pays '.$overdraft->format().' more than they are owed ('.$available->format().'). '
                    .'Say why myFiesta is advancing it.'
                );
            }

            // The note and the advance's reason go separately: the note is
            // also what answers for paying to unverified details, and a reason
            // to advance money is not a reason to skip checking where it goes.
            $settlement = $this->settlements->record(
                $organization,
                $amount,
                $rail,
                filled($note) ? trim((string) $note) : null,
                $by,
                advanceReason: $overdraft->isZero() ? null : $reason,
                balance: $available,
            );

            $locked->update([
                'status' => 'paid',
                'paid_amount' => $amount->amount,
                'overdraft_amount' => $overdraft->isZero() ? null : $overdraft->amount,
                'overdraft_reason' => $overdraft->isZero() ? null : $reason,
                'settlement_id' => $settlement->id,
                'decision_note' => filled($note) ? trim($note) : null,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'approved_by' => $by->id,
                'approved_at' => now(),
            ]);

            $this->auditor->record('payout_request.paid', $locked, $by, $organization->id, [
                'requested' => $locked->amount,
                'paid' => $amount->amount,
                'currency' => $amount->currency,
                'overdraft' => ! $overdraft->isZero(),
                'settlement_id' => $settlement->id,
            ]);

            // The decision itself, on its own line of the trail: who, when,
            // how much beyond the balance, and why.
            if (! $overdraft->isZero()) {
                $this->auditor->record('payout_request.overdraft_approved', $locked, $by, $organization->id, [
                    'balance' => $available->amount,
                    'paid' => $amount->amount,
                    'overdraft_amount' => $overdraft->amount,
                    'currency' => $amount->currency,
                    'reason' => $reason,
                    'settlement_id' => $settlement->id,
                ]);
            }

            return $locked;
        }, notify: true, organizationFirst: true);
    }

    /** @throws PayoutRequestRefused */
    public function reject(PayoutRequest $request, User $by, string $reason): PayoutRequest
    {
        if (! $by->platform_role?->canSettle()) {
            throw PayoutRequestRefused::because('Only platform administrators and finance can decide payout requests.');
        }

        if (OwnOrganization::includes($by, $request->organization_id)) {
            throw PayoutRequestRefused::because(OwnOrganization::DECIDE_REQUEST);
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
     *
     * Paying reads the balance, so it holds the organization too — taken
     * before the request, the order Suspension takes them in, so paying and
     * suspending at the same moment wait for each other rather than deadlock.
     */
    private function decide(PayoutRequest $request, callable $act, bool $notify = false, bool $organizationFirst = false): PayoutRequest
    {
        $decided = DB::transaction(function () use ($request, $act, $organizationFirst) {
            if ($organizationFirst) {
                Organization::query()->whereKey($request->organization_id)->lockForUpdate()->first();
            }

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
