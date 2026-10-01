<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSurvey;
use App\Models\Organization;
use App\Models\SurveyTemplate;
use App\Services\Audit\Auditor;
use App\Services\Surveys\EventSurveys;
use App\Services\Surveys\SurveyResults;
use App\Services\Surveys\SurveySender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The survey after each night, from the organizer's side: whether it goes,
 * which questions, how long after, and what people said.
 *
 * A survey is an email to the people who came, so it belongs to whoever may
 * write to them (messages.send): owners, managers and marketing.
 */
class SurveyController extends Controller
{
    /** The longest an organizer can hold a survey back: a week. */
    public const MAX_DELAY_HOURS = 168;

    public function __construct(
        private readonly EventSurveys $surveys,
        private readonly SurveyResults $results,
        private readonly SurveySender $sender,
        private readonly Auditor $auditor,
    ) {}

    /** The /surveys screen: the organization's switch and its recent nights. */
    public function overview(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $recent = EventSurvey::query()
            ->whereHas('event', fn ($query) => $query->where('organization_id', $organization->id))
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->limit(20)
            ->with('event:id,title,starts_at,timezone')
            ->get();

        return response()->json([
            'surveys_enabled' => (bool) $organization->surveys_enabled,
            // Finished nights the next hourly run would ask about with surveys
            // on: switching them back on emails those people within the hour.
            'nights_due_now' => $this->sender->dueCountFor($organization),
            'minimum' => SurveyResults::MINIMUM,
            'recent' => $recent->map(function (EventSurvey $survey) {
                $summary = $this->results->summarise($survey);

                return [
                    'event_id' => $survey->event_id,
                    'title' => $survey->event->title,
                    'starts_at' => $survey->event->starts_at->toIso8601String(),
                    'timezone' => $survey->event->timezone,
                    'sent_at' => $summary['sent_at'],
                    'invited' => $summary['invited'],
                    'responded' => $summary['responded'],
                    'response_rate' => $summary['response_rate'],
                    'nps' => $summary['nps']['score'] ?? null,
                ];
            })->values(),
        ]);
    }

    /** Surveys on or off for every night of the organization. */
    public function settings(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $data = $request->validate(['surveys_enabled' => ['required', 'boolean']]);

        $organization->forceFill(['surveys_enabled' => $data['surveys_enabled']])->save();

        $this->auditor->record($data['surveys_enabled'] ? 'surveys.switched_on' : 'surveys.switched_off', $organization, $request->user(), $organization->id);

        $due = $this->sender->dueCountFor($organization);

        return response()->json([
            'surveys_enabled' => (bool) $organization->surveys_enabled,
            'nights_due_now' => $due,
            'message' => match (true) {
                ! $data['surveys_enabled'] => 'Surveys are off. No event is asked about until you switch them back on.',
                $due === 1 => 'Surveys are on. 1 event that has finished is asked about within the hour.',
                $due > 1 => "Surveys are on. {$due} events that have finished are asked about within the hour.",
                default => 'Surveys are on. Each event is asked about at the time set on its Feedback tab.',
            },
        ]);
    }

    /** One night's survey, as the Feedback tab shows it. */
    public function show(Request $request, Event $event): JsonResponse
    {
        $this->authorize('message', $event);

        return response()->json($this->present($event));
    }

