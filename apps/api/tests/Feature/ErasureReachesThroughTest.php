<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\Order;
use App\Models\OrderAnswer;
use App\Models\OrderLine;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\PersonalData\Eraser;
use App\Services\PersonalData\Exporter;
use App\Services\PersonalData\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What an erasure has to reach beyond the columns that name somebody.
 *
 * Two things the map could not say before: an answer given at checkout,
 * which knows only its order and the ticket it is about — and those give up
 * the address that leads to it when they are anonymised; and a profile
 * photo, which is a file, and outlived the column that named it.
 */
class ErasureReachesThroughTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private EventQuestion $typed;

    private EventQuestion $picked;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $organization = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $organization->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $this->typed = EventQuestion::create(['event_id' => $this->event->id, 'label' => 'Anything we should know?', 'type' => 'text']);
        $this->picked = EventQuestion::create(['event_id' => $this->event->id, 'label' => 'Dinner', 'type' => 'choice', 'options' => ['Meat', 'Vegetarian']]);
    }

    /** An order under an address, with one typed and one picked answer. */
    private function answered(string $email, string $typed): Order
    {
        $order = Order::create([
            'organization_id' => $this->event->organization_id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => $email,
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => 5000,
            'total_amount' => 5000,
            'net_revenue_amount' => 5000,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        OrderAnswer::create(['order_id' => $order->id, 'event_question_id' => $this->typed->id, 'value' => [$typed]]);
        OrderAnswer::create(['order_id' => $order->id, 'event_question_id' => $this->picked->id, 'value' => ['Vegetarian']]);

        return $order;
    }

    public function test_what_somebody_typed_at_checkout_is_erased_through_their_orders(): void
    {
        // Typed as a different case from how the request arrives.
        $mine = $this->answered('Ada@Example.com', 'I use a wheelchair, call 416 555 0100');
        $theirs = $this->answered('grace@example.com', 'Grace, table 4');

        $done = app(Eraser::class)->erase(Subject::forEmail('ada@example.com'));

        // Found even though the order then lost the address that led here.
        $this->assertSame(['action' => 'deleted', 'rows' => 1], $done['order_answers']);
        $this->assertStringEndsWith('@erased.invalid', $mine->fresh()->buyer_email);

        $this->assertDatabaseMissing('order_answers', ['order_id' => $mine->id, 'event_question_id' => $this->typed->id]);

        // A choice from the organizer's own list stays in their counts, and
        // says nothing about anybody once the order names nobody.
        $this->assertSame(['Vegetarian'], OrderAnswer::where('order_id', $mine->id)->sole()->value);

        // Nobody else's.
        $this->assertSame(2, OrderAnswer::where('order_id', $theirs->id)->count());
    }

    public function test_an_export_carries_every_answer_they_gave_and_nobody_elses(): void
    {
        $this->answered('ada@example.com', 'Gluten free, please');
        $this->answered('grace@example.com', 'Grace, table 4');

        $export = app(Exporter::class)->build(Subject::forEmail('ada@example.com'));

        $this->assertCount(2, $export['data']['order_answers']);
        $json = json_encode($export);
        $this->assertStringContainsString('Gluten free, please', $json);
        $this->assertStringNotContainsString('Grace, table 4', $json);
    }

    /**
     * An answer about one person on somebody else's order is theirs too:
     * found through the ticket they hold, in their export and in their
     * erasure, before the ticket gives up the address that leads to it.
     */
    public function test_an_answer_about_the_person_holding_a_ticket_goes_with_them(): void
    {
        $order = $this->answered('ada@example.com', 'Ada is driving');
        $type = TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 5000, 'status' => 'on_sale']);
        $line = OrderLine::create([
            'order_id' => $order->id,
            'ticket_type_id' => $type->id,
            'name' => 'General',
            'unit_price_amount' => 5000,
            'quantity' => 1,
            'line_total_amount' => 5000,
        ]);
        $ticket = Ticket::create([
            'event_id' => $this->event->id,
            'ticket_type_id' => $type->id,
            'order_id' => $order->id,
            'code' => Ticket::generateCode(),
            'owner_email' => 'Grace@Example.com',
            'holder_name' => 'Grace Adeyemi',
            'status' => 'valid',
        ]);
        $about = OrderAnswer::create([
            'order_id' => $order->id,
            'event_question_id' => $this->typed->id,
            'order_line_id' => $line->id,
            'attendee_index' => 0,
            'ticket_id' => $ticket->id,
            'value' => ['Grace is diabetic'],
        ]);

        $export = json_encode(app(Exporter::class)->build(Subject::forEmail('grace@example.com')));
        $this->assertStringContainsString('Grace is diabetic', $export);
        $this->assertStringNotContainsString('Ada is driving', $export);

        $done = app(Eraser::class)->erase(Subject::forEmail('grace@example.com'));

        $this->assertSame(['action' => 'deleted', 'rows' => 1], $done['order_answers']);
        $this->assertNull($about->fresh());
        $this->assertNull($ticket->fresh()->owner_email);

        // The buyer's own answers, and the order, are the buyer's.
        $this->assertSame('ada@example.com', $order->fresh()->buyer_email);
        $this->assertSame(2, OrderAnswer::where('order_id', $order->id)->count());
    }

    public function test_an_address_known_only_through_an_answer_is_still_known(): void
    {
        $this->answered('ada@example.com', 'Hello');

        $this->assertTrue(Subject::forEmail('ada@example.com')->isKnown());
        $this->assertFalse(Subject::forEmail('nobody@example.com')->isKnown());
    }

    public function test_a_profile_photo_leaves_the_disk_with_the_account(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $path = UploadedFile::fake()->image('me.jpg')->store("avatars/{$user->id}", 'public');
        $user->forceFill(['avatar_path' => $path])->save();

        $other = User::factory()->create();
        $theirs = UploadedFile::fake()->image('them.jpg')->store("avatars/{$other->id}", 'public');
        $other->forceFill(['avatar_path' => $theirs])->save();

        app(Eraser::class)->erase(Subject::forEmail('ada@example.com'));

        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($theirs);
    }

    public function test_an_erasure_that_does_not_commit_leaves_the_photo(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $path = UploadedFile::fake()->image('me.jpg')->store("avatars/{$user->id}", 'public');
        $user->forceFill(['avatar_path' => $path])->save();

        DB::beginTransaction();
        app(Eraser::class)->erase(Subject::forEmail('ada@example.com'));
        DB::rollBack();

        // A file cannot be rolled back, so it is only removed once the
        // erasure is certain.
        $this->assertSame($path, $user->fresh()->avatar_path);
        Storage::disk('public')->assertExists($path);
    }
}
