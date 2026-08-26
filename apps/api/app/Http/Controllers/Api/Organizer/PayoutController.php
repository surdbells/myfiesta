<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Models\SensitiveDataAccess;
use App\Models\Settlement;
use App\Services\Audit\Auditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
 * themselves as paid.
 */
class PayoutController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

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
        ]);
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
     */
    public function update(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

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
         */
        if ($detail->isDirty(['account_number', 'interac_email', 'bank_code', 'transit_number'])) {
            $detail->verified_at = null;
        }

        $detail->save();

        // Money moving, and the instructions for where it moves to, are the
        // two things most worth being able to attribute afterwards.
        $this->auditor->record('payout_details.updated', $organization, $request->user(), $organization->id, [
            'rail' => $detail->rail,
            'last_four' => $detail->account_last_four,
        ]);

        SensitiveDataAccess::record(
            $request->user(),
            OrganizationPayoutDetail::class,
            $detail->id,
            'write',
            $request->ip(),
        );

        return response()->json($this->destination($organization));
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
}
