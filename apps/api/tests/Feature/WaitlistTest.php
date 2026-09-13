<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Mail\WaitlistOpenedMail;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketType;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\TicketIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A waitlist for when an event sells out.
 */
class WaitlistTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $general;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->event = Event::factory()->published()->create(['organization_id' => $this->org->id, 'slug' => 'afro-fest', 'starts_at' => now()->addWeek()]);
        $this->general = TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 0, 'quantity_available' => 1, 'status' => 'on_sale']);
    }

    private function sellOut(): void
    {
        app(TicketIssuer::class)->issueComp($this->event->id, $this->general->id, 'first@example.com', 'First');
    }

    private function join(string $email = 'ada@example.com', int $quantity = 2)
    {
        return $this->postJson('/api/events/afro-fest/waitlist', ['email' => $email, 'name' => 'Ada', 'quantity' => $quantity]);
    }

    private function asOwner(): void
    {
        $owner = User::factory()->create();
        $this->org->members()->attach($owner->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);
        Sanctum::actingAs($owner->fresh()->load('organizations'), [TokenAbility::Organizer->value]);
    }

    public function test_the_waitlist_opens_only_when_nothing_can_be_bought(): void
    {
        $this->join()->assertStatus(422)->assertJsonPath('message', 'Tickets are on sale right now — no need to wait.');

        $this->sellOut();

        $this->join()->assertCreated();
        $this->assertSame(1, WaitlistEntry::where('email', 'ada@example.com')->count());
    }

    public function test_joining_twice_updates_rather_than_duplicates(): void
    {
        $this->sellOut();

        $this->join('Ada@Example.com', 2)->assertCreated();
        $this->join('ada@example.com', 4)->assertCreated();

        $this->assertSame(4, WaitlistEntry::sole()->quantity);
    }

    public function test_the_organizer_sees_who_is_waiting_and_tells_the_first_ones_once_tickets_are_back(): void
    {
        $this->sellOut();
        foreach (['one', 'two', 'three'] as $i => $who) {
            $this->travel(1)->minutes();
            $this->join("{$who}@example.com", $i + 1);
        }

        $this->asOwner();

        $this->getJson("/api/organizer/events/{$this->event->id}/waitlist")
            ->assertOk()
            ->assertJsonPath('summary.waiting', 3)
            ->assertJsonPath('summary.waiting_tickets', 6)
            ->assertJsonPath('data.0.email', 'one@example.com');

        // Nothing to buy yet: telling them would send them to a sold-out page.
        $this->postJson("/api/organizer/events/{$this->event->id}/waitlist/notify", ['limit' => 2])->assertStatus(422);
        Mail::assertNothingQueued();

        $this->general->update(['quantity_available' => 3]);

        $this->postJson("/api/organizer/events/{$this->event->id}/waitlist/notify", ['limit' => 2, 'note' => 'Two more released.'])
            ->assertOk()
            ->assertJsonPath('told', 2);

        Mail::assertQueued(WaitlistOpenedMail::class, 2);
        Mail::assertQueued(WaitlistOpenedMail::class, fn (WaitlistOpenedMail $m) => $m->hasTo('one@example.com'));
        Mail::assertNotQueued(WaitlistOpenedMail::class, fn (WaitlistOpenedMail $m) => $m->hasTo('three@example.com'));

        // A second press does not mail the same people again.
        $this->postJson("/api/organizer/events/{$this->event->id}/waitlist/notify", ['limit' => 2])->assertJsonPath('told', 1);
        Mail::assertQueued(WaitlistOpenedMail::class, 3);
    }

    public function test_buying_marks_them_as_got_in(): void
    {
        $this->sellOut();
        $this->join('ada@example.com');
        $this->general->update(['quantity_available' => 2]);

        $order = app(CheckoutService::class)->reserve($this->event, [$this->general->id => 1], 'ADA@example.com', 'Ada');
        app(\App\Services\Checkout\Fulfiller::class)->fulfilFree($order);

        $this->assertSame('purchased', WaitlistEntry::sole()->status);
    }

    public function test_leaving_needs_a_press_not_just_a_visit(): void
    {
        $this->sellOut();
        $this->join();
        $entry = WaitlistEntry::sole();

        // A mail scanner following the link changes nothing.
        $this->get("/waitlist/{$entry->token}/leave")->assertOk()->assertSee('Leave the waitlist');
        $this->assertSame('waiting', $entry->fresh()->status);

        $this->post("/waitlist/{$entry->token}/leave")->assertOk()->assertSee('You have left the waitlist');
        $this->assertSame('left', $entry->fresh()->status);

        $this->get('/waitlist/not-a-token/leave')->assertOk()->assertSee('That link has expired');
    }

    public function test_the_email_says_first_come_first_served_and_how_to_leave(): void
    {
        $this->sellOut();
        $this->join();
        $entry = WaitlistEntry::sole();

        $html = (new WaitlistOpenedMail($entry, 'Doors at ten.'))->render();

        $this->assertStringContainsString('does not hold one for you', $html);
        $this->assertStringContainsString('Doors at ten.', $html);
        $this->assertStringContainsString("/waitlist/{$entry->token}/leave", $html);
        $this->assertStringContainsString('/afro-fest/tickets', $html);
    }
}
