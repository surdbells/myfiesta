<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Code;
use App\Models\Event;
use App\Models\Organization;
use App\Services\Audit\Auditor;
use App\Support\Paging;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Discount and promoter codes.
 *
 * One object covers both, because in nightlife the discount code *is* the
 * promoter's attribution — it is how they prove they drove the sale. A code
 * may discount, attribute, or do both.
 */
class CodeController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Every code in the organization, across its events.
     *
     * Codes lived only inside each event, so "which codes do we have out
     * there" meant opening every event in turn — and a promoter programme
     * spanning a season had no single place to be looked at.
     *
     * Filter by one event, by codes for all events, or by a search over the
     * code, its label and the promoter's name. Batch codes are left out, as
     * on the event screen: a thousand one-off codes are one batch.
     */
    public function all(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $filters = $request->validate([
            'event_id' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:64'],
        ]);

        $codes = Code::query()
            ->where('organization_id', $organization->id)
            ->whereNull('batch_id')
            ->when(($filters['event_id'] ?? null) === 'all-events', fn ($query) => $query->whereNull('event_id'))
            ->when(
                filled($filters['event_id'] ?? null) && $filters['event_id'] !== 'all-events',
                fn ($query) => $query->where('event_id', $filters['event_id']),
            )
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q'])).'%';

                $query->where(fn ($inner) => $inner
                    ->where('code', 'ilike', $like)
                    ->orWhere('label', 'ilike', $like)
                    ->orWhere('promoter_name', 'ilike', $like));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(Paging::perPage($request, 50));

        $codes->getCollection()->load(['event:id,title,starts_at,timezone,currency,status', 'ticketTypes:id,name', 'unlocks:id,name']);
        $sales = $this->salesFor($codes->getCollection()->pluck('id')->all());

        return response()->json([
            'data' => $codes->getCollection()
                ->map(fn (Code $code) => $this->present($code, $code->event, $sales[$code->id] ?? []) + [
                    'event' => $code->event ? [
                        'id' => $code->event->id,
                        'title' => $code->event->title,
                        'starts_at' => $code->event->starts_at,
                        'timezone' => $code->event->timezone,
                        'status' => $code->event->status,
                    ] : null,
                ])
                ->values(),
            'meta' => Paging::meta($codes),
        ]);
    }

    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageCodes', $event);

        // Paged. Organization-wide codes are listed on every event, and a
        // promoter programme mints one per promoter per season — the list
        // grows with every year the organization runs, not with this event.
        $codes = Code::query()
            ->where('organization_id', $event->organization_id)
            ->where(fn ($q) => $q->whereNull('event_id')->orWhere('event_id', $event->id))
            // Single-use batch codes are listed as their batch, not one by one.
            ->whereNull('batch_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(Paging::perPage($request, 50));

        $codes->getCollection()->load(['ticketTypes:id,name', 'unlocks:id,name']);
        $sales = $this->salesFor($codes->getCollection()->pluck('id')->all());

        return response()->json([
            'data' => $codes->getCollection()
                ->map(fn (Code $code) => $this->present($code, $event, $sales[$code->id] ?? []))
                ->values(),
            'meta' => Paging::meta($codes),
        ]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageCodes', $event);

        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:64', 'alpha_dash',
                // Unique within the organization, case-insensitively — CODE and
                // code being different codes would be a support ticket a week.
                // Compared in upper case: codes are stored that way, and the
                // plain unique rule let "same" past to fail as a 500 on the
                // database index.
                $this->uniqueWithin($event, 'code'),
            ],
            'label' => ['nullable', 'string', 'max:120'],
            'discount_type' => ['nullable', 'in:percentage,fixed', 'required_with:discount_value'],
            // Percentage in basis points, fixed in minor units. Both integers,
            // because a rate that multiplies money must not be a float.
            //
            // Required alongside the type. A percentage code with no percentage
            // used to be accepted — the check constraint meant to stop it was
            // defeated by NULL comparing to unknown rather than false — and then
            // returned a 500 to every buyer who typed it.
            'discount_value' => ['nullable', 'integer', 'min:1', 'required_with:discount_type'],
            'promoter_name' => ['nullable', 'string', 'max:120'],
            'ref_slug' => ['nullable', 'string', 'max:64', 'alpha_dash', $this->uniqueWithin($event, 'ref_slug')],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'max_per_customer' => ['nullable', 'integer', 'min:1'],
            'min_quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'ticket_type_ids' => ['nullable', 'array'],
            'ticket_type_ids.*' => ['uuid', Rule::exists('ticket_types', 'id')->where('event_id', $event->id)],
            // Presale: the tiers this code opens.
            'unlock_ticket_type_ids' => ['nullable', 'array'],
            'unlock_ticket_type_ids.*' => ['uuid', Rule::exists('ticket_types', 'id')->where('event_id', $event->id)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'event_scoped' => ['nullable', 'boolean'],
        ], [
            // The defaults here read as if somebody else claimed a username.
            'ticket_type_ids.*.exists' => 'One of those tickets is not on this event.',
            'unlock_ticket_type_ids.*.exists' => 'One of those tickets is not on this event.',
            'discount_value.required_with' => 'Say how much comes off.',
            'discount_type.required_with' => 'Say whether that is a percentage or an amount.',
        ]);

        // A code has to do something. The database enforces this too, but a
        // constraint violation is not a sentence anyone wants to read.
        if (blank($data['discount_type'] ?? null) && blank($data['ref_slug'] ?? null) && empty($data['unlock_ticket_type_ids'])) {
            return response()->json([
                'message' => 'A code needs to take money off, credit a promoter or unlock tickets — otherwise it does nothing.',
            ], 422);
        }

        if (! empty($data['unlock_ticket_type_ids']) && ! ($data['event_scoped'] ?? true)) {
            return response()->json([
                'message' => 'A code for all your events cannot unlock one event’s tickets. Make it for this event only.',
            ], 422);
        }

        if (($data['discount_type'] ?? null) === 'percentage' && $data['discount_value'] > 10000) {
            return response()->json([
                'message' => 'A percentage cannot exceed 100%.',
            ], 422);
        }

        if ($refusal = $this->aimRefusal(
            $data['ticket_type_ids'] ?? [],
            (bool) ($data['event_scoped'] ?? true),
            filled($data['discount_type'] ?? null),
            $data['min_quantity'] ?? null,
        )) {
            return response()->json(['message' => $refusal], 422);
        }

        $code = Code::create([
            'organization_id' => $event->organization_id,
            // Scoped to this event unless explicitly made organization-wide.
            'event_id' => ($data['event_scoped'] ?? true) ? $event->id : null,
            'code' => $data['code'],
            'label' => $data['label'] ?? null,
            'discount_type' => $data['discount_type'] ?? null,
            'discount_value' => $data['discount_value'] ?? null,
            // Fixed amounts carry a currency and cannot cross into an event
            // priced in another; percentages travel freely and must not.
            'discount_currency' => ($data['discount_type'] ?? null) === 'fixed'
                ? $event->currency
                : null,
            'ref_slug' => $data['ref_slug'] ?? null,
            'promoter_name' => $data['promoter_name'] ?? null,
            'max_redemptions' => $data['max_redemptions'] ?? null,
            'max_per_customer' => $data['max_per_customer'] ?? null,
            'min_quantity' => $data['min_quantity'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => true,
            'unlocks_tickets' => ! empty($data['unlock_ticket_type_ids']),
        ]);

        $code->ticketTypes()->sync($data['ticket_type_ids'] ?? []);
        $code->unlocks()->sync($data['unlock_ticket_type_ids'] ?? []);

        // Refreshed so the response carries what the database actually holds.
        // redemption_count defaults to 0 there and is absent from the model we
        // just built, so without this a new code reports a null usage count and
        // a reload silently changes it to 0.
        return response()->json($this->present($code->refresh(), $event, $this->salesFor([$code->id])[$code->id] ?? []), 201);
    }

    /**
     * Change a code that is already out there.
     *
     * Before this the only edit was delete-and-recreate, which throws away the
     * redemption count and the attribution attached to it — so a typo in a
     * promoter's name cost the record of everything they had sold.
     *
     * Two things deliberately cannot change. The code itself is printed on
     * posters and typed from screenshots, so renaming it would silently break
     * every place it has already been shared; a new code is the honest way to
     * do that. And a fixed amount's currency follows the event it was made
     * for, because a discount in dollars cannot come off a price in naira.
     */
    public function update(Request $request, Event $event, Code $code): JsonResponse
    {
        $this->authorize('manageCodes', $event);

        abort_unless($code->organization_id === $event->organization_id, 404);

        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'discount_type' => ['sometimes', 'nullable', 'in:percentage,fixed'],
            'discount_value' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'promoter_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'ref_slug' => ['sometimes', 'nullable', 'string', 'max:64', 'alpha_dash', $this->uniqueWithin($event, 'ref_slug', $code)],
            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_per_customer' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'min_quantity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'ticket_type_ids' => ['sometimes', 'array'],
            'ticket_type_ids.*' => ['uuid', Rule::exists('ticket_types', 'id')->where('event_id', $event->id)],
            'unlock_ticket_type_ids' => ['sometimes', 'array'],
            'unlock_ticket_type_ids.*' => ['uuid', Rule::exists('ticket_types', 'id')->where('event_id', $event->id)],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Merged against what is stored, so the invariants below are checked
        // on the code as it will be — not on whichever fields happened to be
        // sent. A request changing only the end date must not be able to
        // leave a percentage code with no percentage.
        $next = array_merge([
            'discount_type' => $code->discount_type,
            'discount_value' => $code->discount_value,
            'ref_slug' => $code->ref_slug,
            'starts_at' => $code->starts_at,
            'ends_at' => $code->ends_at,
        ], $data);

        $unlocksNext = array_key_exists('unlock_ticket_type_ids', $data)
            ? ! empty($data['unlock_ticket_type_ids'])
            : $code->unlocks_tickets;

        if (blank($next['discount_type']) && blank($next['ref_slug']) && ! $unlocksNext) {
            return response()->json([
                'message' => 'A code needs to take money off, credit a promoter or unlock tickets — otherwise it does nothing.',
            ], 422);
        }

        if (! empty($data['unlock_ticket_type_ids']) && $code->event_id === null) {
            return response()->json([
                'message' => 'A code for all your events cannot unlock one event’s tickets.',
            ], 422);
        }

        if (filled($next['discount_type']) && blank($next['discount_value'])) {
            return response()->json(['message' => 'Say how much comes off.'], 422);
        }

        if ($next['discount_type'] === 'percentage' && (int) $next['discount_value'] > 10000) {
            return response()->json(['message' => 'A percentage cannot exceed 100%.'], 422);
        }

        /*
         * Not below what it has already been used.
         *
         * The database used to refuse this with a constraint and the console
         * got a 500. Lowering a limit to stop further use is reasonable; the
         * way to stop it entirely is to turn the code off.
         */
        if (array_key_exists('max_redemptions', $data) && $data['max_redemptions'] !== null
            && $data['max_redemptions'] < $code->redemption_count) {
            return response()->json([
                'message' => "It has already been used {$code->redemption_count} times, so the limit cannot be lower than that. Turn it off to stop it being used.",
            ], 422);
        }

        if ($refusal = $this->aimRefusal(
            $data['ticket_type_ids'] ?? $code->ticketTypes()->pluck('ticket_types.id')->all(),
            $code->event_id !== null,
            filled($next['discount_type']),
            array_key_exists('min_quantity', $data) ? $data['min_quantity'] : $code->min_quantity,
        )) {
            return response()->json(['message' => $refusal], 422);
        }

        if ($next['starts_at'] && $next['ends_at']
            && Carbon::parse($next['ends_at'])->lessThanOrEqualTo(Carbon::parse($next['starts_at']))) {
            return response()->json([
                'message' => 'The end has to come after the start.',
            ], 422);
        }

        // A code that has become a fixed amount takes the event's currency;
        // one that has become a percentage carries none, because percentages
        // travel between currencies and amounts do not.
        if (array_key_exists('discount_type', $data)) {
            $code->discount_currency = $data['discount_type'] === 'fixed' ? $event->currency : null;
        }

        $code->fill(collect($data)->except(['ticket_type_ids', 'unlock_ticket_type_ids'])->all());
        $code->unlocks_tickets = $unlocksNext;
        $code->save();

        if (array_key_exists('ticket_type_ids', $data)) {
            $code->ticketTypes()->sync($data['ticket_type_ids']);
        }

        if (array_key_exists('unlock_ticket_type_ids', $data)) {
            $code->unlocks()->sync($data['unlock_ticket_type_ids']);
        }

        $this->auditor->record(
            'code.updated',
            $event,
            $request->user(),
            metadata: ['code' => $code->code, 'changed' => array_keys($data)],
        );

        return response()->json($this->present($code->refresh(), $event, $this->salesFor([$code->id])[$code->id] ?? []));
    }

    /**
     * Turn a code off.
     *
     * Deactivated rather than deleted: orders point at it, and removing it
     * would make those orders impossible to explain — and lose the attribution
     * a promoter is owed for.
     */
    public function destroy(Request $request, Event $event, Code $code): JsonResponse
    {
        $this->authorize('manageCodes', $event);

        abort_unless($code->organization_id === $event->organization_id, 404);

        $code->update(['is_active' => false]);

        return response()->json([
            'message' => 'Turned off. Orders already placed with it keep their discount.',
        ]);
    }

    /**
     * A rule that a code name or tracking slug is free in this organization,
     * compared case-insensitively.
     */
    private function uniqueWithin(Event $event, string $column, ?Code $except = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($event, $column, $except) {
            if (blank($value)) {
                return;
            }

            $taken = Code::query()
                ->where('organization_id', $event->organization_id)
                ->whereRaw("upper({$column}) = ?", [mb_strtoupper(trim((string) $value))])
                ->when($except, fn ($q) => $q->whereKeyNot($except->id))
                ->exists();

            if ($taken) {
                $fail($column === 'code'
                    ? 'You already have a code with that name.'
                    : 'Another code already tracks that link. Each link needs its own slug, or sales could be credited to the wrong promoter.');
            }
        };
    }

    /**
     * Whether a code's targeting makes sense, as a sentence if it does not.
     *
     * @param  list<string>  $ticketTypeIds
     */
    private function aimRefusal(array $ticketTypeIds, bool $eventScoped, bool $discounts, ?int $minQuantity): ?string
    {
        if ($ticketTypeIds !== [] && ! $eventScoped) {
            return 'A code for all your events cannot be limited to one event’s tickets. Make it for this event only, or leave every ticket included.';
        }

        if (($ticketTypeIds !== [] || $minQuantity !== null) && ! $discounts) {
            return 'Ticket and quantity conditions only apply to a discount. A tracking-only code credits every sale.';
        }

        return null;
    }

    /**
     * What each code has sold: paid orders, tickets, what they came to after
     * the discount, and what the discount gave away — per currency, because an
     * organization-wide code can sell in dollars and naira both.
     *
     * The count alone ("12 used") was all an organizer had, and the question a
     * promoter's code exists to answer is how much they sold.
     *
     * @param  list<string>  $codeIds
     * @return array<string, list<array{currency: string, orders: int, tickets: int, revenue: int, discount: int}>>
     */
    private function salesFor(array $codeIds): array
    {
        if ($codeIds === []) {
            return [];
        }

        $tickets = DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->whereIn('orders.code_id', $codeIds)
            ->whereIn('orders.status', Code::PAID_STATUSES)
            ->groupBy('orders.code_id', 'orders.currency')
            ->selectRaw('orders.code_id, orders.currency, sum(order_lines.quantity) as tickets')
            ->get()
            ->keyBy(fn ($row) => $row->code_id.'|'.$row->currency);

        return DB::table('orders')
            ->whereIn('code_id', $codeIds)
            ->whereIn('status', Code::PAID_STATUSES)
            ->groupBy('code_id', 'currency')
            ->orderBy('currency')
            ->selectRaw('code_id, currency, count(*) as orders, sum(subtotal_amount - discount_amount) as revenue, sum(discount_amount) as discount')
            ->get()
            ->groupBy('code_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => [
                'currency' => $row->currency,
                'orders' => (int) $row->orders,
                'tickets' => (int) ($tickets[$row->code_id.'|'.$row->currency]->tickets ?? 0),
                'revenue' => (int) $row->revenue,
                'discount' => (int) $row->discount,
            ])->values()->all())
            ->all();
    }

    /**
     * @param  Event|null  $event  The event the code is being looked at from. Null on the
     *                             organization-wide list, for a code made for every event.
     */
    private function present(Code $code, ?Event $event, array $sales = []): array
    {
        return [
            'id' => $code->id,
            'code' => $code->code,
            'label' => $code->label,
            'discount_type' => $code->discount_type,
            'discount_value' => $code->discount_value,
            'discount_currency' => $code->discount_currency,
            'ref_slug' => $code->ref_slug,
            'promoter_name' => $code->promoter_name,
            'redemption_count' => $code->redemption_count,
            'max_redemptions' => $code->max_redemptions,
            'max_per_customer' => $code->max_per_customer,
            'min_quantity' => $code->min_quantity,
            // Empty means every ticket type.
            'ticket_types' => $code->ticketTypes->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
            // Presale: the tiers it opens. Empty for a code that unlocks nothing.
            'unlocks' => $code->unlocks->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
            'sales' => $sales,
            // The window is enforced at checkout and was absent from this
            // response, so the console could neither show it nor edit it —
            // a code could only ever be given a window it could not display.
            'starts_at' => $code->starts_at,
            'ends_at' => $code->ends_at,
            'is_active' => $code->is_active,
            'event_scoped' => $code->event_id !== null,
            // Whether it would actually work right now, which is the question
            // an organizer is really asking when they look at this list.
            'usable' => $code->is_active
                && ($code->max_redemptions === null || $code->redemption_count < $code->max_redemptions)
                && ($code->starts_at === null || $code->starts_at->isPast())
                && ($code->ends_at === null || $code->ends_at->isFuture())
                && ($event === null || $code->appliesTo($event)),
        ];
    }

    private function organization(Request $request): Organization
    {
        $memberships = $request->user()->organizations()->get();

        $asked = $request->header('X-Organization');

        $organization = $asked
            ? $memberships->firstWhere('id', $asked)
            : $memberships->first();

        abort_unless($organization !== null, 403, 'No organization.');

        abort_unless(
            $request->user()->hasPermissionIn($organization->id, Permission::CodesManage),
            403,
            'You cannot manage codes for this organization.',
        );

        return $organization;
    }
}
