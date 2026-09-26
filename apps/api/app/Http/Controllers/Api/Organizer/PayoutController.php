<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Mail\PayoutDestinationChanged;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Models\PayoutRequest;
use App\Models\SensitiveDataAccess;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Payouts\PayoutRequestRefused;
use App\Services\Payouts\PayoutRequests;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * What an organizer is owed, what has been paid, and where to send it.
 *
 * None of this was reachable. The settlements table, the ledger and the payout
 * details have existed since the first migrations and no endpoint returned any
 * of them, so an organizer could see a balance on their dashboard and had no
 * way to find out which events it came from or whether anything had ever been
 * sent.
 *
 * Settlements are read-only here on purpose. Paying somebody out is a manual
 * act performed by this business against a real bank, and an endpoint that let
 * an organizer record one would be an endpoint that lets an organizer mark
 * themselves as paid. What an organizer can do is ask — a payout request —
 * which moves nothing until staff pay it.
 */
class PayoutController extends Controller
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly PayoutRequests $requests,
    ) {}

    /**
     * The statement.
     *
     * Three things, because they answer the three questions in order: what am
     * I owed, which events is that, and what have you actually sent me.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $currency = $this->currency($organization);

        $entries = LedgerEntry::query()
            ->where('organization_id', $organization->id)
            ->where('currency', $currency);

        $money = fn (int $amount) => ['amount' => $amount, 'currency' => $currency];

        return response()->json([
            'currency' => $currency,
            'balance' => $money((int) (clone $entries)->sum('amount')),
            'settled' => $money((int) abs((clone $entries)->where('type', 'settlement')->sum('amount'))),
            'events' => $this->byEvent($organization, $currency),
            'settlements' => $this->settlements($organization),
            'destination' => $this->destination($organization),
            'requests' => $this->requestHistory($organization),
            // Whether this member may ask to be paid — owners and finance.
            'can_request' => $request->user()->hasPermissionIn($organization->id, Permission::PayoutsRequest),
            // Whether this member may change where it is sent — owners only.
            // Sent rather than left for the client to work out, so a manager
            // is never offered a form that answers 403.
            'can_change_destination' => $request->user()->hasPermissionIn($organization->id, Permission::PayoutsDestination),
        ]);
    }

    /**
     * Ask to be paid what is owed.
     *
     * An amount up to the balance, to the payout details on file. Paying it,
     * and anything beyond the balance, is decided by platform staff.
     */
    public function requestPayout(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        abort_unless(
            $request->user()->hasPermissionIn($organization->id, Permission::PayoutsRequest),
            403,
            'Only owners and finance can ask for a payout.',
        );

        $data = $request->validate([
            // Minor units, like every amount the console sends.
            'amount' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $payout = $this->requests->request(
                $organization,
                $request->user(),
                new Money((int) $data['amount'], $this->currency($organization)),
                $data['note'] ?? null,
            );
        } catch (PayoutRequestRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->presentRequest($payout),
            'message' => 'Payout requested. We will let you know when it is sent.',
        ], 201);
    }

    /** Withdraw a request that has not been decided. */
    public function cancelRequest(Request $request, PayoutRequest $payoutRequest): JsonResponse
    {
        $organization = $this->organization($request);

        abort_unless($payoutRequest->organization_id === $organization->id, 404);
        abort_unless($request->user()->hasPermissionIn($organization->id, Permission::PayoutsRequest), 403);

        try {
            $this->requests->cancel($payoutRequest, $request->user());
        } catch (PayoutRequestRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

        return response()->json(['message' => 'Payout request withdrawn.']);
    }

    /**
     * Requests, newest first: what was asked, and what happened to it.
     *
     * @return list<array<string, mixed>>
     */
    private function requestHistory(Organization $organization): array
    {
        return PayoutRequest::query()
            ->where('organization_id', $organization->id)
            ->with('requester:id,name')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (PayoutRequest $payout) => $this->presentRequest($payout))
            ->all();
    }

    /** @return array<string, mixed> */
    private function presentRequest(PayoutRequest $payout): array
    {
        return [
            'id' => $payout->id,
            'amount' => ['amount' => $payout->amount, 'currency' => $payout->currency],
            'paid_amount' => $payout->paid_amount !== null ? ['amount' => $payout->paid_amount, 'currency' => $payout->currency] : null,
            'status' => $payout->status,
            'note' => $payout->note,
            // The reason a request was not paid, or a note on one that was.
            // Staff write it for the organizer to read.
            'decision_note' => $payout->decision_note,
            'requested_by' => $payout->requester?->name,
            'requested_at' => $payout->created_at,
            'decided_at' => $payout->decided_at,
        ];
    }

    /**
     * The balance, split by the night that earned it.
     *
     * The single figure on the dashboard is the one somebody checks; this is
     * the one they query. An organizer asking "why is this less than I
     * expected" is asking which event, and a total cannot answer that.
     *
     * Entries with no event — an organization-wide adjustment, or a settlement
     * covering several nights — are grouped separately rather than dropped,
     * because a breakdown that does not add up to the balance above it is
     * worse than no breakdown.
     *
     * @return list<array<string, mixed>>
     */
    private function byEvent(Organization $organization, string $currency): array
    {
        $rows = DB::table('ledger_entries')
            ->leftJoin('events', 'events.id', '=', 'ledger_entries.event_id')
            ->where('ledger_entries.organization_id', $organization->id)
            ->where('ledger_entries.currency', $currency)
            ->groupBy('ledger_entries.event_id', 'events.title', 'events.starts_at')
            ->orderByRaw('max(ledger_entries.occurred_at) desc')
            ->select([
                'ledger_entries.event_id',
                'events.title',
                'events.starts_at',
                DB::raw('sum(ledger_entries.amount) as balance'),
                DB::raw("sum(case when ledger_entries.type = 'sale' then ledger_entries.amount else 0 end) as gross"),
                DB::raw("sum(case when ledger_entries.type = 'settlement' then -ledger_entries.amount else 0 end) as settled"),
            ])
            ->get();

        return $rows->map(fn (object $row) => [
            'event_id' => $row->event_id,
            // Null title means the entries are not against an event. Named
            // rather than left blank, so the row reads as deliberate.
            'title' => $row->title ?? 'Not tied to an event',
            'starts_at' => $row->starts_at,
            'balance' => ['amount' => (int) $row->balance, 'currency' => $currency],
            'gross' => ['amount' => (int) $row->gross, 'currency' => $currency],
            'settled' => ['amount' => (int) $row->settled, 'currency' => $currency],
        ])->all();
    }

    /**
     * What has actually been sent, most recent first.
     *
     * @return list<array<string, mixed>>
     */
    private function settlements(Organization $organization): array
    {
        return Settlement::query()
            ->where('organization_id', $organization->id)
            ->with('event:id,title')
            ->orderByDesc('settled_at')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (Settlement $settlement) => [
                'id' => $settlement->id,
                'amount' => [
                    'amount' => $settlement->amount,
                    'currency' => $settlement->currency,
                ],
                'rail' => $settlement->rail,
                // full, partial, or overdraft. Shown rather than flattened to
                // "paid": a partial settlement is the reason a balance did not
                // go to zero, and that is a question somebody would otherwise
                // ask support.
                'type' => $settlement->type,
                'status' => $settlement->status,
                'note' => $settlement->note,
                'event' => $settlement->event
                    ? ['id' => $settlement->event->id, 'title' => $settlement->event->title]
                    : null,
                'settled_at' => $settlement->settled_at ?? $settlement->created_at,
            ])
            ->all();
    }

    /**
     * Where the money goes, masked.
     *
     * Never the account number. This endpoint exists so an organizer can
     * confirm the details on file are the right ones, and the last four digits
     * do that as well as the whole number does — while being useless to
     * anybody who obtains the response.
     *
     * @return array<string, mixed>|null
     */
    private function destination(Organization $organization): ?array
    {
        $detail = OrganizationPayoutDetail::query()
            ->where('organization_id', $organization->id)
            ->first();

        if (! $detail) {
            return null;
        }

        return [
            'rail' => $detail->rail,
            'currency' => $detail->currency,
            // Interac pays to an email address, and an address is not a secret
            // in the way an account number is — it is also the only thing that
            // identifies which one was used.
            'interac_email' => $detail->rail === 'interac' ? $detail->interac_email : null,
            'bank_name' => $detail->bank_name,
            'account_name' => $detail->account_name,
            'account_last_four' => $detail->account_last_four,
            'verified_at' => $detail->verified_at,
        ];
    }

    /**
     * Set where the money goes.
     *
     * Written, never echoed back. The response is the masked view above, which
     * is the same thing a fresh page load would show — a save that returned
     * what it just stored would put a full account number in a response for no
     * reason at all.
     *
     * Owners only. This used to need nothing more than seeing the money, so a
     * manager or a finance member could quietly point every future payout at
     * their own account. Checked before the details are even validated: a
     * member who may not change them has no business learning which fields
     * would have been accepted.
     */
    public function update(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        abort_unless(
            $request->user()->hasPermissionIn($organization->id, Permission::PayoutsDestination),
            403,
            "Only the organization's owner can change where payouts go.",
        );

        $rail = $request->input('rail');

        $data = $request->validate([
            'rail' => ['required', 'in:interac,bank_transfer'],

            // Interac in Canada: an email address and nothing else.
            'interac_email' => [$rail === 'interac' ? 'required' : 'nullable', 'email', 'max:255'],

            // A bank account, which differs by country and is validated as
            // whatever the organization actually sells in.
            'account_name' => [$rail === 'bank_transfer' ? 'required' : 'nullable', 'string', 'max:120'],
            'bank_name' => [$rail === 'bank_transfer' ? 'required' : 'nullable', 'string', 'max:120'],
            'account_number' => [$rail === 'bank_transfer' ? 'required' : 'nullable', 'string', 'max:34'],
            'transit_number' => ['nullable', 'string', 'max:10'],
            'institution_number' => ['nullable', 'string', 'max:10'],
            'bank_code' => ['nullable', 'string', 'max:20'],
        ]);

        $detail = OrganizationPayoutDetail::query()
            ->firstOrNew(['organization_id' => $organization->id]);

        // Where it pointed before, masked, for the audit entry below. "Changed
        // to the account ending 4567" answers half the question somebody asks
        // after a payout goes astray; the other half is what it was before.
        $before = $detail->exists
            ? ['rail' => $detail->rail, 'last_four' => $detail->account_last_four]
            : null;

        // The same, in words, for the owners' email — which can say the bank's
        // name, as the audit log does not.
        $previously = $detail->exists ? PayoutDestinationChanged::describe($detail) : null;

        $wasVerified = $detail->exists && $detail->verified_at !== null;

        $detail->fill($data + [
            'organization_id' => $organization->id,
            'currency' => $this->currency($organization),
            // The only part kept in the clear, and the only part ever shown.
            'account_last_four' => $rail === 'bank_transfer' && $request->filled('account_number')
                ? substr(preg_replace('/\D/', '', $request->string('account_number')), -4)
                : null,
        ]);

        /*
         * Changing these resets verification.
         *
         * A verified account that can be edited without losing that mark is a
         * verification of nothing: somebody proves one account and then points
         * the payouts at another.
         *
         * Every field that decides where money lands, not four of them. The
         * institution number and the rail used to be missing, so moving the
         * same account and transit numbers to a different bank kept the mark.
         */
        $moved = $detail->exists && $detail->isDirty(OrganizationPayoutDetail::DESTINATION_FIELDS);

        if ($moved) {
            $detail->forceFill([
                'verified_at' => null,
                'verified_by' => null,
                'verification_method' => null,
                'verification_note' => null,
            ]);
        }

        $detail->save();

        // Money moving, and the instructions for where it moves to, are the
        // two things most worth being able to attribute afterwards.
        $this->auditor->record('payout_details.updated', $organization, $request->user(), $organization->id, [
            'rail' => $detail->rail,
            'last_four' => $detail->account_last_four,
            // Null the first time details are added.
            'from' => $before,
            // Whether payouts now go somewhere they did not go before — true
            // the first time as well. For Interac this is the only thing that
            // tells a redirect apart from saving the same address again: both
            // sides read "interac, no last four", and the address itself stays
            // out of a log that is kept after somebody asks to be erased.
            'destination_changed' => $before === null || $moved,
            'verification_cleared' => $moved && $wasVerified,
        ]);

        SensitiveDataAccess::record(
            $request->user(),
            OrganizationPayoutDetail::class,
            $detail->id,
            'write',
            $request->ip(),
        );

        // The same test the record above makes, so the owners are told about
        // exactly the saves the log calls a change — and not about somebody
        // opening the form and saving what was already there.
        if ($before === null || $moved) {
            $this->tellOwners($organization, $request->user(), $detail, $previously);
        }

        return response()->json($this->destination($organization));
    }

    /**
     * Email everybody who decides where the money goes that it now goes
     * somewhere else.
     *
     * The audit log records a redirect; nobody reads it until a payout has
     * already gone astray. An owner's account is exactly what somebody would
     * take over to redirect the money, and an organization can have several
     * owners, so every one of them hears — including whoever made the change,
     * since if it was not them this is how they learn their account is in use.
     *
     * Chosen by the permission rather than by naming the role, so whoever may
     * change the destination is always whoever is told it changed.
     */
    private function tellOwners(Organization $organization, User $by, OrganizationPayoutDetail $detail, ?string $previously): void
    {
        $roles = array_values(array_map(
            fn (Role $role) => $role->value,
            array_filter(
                Role::cases(),
                fn (Role $role) => in_array(Permission::PayoutsDestination, Permission::forRole($role), true),
            ),
        ));

        $destination = PayoutDestinationChanged::describe($detail);
        $at = $detail->updated_at ?? now();
        $zone = $this->zone($organization);

        $organization->members()
            ->wherePivotIn('role', $roles)
            ->get()
            ->each(fn (User $owner) => Mail::to($owner->email)->queue(
                new PayoutDestinationChanged($owner, $by, $organization, $destination, $previously, $at, $zone),
            ));
    }

    /**
     * The organization this request is about, checked against membership.
     *
     * Money is a permission rather than a screen everywhere else in this
     * console; here it is the whole screen, so a member without it is refused
     * outright rather than served an emptier version.
     */
    private function organization(Request $request): Organization
    {
        $memberships = $request->user()->organizations()->get();

        $asked = $request->header('X-Organization');

        $organization = $asked
            ? $memberships->firstWhere('id', $asked)
            : $memberships->first();

        abort_unless($organization !== null, 403, 'No organization.');

        abort_unless(
            $request->user()->hasPermissionIn($organization->id, Permission::MoneyView),
            403,
            'You cannot see the money for this organization.',
        );

        return $organization;
    }

    private function currency(Organization $organization): string
    {
        return (string) (DB::table('events')
            ->where('organization_id', $organization->id)
            ->select('currency', DB::raw('count(*) as n'))
            ->groupBy('currency')
            ->orderByDesc('n')
            ->value('currency') ?? 'CAD');
    }

    /**
     * The zone most of the organization's nights are in.
     *
     * An organization has no zone of its own. Its events each do, and the one
     * most of them share is where its people are — the zone to write a time in
     * for an owner who has not picked one.
     */
    private function zone(Organization $organization): string
    {
        return (string) (DB::table('events')
            ->where('organization_id', $organization->id)
            ->select('timezone', DB::raw('count(*) as n'))
            ->groupBy('timezone')
            ->orderByDesc('n')
            ->value('timezone') ?? config('app.timezone'));
    }
}
