<?php

namespace Tests\Feature;

use App\Contracts\Payments\CheckoutOptions;
use App\Contracts\Payments\CheckoutSession;
use App\Contracts\Payments\PaymentEvent;
use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Contracts\Payments\RefundResult;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Exceptions\CheckoutException;
use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\Order;
use App\Models\OrderAnswer;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\Answers;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What an organizer asks at checkout, and what a buyer answers.
 *
 * The rules being protected here are the ones a client cannot be trusted with:
 * that a required question is required however the page was rendered, that an
 * answer to a choice question is one of the choices, and that an answer given
 * before any ticket existed ends up attached to the right person's ticket once
 * the money arrives — which is the whole reason a door can read it.
 */
class CheckoutQuestionsTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutService $checkout;

    private Fulfiller $fulfiller;

    private Event $event;

    private TicketType $general;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);

        // A gateway that answers rather than one that is reached: this test
        // is about the form, not about Stripe.
        $registry = new PaymentGatewayRegistry;
        $registry->register(new QuestionsTestGateway);
        $this->app->instance(PaymentGatewayRegistry::class, $registry);

        $this->checkout = app(CheckoutService::class);
        $this->fulfiller = app(Fulfiller::class);

        $this->event = Event::create([
            'organization_id' => Organization::create(['name' => 'N', 'slug' => 'n-'.uniqid()])->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->general = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 10000,
            'status' => 'on_sale',
        ]);
    }

    private function ask(array $attributes): EventQuestion
    {
        return EventQuestion::create([
            'event_id' => $this->event->id,
            'label' => 'Name on the ticket',
            'type' => 'text',
            'required' => false,
            'per_attendee' => false,
            ...$attributes,
        ]);
    }

    private function reserve(array $answers = [], array $attendees = [], int $quantity = 1): Order
    {
        return $this->checkout->reserve(
            event: $this->event,
            quantities: [$this->general->id => $quantity],
            buyerEmail: 'ada@example.test',
            buyerName: 'Ada Buyer',
            answers: $answers,
            attendees: $attendees,
        );
    }

    // --- what the buyer is shown -------------------------------------------

    public function test_the_event_page_carries_the_questions_to_ask(): void
    {
        $this->ask([
            'label' => 'How did you hear about this?',
            'type' => 'choice',
            'options' => ['Instagram', 'A friend'],
            'required' => true,
        ]);

        $this->getJson('/api/events/afro-fest')
            ->assertOk()
            ->assertJsonPath('data.questions.0.label', 'How did you hear about this?')
            ->assertJsonPath('data.questions.0.type', 'choice')
            ->assertJsonPath('data.questions.0.options', ['Instagram', 'A friend'])
            ->assertJsonPath('data.questions.0.required', true)
            ->assertJsonPath('data.questions.0.per_attendee', false);
    }

    public function test_an_event_that_asks_nothing_says_so_rather_than_leaving_it_out(): void
    {
        // An empty list, not a missing key: a client rendering a form from
        // this should not have to tell "asks nothing" apart from "old server".
        $this->getJson('/api/events/afro-fest')->assertOk()->assertJsonPath('data.questions', []);
    }

    public function test_a_removed_question_is_no_longer_asked(): void
    {
        $question = $this->ask(['label' => 'Dietary requirements']);
        $question->delete();

        $this->getJson('/api/events/afro-fest')->assertOk()->assertJsonPath('data.questions', []);
    }

    // --- answers for the order ---------------------------------------------

    public function test_an_answer_for_the_order_is_stored_against_it(): void
    {
        $question = $this->ask(['label' => 'How did you hear about this?']);

        $order = $this->reserve([$question->id => 'Instagram']);

        $answer = $order->answers()->sole();

        $this->assertSame(['Instagram'], $answer->value);
        $this->assertNull($answer->order_line_id, 'Asked once for the order, not about a person.');
        $this->assertNull($answer->ticket_id);
    }

    public function test_a_required_question_is_required_whatever_the_page_rendered(): void
    {
        $this->ask(['label' => 'How did you hear about this?', 'required' => true]);

        $this->expectException(CheckoutException::class);
        // Named, so somebody does not have to hunt back up a form.
        $this->expectExceptionMessage('Please answer: How did you hear about this?');

        $this->reserve();
    }

    public function test_refusing_an_order_holds_no_stock(): void
    {
        $this->ask(['required' => true]);

        try {
            $this->reserve();
        } catch (CheckoutException) {
            // Expected.
        }

        // A hold taken for an order that was never going to exist is stock
        // nobody else can buy for twenty minutes.
        $this->assertSame(0, Order::count());
        $this->assertDatabaseCount('inventory_holds', 0);
    }

    public function test_a_choice_answer_has_to_be_one_of_the_choices(): void
    {
        $question = $this->ask([
            'label' => 'Which night?',
            'type' => 'choice',
            'options' => ['Friday', 'Saturday'],
        ]);

        $this->expectException(CheckoutException::class);

        // Anything else would land in a column an organizer reads as though
        // they had offered it.
        $this->reserve([$question->id => 'Sunday']);
    }

    public function test_a_multi_choice_answer_stays_a_list(): void
    {
        $question = $this->ask([
            'label' => 'Which nights?',
            'type' => 'multi_choice',
            'options' => ['Friday', 'Saturday', 'Sunday'],
        ]);

        $order = $this->reserve([$question->id => ['Friday', 'Sunday']]);

        $this->assertSame(['Friday', 'Sunday'], $order->answers()->sole()->value);
    }

    public function test_no_is_an_answer(): void
    {
        $question = $this->ask(['label' => 'Do you need step-free access?', 'type' => 'boolean', 'required' => true]);

        // The one an organizer plans around is the yes, but a false is still
        // an answer — treating it as blank would refuse the order.
        $order = $this->reserve([$question->id => false]);

        $this->assertSame([false], $order->answers()->sole()->value);
        $this->assertSame('No', $order->answers()->sole()->asText());
    }

    public function test_an_answer_that_is_too_long_is_refused_by_the_server(): void
    {
        $question = $this->ask(['label' => 'Anything else?']);

        $this->expectException(CheckoutException::class);

        $this->reserve([$question->id => str_repeat('x', Answers::MAX_TEXT + 1)]);
    }

    public function test_an_answer_to_a_question_that_no_longer_exists_does_not_stop_the_sale(): void
    {
        $question = $this->ask(['label' => 'Dietary requirements']);
        $question->delete();

        // An organizer tidying their form while somebody is on the checkout
        // page must not turn that person's purchase into an error.
        $order = $this->reserve([$question->id => 'Vegetarian']);

        $this->assertSame('pending', $order->status);
        $this->assertSame(0, $order->answers()->count());
    }

    public function test_a_question_from_another_event_is_not_answerable_here(): void
    {
        $other = Event::create([
            'organization_id' => $this->event->organization_id,
            'slug' => 'somewhere-else',
            'title' => 'Somewhere Else',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $theirs = EventQuestion::create([
            'event_id' => $other->id,
            'label' => 'Their question',
            'type' => 'text',
        ]);

        $order = $this->reserve([$theirs->id => 'Something']);

        $this->assertSame(0, $order->answers()->count());
    }

    // --- answers about each person ------------------------------------------

    public function test_each_person_is_asked_and_each_answer_is_kept_apart(): void
    {
        $question = $this->ask(['label' => 'Full name', 'per_attendee' => true, 'required' => true]);

        $order = $this->reserve(
            attendees: [
                ['ticket_type_id' => $this->general->id, 'answers' => [$question->id => 'Ada Okoro']],
                ['ticket_type_id' => $this->general->id, 'answers' => [$question->id => 'Tunde Bello']],
            ],
            quantity: 2,
        );

        $answers = $order->answers()->orderBy('attendee_index')->get();

        $this->assertCount(2, $answers);
        $this->assertSame([0, 1], $answers->pluck('attendee_index')->all());
        $this->assertSame([['Ada Okoro'], ['Tunde Bello']], $answers->pluck('value')->all());
        $this->assertNotNull($answers->first()->order_line_id);
    }

    public function test_every_ticket_needs_somebody(): void
    {
        $question = $this->ask(['label' => 'Full name', 'per_attendee' => true, 'required' => true]);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('one set of answers per ticket');

        $this->reserve(
            attendees: [['ticket_type_id' => $this->general->id, 'answers' => [$question->id => 'Ada Okoro']]],
            quantity: 2,
        );
    }

    public function test_the_refusal_names_which_ticket_is_missing_an_answer(): void
    {
        $question = $this->ask(['label' => 'Full name', 'per_attendee' => true, 'required' => true]);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Please answer for ticket 2: Full name.');

        $this->reserve(
            attendees: [
                ['ticket_type_id' => $this->general->id, 'answers' => [$question->id => 'Ada Okoro']],
                ['ticket_type_id' => $this->general->id, 'answers' => []],
            ],
            quantity: 2,
        );
    }

    public function test_describing_nobody_is_refused_when_each_person_must_be_asked(): void
    {
        $this->ask(['label' => 'Full name', 'per_attendee' => true, 'required' => true]);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Please answer for each person');

        $this->reserve(quantity: 2);
    }

    public function test_an_optional_per_person_question_does_not_block_a_plain_order(): void
    {
        $this->ask(['label' => 'Dietary requirements', 'per_attendee' => true]);

        $order = $this->reserve(quantity: 2);

        $this->assertSame('pending', $order->status);
    }

    // --- once the money arrives ---------------------------------------------

    public function test_an_answer_ends_up_on_the_ticket_of_the_person_who_gave_it(): void
    {
        $question = $this->ask(['label' => 'Full name', 'per_attendee' => true, 'required' => true]);

        $order = $this->reserve(
            attendees: [
                ['ticket_type_id' => $this->general->id, 'answers' => [$question->id => 'Ada Okoro']],
                ['ticket_type_id' => $this->general->id, 'answers' => [$question->id => 'Tunde Bello']],
            ],
            quantity: 2,
        );

        // Answers are given before any ticket exists; the webhook mints them.
        $this->assertSame(0, OrderAnswer::whereNotNull('ticket_id')->count());

        // Straight to fulfilment, which is what a verified webhook does.
        $this->fulfiller->fulfil($order);

        $tickets = Ticket::where('order_id', $order->id)->orderBy('created_at')->orderBy('id')->get();

        $this->assertCount(2, $tickets);

        foreach ($tickets as $ticket) {
            $this->assertCount(1, $ticket->answers, 'Every ticket carries the answers for its own holder.');
        }

        $names = $tickets->flatMap(fn (Ticket $t) => $t->answers->pluck('value'))->flatten()->all();
        sort($names);

        $this->assertSame(['Ada Okoro', 'Tunde Bello'], $names);
    }

    public function test_the_order_level_answer_stays_off_the_tickets(): void
    {
        $question = $this->ask(['label' => 'How did you hear about this?']);

        $order = $this->reserve([$question->id => 'Instagram'], quantity: 2);

        // Straight to fulfilment, which is what a verified webhook does.
        $this->fulfiller->fulfil($order);

        // It is about the order, not about anybody standing at a door.
        $this->assertSame(0, OrderAnswer::whereNotNull('ticket_id')->count());
    }

    public function test_an_ordinary_order_with_no_questions_still_issues(): void
    {
        $order = $this->reserve(quantity: 3);

        // Straight to fulfilment, which is what a verified webhook does.
        $this->fulfiller->fulfil($order);

        $this->assertSame(3, Ticket::where('order_id', $order->id)->count());
        $this->assertSame(0, OrderAnswer::count());
    }

    // --- what an organizer and a door see -----------------------------------

    /**
     * A paid order with a name on each ticket and one answer for the order.
     *
     * @return array{question: EventQuestion, buyerQuestion: EventQuestion, order: Order}
     */
    private function soldWithAnswers(): array
    {
        $name = $this->ask(['label' => 'Name on the ticket', 'per_attendee' => true, 'required' => true]);
        $heard = $this->ask(['label' => 'How did you hear about this?']);

        $order = $this->reserve(
            answers: [$heard->id => 'Instagram'],
            attendees: [
                ['ticket_type_id' => $this->general->id, 'answers' => [$name->id => 'Ada Okoro']],
                ['ticket_type_id' => $this->general->id, 'answers' => [$name->id => 'Tunde Bello']],
            ],
            quantity: 2,
        );

        $this->fulfiller->fulfil($order);

        return ['question' => $name, 'buyerQuestion' => $heard, 'order' => $order];
    }

    private function asOrganizer(): User
    {
        $user = User::factory()->create();

        Organization::find($this->event->organization_id)->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        return $user;
    }

    public function test_the_guest_list_shows_what_each_person_answered(): void
    {
        $this->soldWithAnswers();
        $this->asOrganizer();

        $body = $this->getJson("/api/organizer/events/{$this->event->id}/guests")->assertOk()->json('data');

        $names = collect($body)
            ->flatMap(fn (array $row) => collect($row['answers'])
                ->where('label', 'Name on the ticket')
                ->pluck('value'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['Ada Okoro', 'Tunde Bello'], $names);

        // What the buyer answered for the order is on every row of it: on a
        // flat list that is what a reader expects.
        $this->assertSame('Instagram', collect($body[0]['answers'])->firstWhere('label', 'How did you hear about this?')['value']);
    }

    public function test_the_export_has_a_column_for_every_question_and_still_no_ticket_codes(): void
    {
        ['question' => $question] = $this->soldWithAnswers();
        $this->asOrganizer();

        $csv = $this->get("/api/organizer/events/{$this->event->id}/guests/export")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Name on the ticket', $csv);
        $this->assertStringContainsString('How did you hear about this?', $csv);
        $this->assertStringContainsString('Ada Okoro', $csv);
        $this->assertStringContainsString('Tunde Bello', $csv);

        // The rule that does not bend: a printed or forwarded guest list must
        // not be a set of working tickets.
        foreach (Ticket::where('event_id', $this->event->id)->pluck('code') as $code) {
            $this->assertStringNotContainsString($code, $csv);
        }

        $this->assertNotNull($question);
    }

    public function test_a_question_that_was_removed_keeps_its_column(): void
    {
        ['question' => $question] = $this->soldWithAnswers();
        $question->delete();

        $this->asOrganizer();

        $csv = $this->get("/api/organizer/events/{$this->event->id}/guests/export")->assertOk()->streamedContent();

        // Answers outlive the question, and a column of them with no heading
        // is a column nobody can read.
        $this->assertStringContainsString('Name on the ticket', $csv);
        $this->assertStringContainsString('Ada Okoro', $csv);
    }

    public function test_the_door_shows_what_the_person_in_front_of_it_answered(): void
    {
        $this->soldWithAnswers();
        $this->asOrganizer();

        $ticket = Ticket::where('event_id', $this->event->id)->orderBy('created_at')->orderBy('id')->first();

        $response = $this->postJson("/api/events/{$this->event->id}/scan", ['code' => $ticket->code])
            ->assertOk()
            ->assertJsonPath('accepted', true);

        $answers = $response->json('ticket.answers');

        $this->assertCount(1, $answers, 'The door is shown what was asked of this person and nothing else.');
        $this->assertSame('Name on the ticket', $answers[0]['label']);

        // Never what the buyer answered for the order. How somebody heard
        // about the night is not a door's business.
        $this->assertNotContains('How did you hear about this?', array_column($answers, 'label'));
    }

    // --- over the wire ------------------------------------------------------

    public function test_the_checkout_endpoint_takes_answers_and_refuses_a_missing_one(): void
    {
        $question = $this->ask(['label' => 'How did you hear about this?', 'required' => true]);

        $body = [
            'items' => [['ticket_type_id' => $this->general->id, 'quantity' => 1]],
            'buyer' => ['name' => 'Ada Buyer', 'email' => 'ada@example.test'],
        ];

        $this->postJson('/api/events/afro-fest/orders', $body)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Please answer: How did you hear about this?.');

        $this->postJson('/api/events/afro-fest/orders', $body + [
            'answers' => [$question->id => 'Instagram'],
        ])->assertCreated();

        $this->assertSame(['Instagram'], OrderAnswer::sole()->value);
    }
}

/** Enough of a gateway to let an order be placed. */
class QuestionsTestGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'stripe';
    }

    public function supports(string $currency): bool
    {
        return true;
    }

    public function createCheckout(Order $order, CheckoutOptions $options): CheckoutSession
    {
        return new CheckoutSession(reference: 'cs_test', redirectUrl: 'https://checkout.example/s', expiresAt: null);
    }

    public function verifySignature(string $payload, array $headers): bool
    {
        return false;
    }

    public function parseWebhook(string $payload, array $headers): ?PaymentEvent
    {
        return null;
    }

    public function refund(Order $order, int $amountMinorUnits, ?string $reason = null, ?string $idempotencyKey = null): RefundResult
    {
        throw new \LogicException('Not needed here.');
    }
}
