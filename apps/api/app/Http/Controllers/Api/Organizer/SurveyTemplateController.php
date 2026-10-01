<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\EventSurvey;
use App\Models\Organization;
use App\Models\SurveyTemplate;
use App\Services\Audit\Auditor;
use App\Services\Surveys\SurveyQuestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * An organization's own surveys: written once and chosen for any night.
 *
 * myFiesta's own is listed beside them to choose from and copy, and is never
 * edited from here. Removing one archives it rather than deleting it: a night
 * already sent with it is compared with the next night sent with it, and a
 * night that was going to send it sends myFiesta's instead.
 */
class SurveyTemplateController extends Controller
{
    public function __construct(
        private readonly SurveyQuestions $questions,
        private readonly Auditor $auditor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $templates = SurveyTemplate::query()
            ->whereNull('archived_at')
            ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $organization->id))
            ->orderByRaw('organization_id is not null')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $templates->map(fn (SurveyTemplate $template) => $this->present($template))->values(),
            'max_questions' => SurveyQuestions::MAX_QUESTIONS,
            'types' => SurveyQuestions::TYPES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $data = $request->validate($this->rules(), $this->messages());
        $questions = $this->questions->fromInput($request->input('questions'));

        $template = SurveyTemplate::create([
            'organization_id' => $organization->id,
            'name' => trim($data['name']),
            'questions' => $questions,
        ]);

        $this->auditor->record('survey_template.created', $template, $request->user(), $organization->id);

        return response()->json(['data' => $this->present($template)], 201);
    }

    public function update(Request $request, SurveyTemplate $template): JsonResponse
    {
        $organization = $this->organization($request);
        $this->ownedBy($template, $organization);

        $data = $request->validate($this->rules(), $this->messages());
        $questions = $this->questions->fromInput($request->input('questions'));

        // Nights already sent keep the questions they were sent with
        // (event_surveys.questions); nights still to go send these.
        $template->forceFill(['name' => trim($data['name']), 'questions' => $questions])->save();

        $this->auditor->record('survey_template.updated', $template, $request->user(), $organization->id);

        return response()->json(['data' => $this->present($template)]);
    }

    public function destroy(Request $request, SurveyTemplate $template): JsonResponse
    {
        $organization = $this->organization($request);
        $this->ownedBy($template, $organization);

        $waiting = $this->waiting($template);

        $template->forceFill(['archived_at' => now()])->save();

        $this->auditor->record('survey_template.archived', $template, $request->user(), $organization->id, metadata: ['nights_waiting' => $waiting]);

        return response()->json([
            'message' => match ($waiting) {
                0 => "Removed “{$template->name}”.",
                1 => "Removed “{$template->name}”. The 1 night that was going to send it sends myFiesta’s survey instead.",
                default => "Removed “{$template->name}”. The {$waiting} nights that were going to send it send myFiesta’s survey instead.",
            },
        ]);
    }

    /** @return array<string, mixed> */
    private function present(SurveyTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'platform' => $template->isPlatform(),
            'questions' => $template->questions ?? [],
            // Nights still to be sent with it, so removing one says what it touches.
            'nights_waiting' => $template->isPlatform() ? 0 : $this->waiting($template),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }

    private function waiting(SurveyTemplate $template): int
    {
        return EventSurvey::query()->where('template_id', $template->id)->whereNull('sent_at')->count();
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            // Each question is checked by SurveyQuestions, which tidies it too.
            'questions' => ['required', 'array'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'name.required' => 'Give the survey a name, so you can find it when choosing one for a night.',
            'name.max' => 'Keep the name under 80 characters.',
            'questions.required' => 'Add at least one question.',
        ];
    }

    private function ownedBy(SurveyTemplate $template, Organization $organization): void
    {
        // myFiesta's own and another organization's look the same from here:
        // not yours to change.
        abort_if($template->organization_id !== $organization->id || $template->archived_at !== null, 404);
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
