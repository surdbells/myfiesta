<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\CopyAdjustments;
use App\Models\Event;
use App\Models\EventTemplate;
use App\Models\Organization;
use App\Services\Events\EventTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * An organization's event templates: kept from an event, listed, used to
 * start the next one, and deleted (EventTemplates says what one is).
 *
 * Whoever may create events may do all four. A template is a way of making
 * events, and keeping one changes nothing anybody can buy.
 *
 * Another organization's template is as missing as one that never existed:
 * its existence, and what it is called, are nobody else's business.
 */
class EventTemplateController extends Controller
{
    public function __construct(private readonly EventTemplates $templates) {}

    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $templates = EventTemplate::query()
            ->where('organization_id', $organization->id)
            ->with('creator:id,name')
            ->orderBy('name')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $templates->map(fn (EventTemplate $template) => $this->present($template))->values(),
        ]);
    }

    /** Keep an event as a template. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_event_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:80'],
        ]);

        $event = Event::query()->findOrFail($data['from_event_id']);

        $this->authorize('viewInConsole', $event);
        $this->authorize('create', [Event::class, $event->organization_id]);

        // Kept from a night myFiesta took off sale, the template would start
        // the same night again with none of the takedown on it.
        if ($event->taken_down_at !== null) {
            return response()->json([
                'message' => 'myFiesta has taken this event off sale, so it cannot be kept as a template until that is lifted.',
            ], 422);
        }

        $name = trim(preg_replace('/\s+/u', ' ', $data['name']) ?? '');

        if (mb_strlen($name) < 2) {
            throw ValidationException::withMessages(['name' => 'Give the template a name of two letters or more.']);
        }

        $taken = ValidationException::withMessages([
            'name' => "You already have a template called “{$name}”. Give this one another name, or delete that one first.",
        ]);

        try {
            $template = DB::transaction(function () use ($event, $name, $request, $taken) {
                // The organization held while it counts and saves, so two
                // people keeping templates at once cannot both be the 50th.
                Organization::query()->whereKey($event->organization_id)->lockForUpdate()->first();

                $kept = EventTemplate::query()->where('organization_id', $event->organization_id);

                if ((clone $kept)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
                    throw $taken;
                }

                if ($kept->count() >= EventTemplates::MAX_PER_ORGANIZATION) {
                    throw ValidationException::withMessages([
                        'name' => 'You have '.EventTemplates::MAX_PER_ORGANIZATION.' templates already. Delete one you no longer use to keep another.',
                    ]);
                }

                return $this->templates->save($event, $name, $request->user());
            });
        } catch (UniqueConstraintViolationException) {
            // The index has the last word on names; the check above is the
            // kinder way of saying the same thing.
            throw $taken;
        }

        return response()->json(['data' => $this->present($template->load('creator:id,name'))], 201);
    }

    /** A new draft event from a template. */
    public function createEvent(Request $request, string $template): JsonResponse
    {
        $found = $this->owned($request, $template);

        // Kept before the takedown, the template would start the same night
        // again with none of it on: refused for as long as the night itself
        // cannot be copied (EventCopyController::duplicate).
        if ($found->sourceEvent?->taken_down_at !== null) {
            return response()->json([
                'message' => 'myFiesta has taken the event this template was kept from off sale, so it cannot be used until that is lifted.',
            ], 422);
        }

        [$startsAt, $options] = CopyAdjustments::read(
            $request,
            array_map(fn (array $type) => (string) $type['key'], $found->payload['ticket_types'] ?? []),
            null,
            'this template',
        );

        $event = $this->templates->createEvent($found, $startsAt, $options, $request->user());

        return response()->json(EventCopyController::made($event, $request), 201);
    }

    public function destroy(Request $request, string $template): Response
    {
        $this->templates->delete($this->owned($request, $template));

        return response()->noContent();
    }

    // --- lookups ------------------------------------------------------------------

    /** The organization the console is working in, when this person may make its events. */
    private function organization(Request $request): Organization
    {
        $memberships = $request->user()->organizations()->get();
        $asked = $request->header('X-Organization');

        $organization = $asked ? $memberships->firstWhere('id', $asked) : $memberships->first();

        abort_if($organization === null, 403, 'No organization.');

        if (! $request->user()->hasPermissionIn($organization->id, Permission::EventsCreate)) {
            throw new AccessDeniedHttpException('Your role cannot create events, so it cannot use templates.');
        }

        return $organization;
    }

    /** One of the organizations this person works for, and may make events for. */
    private function owned(Request $request, string $id): EventTemplate
    {
        $template = EventTemplate::query()
            ->whereKey($id)
            ->whereIn('organization_id', $request->user()->organizations()->pluck('organizations.id'))
            ->firstOrFail();

        $this->authorize('create', [Event::class, $template->organization_id]);

        return $template;
    }

    /** @return array<string, mixed> */
    private function present(EventTemplate $template): array
    {
        $payload = $template->payload;
        $event = $payload['event'] ?? [];
        $currency = (string) ($event['currency'] ?? 'CAD');
        $renditions = $payload['banner']['renditions'] ?? [];
        $poster = $renditions['display'] ?? $template->banner_path;
        $from = $payload['starts_at'] ?? null;
        $to = $payload['ends_at'] ?? null;

        return [
            'id' => $template->id,
            'name' => $template->name,
            'title' => (string) ($event['title'] ?? ''),
            'description' => $event['description'] ?? null,
            'currency' => $currency,
            'timezone' => (string) ($event['timezone'] ?? 'UTC'),
            'city' => (string) ($event['city'] ?? ''),
            // When the night it was kept from started: the console offers the
            // same time of day for the next one.
            'original_starts_at' => $from === null ? null : CarbonImmutable::parse($from)->toIso8601String(),
            'length_minutes' => $from === null || $to === null
                ? null
                : (int) round(CarbonImmutable::parse($from)->diffInMinutes(CarbonImmutable::parse($to))),
            'poster_url' => $poster === null ? null : Storage::disk('public')->url($poster),
            'ticket_types' => array_map(fn (array $type) => [
                'id' => (string) $type['key'],
                'name' => (string) $type['name'],
                'price' => ['amount' => (int) $type['price_amount'], 'currency' => $currency],
                'quantity_available' => $type['quantity_available'] ?? null,
            ], $payload['ticket_types'] ?? []),
            'add_ons' => count($payload['add_ons'] ?? []),
            'questions' => count($payload['questions'] ?? []),
            'reminders' => count($payload['reminders'] ?? []),
            'created_by' => $template->creator?->name,
            'created_at' => $template->created_at->toIso8601String(),
        ];
    }
}
