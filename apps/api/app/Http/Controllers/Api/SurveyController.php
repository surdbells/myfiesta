<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Services\Surveys\SurveyQuestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The survey somebody was emailed, answered from the link in it.
 *
 * No sign-in, like the ticket link: the token is the whole credential, and
 * it answers with the night and the questions, never with whose link it is.
 * One answer per link, and never edited, so the figures an organizer reads
 * cannot be pushed about by pressing send again.
 */
class SurveyController extends Controller
{
    public function __construct(private readonly SurveyQuestions $questions) {}

    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitation($token);

        return response()->json($this->present($invitation));
    }

    public function answer(Request $request, string $token): JsonResponse
    {
        $invitation = $this->invitation($token);
        $survey = $invitation->survey;

        // Checked against what was sent, so somebody answering on Tuesday a
        // survey sent on Sunday answers what they were asked.
        $answers = $this->questions->answers($survey->questions ?? [], $request->input('answers'));

        $saved = DB::transaction(function () use ($invitation, $survey, $answers) {
            $locked = SurveyInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();

            if ($locked === null || $locked->responded_at !== null) {
                return false;
            }

            SurveyResponse::create([
                'id' => (string) Str::uuid7(),
                'invitation_id' => $locked->id,
                'event_survey_id' => $survey->id,
                'answers' => $answers,
                'created_at' => now(),
            ]);

            $locked->forceFill(['responded_at' => now()])->save();

            return true;
        });

        if (! $saved) {
            return response()->json([
                'message' => 'You have already answered this survey. Thank you — it only takes one answer each.',
            ], 409);
        }

        return response()->json([
            'message' => 'Thank you. '.$survey->event->organization->name.' will read every answer.',
            'survey' => $this->present($invitation->fresh()),
        ], 201);
    }

    private function invitation(string $token): SurveyInvitation
    {
        abort_if(strlen($token) > 64, 404);

        return SurveyInvitation::query()
            ->where('token', $token)
            ->with('survey.event.organization')
            ->firstOr(fn () => abort(404, 'We cannot find that survey. Open the link in the email again.'));
    }

    /** @return array<string, mixed> */
    private function present(SurveyInvitation $invitation): array
    {
        $survey = $invitation->survey;
        $event = $survey->event;

        return [
            'event' => [
                'title' => $event->title,
                'organizer' => $event->organization->name,
                'starts_at' => $event->starts_at->toIso8601String(),
                'timezone' => $event->timezone,
            ],
            'questions' => array_map(fn (array $question) => [
                'id' => $question['id'],
                'type' => $question['type'],
                'label' => $question['label'],
                'options' => $question['options'] ?? [],
                'required' => (bool) ($question['required'] ?? false),
            ], $survey->questions ?? []),
            'answered' => $invitation->responded_at !== null,
        ];
    }
}
