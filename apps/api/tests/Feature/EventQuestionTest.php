<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Http\Controllers\Api\Organizer\QuestionController;
use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\Order;
use App\Models\OrderAnswer;
use App\Models\OrderLine;
use App\Models\Organization;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An organizer deciding what to ask.
 *
 * The rules worth holding are about what happens after somebody has answered:
 * a question can be reworded but not reshaped, and removing it never removes
 * the answers — which are, after all, the reason it was asked.
 */
class EventQuestionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::factory()->published()->create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
        ]);

        $this->owner = User::factory()->create();
        $this->join($this->owner, Role::Owner);
        $this->actAs($this->owner);
    }

    private function join(User $user, Role $role): void
    {
        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);
    }

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/organizer/events/{$this->event->id}/questions{$suffix}";
    }

    private function ask(array $attributes = []): EventQuestion
    {
        return EventQuestion::create([
            'event_id' => $this->event->id,
            'label' => 'Full name',
            'type' => 'text',
            ...$attributes,
        ]);
    }

    /** An answer to a question, on an order that exists. */
    private function answer(EventQuestion $question): OrderAnswer
    {
        $type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 1000,
            'status' => 'on_sale',
        ]);

        $order = Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(8)),
            'buyer_email' => 'ada@example.test',
            'buyer_name' => 'Ada',
            'currency' => 'CAD',
            'subtotal_amount' => 1000,
            'net_revenue_amount' => 1000,
            'total_amount' => 1000,
            'status' => 'paid',
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'ticket_type_id' => $type->id,
            'name' => 'General',
            'unit_price_amount' => 1000,
            'quantity' => 1,
            'line_total_amount' => 1000,
        ]);

        return OrderAnswer::create([
            'order_id' => $order->id,
            'event_question_id' => $question->id,
            'value' => ['Ada Okoro'],
        ]);
    }

    public function test_a_question_is_added_and_appears_on_the_public_event_page(): void
    {
        $this->postJson($this->url(), [
            'label' => 'How did you hear about this?',
            'type' => 'choice',
            'options' => ['Instagram', 'A friend'],
            'required' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.label', 'How did you hear about this?')
            ->assertJsonPath('data.options', ['Instagram', 'A friend'])
            ->assertJsonPath('data.answered', false);

        $this->getJson('/api/events/afro-fest')
            ->assertOk()
            ->assertJsonPath('data.questions.0.label', 'How did you hear about this?');
    }

    public function test_a_choice_needs_something_to_choose_from(): void
    {
        $this->postJson($this->url(), [
            'label' => 'Which night?',
            'type' => 'choice',
            'options' => ['Friday'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A question people choose an answer to needs at least two options.');
    }

    public function test_two_options_worded_the_same_are_refused(): void
    {
        $this->postJson($this->url(), [
            'label' => 'Which night?',
            'type' => 'choice',
            'options' => ['Friday', ' Friday '],
        ])->assertStatus(422);
    }

    public function test_options_are_dropped_when_a_question_stops_being_a_choice(): void
    {
        $question = $this->ask(['type' => 'choice', 'options' => ['Friday', 'Saturday']]);

        $this->patchJson($this->url("/{$question->id}"), ['type' => 'text'])
            ->assertOk()
            ->assertJsonPath('data.options', []);
    }

    public function test_there_is_a_limit_on_how_much_somebody_is_asked(): void
    {
        for ($i = 0; $i < QuestionController::MAX_PER_EVENT; $i++) {
            $this->ask(['label' => 'Question '.$i]);
        }

        $this->postJson($this->url(), ['label' => 'One more', 'type' => 'text'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'An event can ask up to '.QuestionController::MAX_PER_EVENT.' questions. Remove one to add another.');
    }

    public function test_a_question_can_be_reworded_after_somebody_has_answered(): void
    {
        $question = $this->ask(['label' => 'Nam on the ticket']);
        $this->answer($question);

        // A typo in a question does not invalidate the answers to it.
        $this->patchJson($this->url("/{$question->id}"), ['label' => 'Name on the ticket'])
            ->assertOk()
            ->assertJsonPath('data.label', 'Name on the ticket')
            ->assertJsonPath('data.answered', true);
    }

    public function test_a_question_cannot_be_reshaped_after_somebody_has_answered(): void
    {
        $question = $this->ask();
        $this->answer($question);

        // Every answer already given would sit outside the list of things it
        // was possible to say, and nothing would mark which were which.
        $this->patchJson($this->url("/{$question->id}"), [
            'type' => 'choice',
            'options' => ['Ada', 'Tunde'],
        ])->assertStatus(422);

        $this->assertSame('text', $question->fresh()->type);
    }

    public function test_removing_a_question_keeps_what_people_answered(): void
    {
        $question = $this->ask();
        $answer = $this->answer($question);

        $this->deleteJson($this->url("/{$question->id}"))
            ->assertOk()
            ->assertJsonPath('message', 'No longer asked. The answers people already gave are kept.');

        $this->assertNotNull($answer->fresh(), 'The answers are the reason it was asked.');
        // And the label with them, or a column in an export has no heading.
        $this->assertSame('Full name', $answer->fresh()->question->label);

        $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_order_they_are_asked_in_is_set_as_one_list(): void
    {
        $first = $this->ask(['label' => 'First']);
        $second = $this->ask(['label' => 'Second']);

        $this->postJson($this->url('/order'), ['ids' => [$second->id, $first->id]])
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Second')
            ->assertJsonPath('data.1.label', 'First');
    }

    public function test_reordering_refuses_a_question_from_another_event(): void
    {
        $mine = $this->ask();

        $other = Event::factory()->published()->create(['organization_id' => $this->org->id]);
        $theirs = EventQuestion::create(['event_id' => $other->id, 'label' => 'Theirs', 'type' => 'text']);

        $this->postJson($this->url('/order'), ['ids' => [$mine->id, $theirs->id]])
            ->assertStatus(422);
    }

    public function test_a_question_on_another_event_is_not_reachable_through_this_one(): void
    {
        $other = Event::factory()->published()->create(['organization_id' => $this->org->id]);
        $theirs = EventQuestion::create(['event_id' => $other->id, 'label' => 'Theirs', 'type' => 'text']);

        $this->patchJson($this->url("/{$theirs->id}"), ['label' => 'Mine now'])->assertNotFound();
    }

    public function test_somebody_who_cannot_manage_tickets_cannot_change_the_form(): void
    {
        $marketing = User::factory()->create();
        $this->join($marketing, Role::Marketing);
        $this->actAs($marketing);

        // What is asked at checkout is part of what is being sold.
        $this->postJson($this->url(), ['label' => 'Anything', 'type' => 'text'])->assertForbidden();
    }

    public function test_another_organization_cannot_see_what_this_event_asks(): void
    {
        $this->ask();

        $stranger = User::factory()->create();
        $elsewhere = Organization::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $elsewhere->members()->attach($stranger->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);

        $this->actAs($stranger);

        $this->getJson($this->url())->assertForbidden();
    }
}