    /** Switch one night's survey, choose its questions, or change its delay. */
    public function update(Request $request, Event $event): JsonResponse
    {
        $this->authorize('message', $event);

        $shortest = $this->surveys->shortestDelayHours();

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'template_id' => ['sometimes', 'nullable', 'uuid'],
            'send_delay_hours' => ['sometimes', 'integer', 'min:'.$shortest, 'max:'.self::MAX_DELAY_HOURS],
        ], [
            'send_delay_hours.min' => "Send it at least {$shortest} hours after the event ends: the door's last scans can take that long to come in.",
            'send_delay_hours.max' => 'Send it within a week of the event, while people remember it.',
        ]);

        $existing = $this->surveys->find($event);

        if ($existing?->sent_at !== null) {
            return response()->json([
                'message' => 'This survey has already gone out, so it cannot be changed.',
            ], 409);
        }

        $changes = [];

        if (array_key_exists('template_id', $data)) {
            $template = $data['template_id'] === null ? null : $this->choosable($event->organization_id, $data['template_id']);

            $changes['template_id'] = $template?->id;
            $changes['questions'] = ($template ?? SurveyTemplate::platformDefault())->questions ?? [];
        }

        if (array_key_exists('enabled', $data)) {
            $changes['enabled'] = $data['enabled'];
        }

        if (array_key_exists('send_delay_hours', $data)) {
            $changes['send_delay_hours'] = $data['send_delay_hours'];
        }

        if ($changes !== []) {
            $row = $this->surveys->ensure($event);

            // Under the lock SurveySender::start takes to send it. The check
            // above read without one, and an hourly run that sent it since
            // has fixed its questions for good: writing over them would ask
            // people one survey and read their answers against another.
            $survey = DB::transaction(function () use ($row, $changes) {
                $survey = EventSurvey::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();

                if ($survey->sent_at !== null) {
                    return null;
                }

                $survey->forceFill($changes)->save();

                return $survey;
            });

            if ($survey === null) {
                return response()->json([
                    'message' => 'This survey has already gone out, so it cannot be changed.',
                ], 409);
            }

            $this->auditor->record('survey.updated', $survey, $request->user(), $event->organization_id, metadata: array_diff_key($changes, ['questions' => true]));
        }

        return response()->json($this->present($event->fresh()));
    }

    /** What people said about one night. */
    public function results(Request $request, Event $event): JsonResponse
    {
        $this->authorize('message', $event);

        return response()->json($this->results->for($event, $this->surveys->find($event)));
    }

    /**
     * Send it now rather than when the hourly run gets to it: confirmed in
     * the console first, because an email cannot be called back.
     */
    public function send(Request $request, Event $event): JsonResponse
    {
        $this->authorize('message', $event);

        $survey = $this->surveys->find($event);

        if ($survey?->sent_at !== null) {
            return response()->json(['message' => 'This survey has already gone out. Each person is only asked once.'], 409);
        }

        if (! $this->surveys->isOver($event)) {
            return response()->json(['message' => 'The night is not over yet. Send the survey once it has ended.'], 422);
        }

        $stopped = $this->surveys->stoppedBecause($event, $survey);

        if ($stopped !== null) {
            return response()->json(['message' => $stopped], 422);
        }

        // Who is asked is the door's answer, and a door phone that lost its
        // signal sends its scans hours later. Sent before they are in, it
        // would ask the people who did not come, or miss some who did, and
        // it can only go once.
        if (! $this->surveys->counted($event)) {
            $from = $this->surveys->finalCountAt($event)->timezone($event->timezone)->format('D j M, g:ia');

            return response()->json([
                'message' => "The door's last scans are still coming in, so it cannot go yet. You can send it from {$from}.",
            ], 422);
        }

        $asked = $this->sender->sendNow($event, $request->user());

        if ($asked === null) {
            return response()->json(['message' => 'This survey has already gone out. Each person is only asked once.'], 409);
        }

        $this->auditor->record('survey.sent', $this->surveys->find($event), $request->user(), $event->organization_id, metadata: ['invited' => $asked]);

        return response()->json([
            'message' => match ($asked) {
                0 => 'Sent to nobody: nobody who came takes emails like this.',
                1 => 'Sent to 1 person.',
                default => "Sent to {$asked} people.",
            },
            'invited' => $asked,
            'survey' => $this->present($event->fresh()),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Event $event): array
    {
        $survey = $this->surveys->find($event);
        $template = $survey?->sent_at !== null ? $survey->template : $this->surveys->template($survey);
        $stopped = $this->surveys->stoppedBecause($event, $survey);

        return [
            'enabled' => $survey->enabled ?? true,
            'organization_enabled' => (bool) ($event->organization->surveys_enabled ?? true),
            // The organization's own survey it sends, or null for myFiesta's:
            // one that was chosen and since removed sends myFiesta's too.
            'template_id' => $template !== null && ! $template->isPlatform() ? $template->id : null,
            'template' => $template ? ['id' => $template->id, 'name' => $template->name, 'platform' => $template->isPlatform()] : null,
            'send_delay_hours' => $this->surveys->delayHours($survey),
            // The night's own zone, so the console says when in the time people were there.
            'timezone' => $event->timezone,
            'ends_at' => $this->surveys->endsAt($event)->toIso8601String(),
            // The earliest it can go: once the door's last scans are in.
            'final_count_at' => $this->surveys->finalCountAt($event)->toIso8601String(),
            'sends_at' => $this->surveys->sendsAt($event, $survey)->toIso8601String(),
            'sent_at' => $survey?->sent_at?->toIso8601String(),
            'stopped_because' => $stopped,
            // Switched on, the next hourly run sends it: what turning it back
            // on does straight away.
            'due_now' => $this->surveys->dueNow($event, $survey),
            'can_send_now' => $survey?->sent_at === null && $stopped === null && $this->surveys->counted($event),
            'questions' => $this->surveys->questions($survey),
            // What can be chosen instead: myFiesta's own, then the
            // organization's, archived ones left out.
            'templates' => SurveyTemplate::query()
                ->whereNull('archived_at')
                ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $event->organization_id))
                ->orderByRaw('organization_id is not null')
                ->orderBy('name')
                ->get()
                ->map(fn (SurveyTemplate $choice) => ['id' => $choice->id, 'name' => $choice->name, 'platform' => $choice->isPlatform()])
                ->values(),
        ];
    }

    /** myFiesta's own, or one of this organization's, still in use. */
    private function choosable(string $organizationId, string $id): SurveyTemplate
    {
        $template = SurveyTemplate::query()
            ->whereKey($id)
            ->whereNull('archived_at')
            ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->first();

        abort_if($template === null, 422, 'Choose one of your surveys, or myFiesta\'s.');

        return $template;
    }

    private function organization(Request $request): Organization
    {
        $memberships = $request->user()->organizations()->get();
        $asked = $request->header('X-Organization');

        $organization = $asked ? $memberships->firstWhere('id', $asked) : $memberships->first();

        abort_if($organization === null, 403, 'No organization.');

        if (! $request->user()->hasPermissionIn($organization->id, Permission::MessagesSend)) {
            throw new AccessDeniedHttpException('Your role cannot run surveys.');
        }

        return $organization;
    }
}
