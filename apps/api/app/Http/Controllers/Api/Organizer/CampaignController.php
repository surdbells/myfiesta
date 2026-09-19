<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Services\Audit\Auditor;
use App\Services\Campaigns\Audiences;
use App\Services\Campaigns\CampaignSender;
use App\Support\Paging;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Writing to people who might come, rather than people who are coming.
 *
 * Anybody who can message an event's ticket holders can run a campaign —
 * marketing staff most of all, since this is their job. Who a campaign may
 * reach is not theirs to decide: the lists and the rules about them are in
 * Audiences, and an organizer chooses a list, never an address.
 */
class CampaignController extends Controller
{
    public function __construct(
        private readonly Audiences $audiences,
        private readonly CampaignSender $sender,
        private readonly Auditor $auditor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $page = Campaign::query()
            ->where('organization_id', $organization->id)
            ->with('event:id,title,slug,starts_at,currency')
            ->orderByDesc('created_at')
            ->paginate(Paging::perPage($request, 20));

        $results = $this->results($page->getCollection()->pluck('ref')->all());

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn (Campaign $campaign) => $this->present($campaign, $results[$campaign->ref] ?? null))
                ->values(),
            'meta' => Paging::meta($page),
            'audiences' => collect(Audiences::LABELS)
                ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label, 'needs_event' => $key === 'abandoned'])
                ->values(),
            // What a campaign can point at: nights still to come and on sale.
            'events' => Event::query()
                ->where('organization_id', $organization->id)
                ->where('status', 'published')
                ->where('kind', 'ticketed')
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->get(['id', 'title', 'starts_at'])
                ->map(fn (Event $event) => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'starts_at' => $event->starts_at?->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    /** How many a list holds, and how many would be written to right now. */
    public function audience(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate($this->audienceRules());
        $event = $this->event($organization, $data['event_id'] ?? null);

        ['all' => $all, 'reachable' => $reachable] = $this->audiences->resolve($organization->id, $data['audience'], $event);

        return response()->json(['all' => count($all), 'reachable' => count($reachable)]);
    }

    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate([...$this->audienceRules(), ...$this->contentRules(), ...$this->whenRules()], $this->messages());
        $event = $this->event($organization, $data['event_id'] ?? null);

        $campaign = Campaign::create([
            'organization_id' => $organization->id,
            'event_id' => $event?->id,
            'audience' => $data['audience'],
            'subject' => trim($data['subject']),
            'body' => trim($data['body']),
            'created_by' => $request->user()->id,
            ...$this->when($data),
        ]);

        return $this->afterSaving($request, $campaign, 201);
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        $organization = $this->owned($request, $campaign);

        if (! $campaign->isOpen()) {
            return response()->json(['message' => 'This one has already gone out, and a sent email cannot be changed.'], 422);
        }

        $data = $request->validate([...$this->audienceRules(), ...$this->contentRules(), ...$this->whenRules()], $this->messages());
        $event = $this->event($organization, $data['event_id'] ?? null);

        $campaign->update([
            'event_id' => $event?->id,
            'audience' => $data['audience'],
            'subject' => trim($data['subject']),
            'body' => trim($data['body']),
            ...$this->when($data),
        ]);

        return $this->afterSaving($request, $campaign, 200);
    }

    public function cancel(Request $request, Campaign $campaign): JsonResponse
    {
        $this->owned($request, $campaign);

        // Conditional, so a cancel that loses a race with the sender is told
        // so, rather than reporting a cancellation of something already sent.
        $cancelled = Campaign::query()
            ->whereKey($campaign->id)
            ->whereIn('status', Campaign::OPEN)
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        if ($cancelled === 0) {
            return response()->json(['message' => 'Too late — this one has already started sending.'], 422);
        }

        return response()->json(['data' => $this->present($campaign->fresh()->load('event'), null)]);
    }

    // --- rules ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function audienceRules(): array
    {
        return [
            'audience' => ['required', Rule::in(Campaign::AUDIENCES)],
            'event_id' => ['nullable', 'uuid', 'required_if:audience,abandoned'],
        ];
    }

    /** @return array<string, mixed> */
    private function contentRules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:4000'],
        ];
    }

    /** @return array<string, mixed> */
    private function whenRules(): array
    {
        return [
            // Saved to finish later, sent now, or sent at a time.
            'send' => ['required', Rule::in(['draft', 'now', 'later'])],
            'scheduled_for' => ['nullable', 'required_if:send,later', 'date', 'after:now', 'before:+90 days'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'subject.required' => 'Give it a subject — it is what people see first.',
            'body.required' => 'There is nothing to send.',
            'event_id.required_if' => 'Choose the event they were buying for.',
            'scheduled_for.after' => 'Pick a time that has not happened yet.',
            'scheduled_for.before' => 'Campaigns can be scheduled up to 90 days ahead.',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function when(array $data): array
    {
        return match ($data['send']) {
            'draft' => ['status' => 'draft', 'scheduled_for' => null],
            'now' => ['status' => 'scheduled', 'scheduled_for' => now()],
            'later' => ['status' => 'scheduled', 'scheduled_for' => CarbonImmutable::parse($data['scheduled_for'])],
        };
    }

    /**
     * Send it now when that was asked for, and refuse an empty list first.
     *
     * Refused rather than sent to nobody, as with event messages: an organizer
     * who thinks it went out and finds out later that it reached no one is
     * worse off than one told now.
     */
    private function afterSaving(Request $request, Campaign $campaign, int $status): JsonResponse
    {
        $campaign->load(['organization', 'event']);

        if ($campaign->status === 'scheduled' && $campaign->scheduled_for->lte(now())) {
            $reach = $this->audiences->resolve($campaign->organization_id, $campaign->audience, $campaign->event);

            if ($reach['reachable'] === []) {
                $campaign->update(['status' => 'draft', 'scheduled_for' => null]);

                return response()->json([
                    'message' => 'Nobody on that list can be written to right now, so nothing was sent. It is saved as a draft.',
                    'data' => $this->present($campaign->fresh()->load('event'), null),
                ], 422);
            }

            $sent = $this->sender->send($campaign);

            return response()->json([
                'message' => $sent === 1 ? 'Sent to 1 person.' : "Sent to {$sent} people.",
                'data' => $this->present($campaign->fresh()->load('event'), null),
            ], $status);
        }

        if ($campaign->status === 'scheduled') {
            $this->auditor->record('campaign.scheduled', $campaign, $request->user(), $campaign->organization_id, metadata: [
                'audience' => $campaign->audience,
                'scheduled_for' => $campaign->scheduled_for->toIso8601String(),
            ]);
        }

        return response()->json(['data' => $this->present($campaign, null)], $status);
    }

    // --- lookups ------------------------------------------------------------------

    private function organization(Request $request): Organization
    {
        $memberships = $request->user()->organizations()->get();
        $asked = $request->header('X-Organization');

        $organization = $asked ? $memberships->firstWhere('id', $asked) : $memberships->first();

        abort_if($organization === null, 403, 'No organization.');

        if (! $request->user()->hasPermissionIn($organization->id, Permission::MessagesSend)) {
            throw new AccessDeniedHttpException('Your role cannot send campaigns.');
        }

        return $organization;
    }

    private function owned(Request $request, Campaign $campaign): Organization
    {
        $organization = $this->organization($request);

        abort_unless($campaign->organization_id === $organization->id, 404);

        return $organization;
    }

    /** One of this organization's nights still on sale, or a validation error. */
    private function event(Organization $organization, ?string $id): ?Event
    {
        if ($id === null) {
            return null;
        }

        $event = Event::query()
            ->whereKey($id)
            ->where('organization_id', $organization->id)
            ->where('status', 'published')
            ->where('kind', 'ticketed')
            ->where('starts_at', '>', now())
            ->first();

        if ($event === null) {
            throw ValidationException::withMessages(['event_id' => 'Choose one of your events that is on sale.']);
        }

        return $event;
    }

    /**
     * What each campaign sold: orders that came in through its link.
     *
     * @param  list<string>  $refs
     * @return array<string, array{orders: int, tickets: int, revenue: int}>
     */
    private function results(array $refs): array
    {
        if ($refs === []) {
            return [];
        }

        $orders = Order::query()
            ->whereIn('ref_slug', $refs)
            ->whereIn('status', ['paid', 'partially_refunded'])
            ->groupBy('ref_slug')
            ->get(['ref_slug', DB::raw('count(*) as orders'), DB::raw('sum(net_revenue_amount) as revenue')])
            ->keyBy('ref_slug');

        // Tickets still standing, so a refund takes its ticket back off the
        // count rather than leaving the campaign credited with it.
        $tickets = Ticket::query()
            ->join('orders', 'orders.id', '=', 'tickets.order_id')
            ->whereIn('orders.ref_slug', $refs)
            ->whereIn('orders.status', ['paid', 'partially_refunded'])
            ->whereIn('tickets.status', ['valid', 'checked_in'])
            ->groupBy('orders.ref_slug')
            ->get(['orders.ref_slug', DB::raw('count(*) as tickets')])
            ->keyBy('ref_slug');

        return collect($orders)->map(fn ($row, string $ref) => [
            'orders' => (int) $row->orders,
            'tickets' => (int) ($tickets[$ref]->tickets ?? 0),
            'revenue' => (int) $row->revenue,
        ])->all();
    }

    /**
     * @param  array{orders: int, tickets: int, revenue: int}|null  $result
     * @return array<string, mixed>
     */
    private function present(Campaign $campaign, ?array $result): array
    {
        $event = $campaign->event;

        return [
            'id' => $campaign->id,
            'audience' => $campaign->audience,
            'event' => $event ? ['id' => $event->id, 'title' => $event->title, 'slug' => $event->slug] : null,
            'subject' => $campaign->subject,
            'body' => $campaign->body,
            'status' => $campaign->status,
            'scheduled_for' => $campaign->scheduled_for?->toIso8601String(),
            'sent_at' => $campaign->sent_at?->toIso8601String(),
            'recipients' => $campaign->recipients,
            'suppressed' => $campaign->suppressed,
            'results' => $campaign->status === 'sent' ? [
                'orders' => $result['orders'] ?? 0,
                'tickets' => $result['tickets'] ?? 0,
                'revenue' => ['amount' => $result['revenue'] ?? 0, 'currency' => $event?->currency],
            ] : null,
            'created_at' => $campaign->created_at?->toIso8601String(),
        ];
    }
}
