<?php

namespace Database\Seeders;

use App\Models\SurveyTemplate;
use Illuminate\Database\Seeder;

/**
 * myFiesta's own survey: the one every night is sent unless its organizer
 * chose another.
 *
 * Six questions, because a survey somebody finishes is worth more than one
 * that asks everything. The recommend question first, as the one number an
 * organizer can follow from night to night; then the four things people
 * remember about a night out — the sound, the room, getting in, and whether
 * it was worth the money; then one box to say anything else.
 *
 * The question ids are fixed, and the insights (SurveyInsights) read them to
 * suggest what to do about a low score, so they are never renamed.
 *
 * The one copy of these questions. A deploy installs them through a migration
 * (…_myfiestas_own_survey), which runs this; a developer's database gets them
 * the same way. It only ever adds the template when there is none, and never
 * edits one that exists: nights already sent with it keep what they asked.
 */
class SurveyTemplateSeeder extends Seeder
{
    public const NAME = 'myFiesta survey';

    /** @var list<array{id: string, type: string, label: string, options: list<string>, required: bool}> */
    public const QUESTIONS = [
        ['id' => 'recommend', 'type' => 'nps', 'label' => 'How likely are you to recommend this event to a friend?', 'options' => [], 'required' => true],
        ['id' => 'sound', 'type' => 'rating5', 'label' => 'How was the sound?', 'options' => [], 'required' => false],
        ['id' => 'venue', 'type' => 'rating5', 'label' => 'How was the venue?', 'options' => [], 'required' => false],
        ['id' => 'door', 'type' => 'rating5', 'label' => 'How was getting in at the door?', 'options' => [], 'required' => false],
        ['id' => 'value', 'type' => 'rating5', 'label' => 'How was it for the money?', 'options' => [], 'required' => false],
        ['id' => 'change', 'type' => 'text', 'label' => 'What should we change?', 'options' => [], 'required' => false],
    ];

    public function run(): void
    {
        $this->install();
    }

    /** Adds the template when there is none. Returns whether it did. */
    public function install(): bool
    {
        if (SurveyTemplate::query()->whereNull('organization_id')->exists()) {
            return false;
        }

        SurveyTemplate::create([
            'organization_id' => null,
            'name' => self::NAME,
            'questions' => self::QUESTIONS,
        ]);

        return true;
    }
}
