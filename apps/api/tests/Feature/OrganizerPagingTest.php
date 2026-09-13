<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Http\Controllers\Api\Organizer\EventImageController;
use App\Http\Controllers\Api\Organizer\ReminderController;
use App\Http\Controllers\Api\Organizer\TicketTypeController;
use App\Models\Code;
use App\Models\EmailPreference;
use App\Models\Event;
use App\Models\EventImage;
use App\Models\EventMessage;
use App\Models\Order;
use App\Models\Organization;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\TicketIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every organizer list either pages and says so, or is bounded where it is written.
 *
 * The failure this guards against is the quiet one: a list that stops at 30
 * with nothing on screen to say there is more, so the order somebody came to
 * refund is simply not there.
 */
class OrganizerPagingTest extends TestCase
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
            'starts_at' => now()->addMonth(),
        ]);
        $this->owner = $this->memberOf($this->org, Role::Owner);

        Sanctum::actingAs($this->owner, [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);
    }

    private function memberOf(Organization $org, Role $role, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        $org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user->fresh()->load('organizations');
    }

    // --- events -------------------------------------------------------------

    public function test_upcoming_and_past_are_paged_separately_in_the_order_each_is_read(): void
    {
        foreach ([3, 1, 2] as $days) {
            Event::factory()->create(['organization_id' => $this->org->id, 'title' => "Ahead {$days}", 'starts_at' => now()->addDays($days)]);
            Event::factory()->create(['organization_id' => $this->org->id, 'title' => "Behind {$days}", 'starts_at' => now()->subDays($days)]);
        }

        $upcoming = $this->getJson('/api/organizer/events?when=upcoming')->assertOk();
        $this->assertSame(['Ahead 1', 'Ahead 2', 'Ahead 3'], array_slice($upcoming->json('data.*.title'), 0, 3));

        // Most recent first: last night is what somebody looks for, not the
        // first event the organization ever ran.
        $past = $this->getJson('/api/organizer/events?when=past')->assertOk();
        $this->assertSame(['Behind 1', 'Behind 2', 'Behind 3'], $past->json('data.*.title'));
        $past->assertJsonPath('meta.total', 3)->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 1);
    }

    public function test_the_thirty_first_event_is_on_page_two_and_the_meta_says_so(): void
    {
        Event::factory()->count(34)->create(['organization_id' => $this->org->id, 'starts_at' => now()->subWeek()]);

        $first = $this->getJson('/api/organizer/events?when=past')
            ->assertOk()
            ->assertJsonCount(30, 'data')
            ->assertJsonPath('meta.total', 34)
            ->assertJsonPath('meta.last_page', 2);

        $second = $this->getJson('/api/organizer/events?when=past&page=2')->assertOk()->assertJsonCount(4, 'data');

        // Same start time for all 34: without a tiebreak the pages could
        // overlap and one event appear on neither.
        $ids = array_merge($first->json('data.*.id'), $second->json('data.*.id'));
        $this->assertCount(34, array_unique($ids));
    }

    public function test_page_size_is_held_to_a_range(): void
    {
        $this->getJson('/api/organizer/events?per_page=5000')->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/organizer/events?per_page=0')->assertOk()->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/organizer/events?when=sideways')->assertStatus(422);
    }

    public function test_the_selected_organization_scopes_the_list(): void
    {
        $other = Organization::create(['name' => 'Toronto Nights', 'slug' => 'toronto-nights']);
        $this->memberOf($other, Role::Owner, $this->owner);
        Event::factory()->create(['organization_id' => $other->id, 'title' => 'Elsewhere']);

        Sanctum::actingAs($this->owner->fresh()->load('organizations'), [TokenAbility::Organizer->value]);

        $titles = fn (array $headers) => $this->getJson('/api/organizer/events', $headers)->assertOk()->json('data.*.title');

        $this->assertContains('Elsewhere', $titles([]));
        $this->assertNotContains('Elsewhere', $titles(['X-Organization' => $this->org->id]));
        $this->assertSame(['Elsewhere'], $titles(['X-Organization' => $other->id]));
    }

    public function test_naming_an_organization_you_are_not_in_is_refused(): void
    {
        $stranger = Organization::create(['name' => 'Not Yours', 'slug' => 'not-yours']);
        Event::factory()->create(['organization_id' => $stranger->id]);

        $this->getJson('/api/organizer/events', ['X-Organization' => $stranger->id])->assertForbidden();
        $this->getJson('/api/organizer/events/options', ['X-Organization' => $stranger->id])->assertForbidden();
    }

    public function test_filter_options_hold_every_event_not_a_page_of_them(): void
    {
        Event::factory()->count(120)->create(['organization_id' => $this->org->id]);

        $this->getJson('/api/organizer/events/options')
            ->assertOk()
            ->assertJsonCount(121, 'data')
            ->assertJsonStructure(['data' => [['id', 'title', 'starts_at']]]);
    }

    public function test_the_browser_may_send_the_organization_header(): void
    {
        $origin = config('cors.allowed_origins')[0];

        $response = $this->call('OPTIONS', '/api/organizer/events', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,x-organization',
        ]);

        $this->assertStringContainsStringIgnoringCase('x-organization', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    // --- an event's lists ----------------------------------------------------

    public function test_an_events_thirty_first_order_can_be_reached_to_refund(): void
    {
        $paidAt = now()->subDay();

        foreach (range(1, 35) as $n) {
            Order::create([
                'organization_id' => $this->org->id,
                'event_id' => $this->event->id,
                'reference' => strtoupper(Str::random(10)),
                'buyer_email' => "buyer{$n}@example.com",
                'buyer_name' => "Buyer {$n}",
                'currency' => 'CAD',
                'subtotal_amount' => 1000,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'net_revenue_amount' => 1000,
                'service_charge_amount' => 0,
                'total_amount' => 1000,
                'gateway' => 'stripe',
                'gateway_reference' => "pi_{$n}",
                'status' => 'paid',
                // All in the same second, which is what a sale-opening rush
                // looks like and what makes an unstable order drop rows.
                'paid_at' => $paidAt,
            ]);
        }

        $url = "/api/organizer/events/{$this->event->id}/orders";

        $first = $this->getJson($url)->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('meta.total', 35);
        $second = $this->getJson("{$url}?page=2")->assertOk()->assertJsonCount(5, 'data');

        $this->assertCount(35, array_unique(array_merge($first->json('data.*.id'), $second->json('data.*.id'))));

        // And found by search wherever it sits, not only on the page loaded.
        $this->getJson("{$url}?q=BUYER35")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.buyer_name', 'Buyer 35');
        $this->getJson("{$url}?q=100%25")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_guests_with_the_same_name_are_each_listed_once(): void
    {
        $type = TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 0, 'status' => 'on_sale']);

        foreach (range(1, 60) as $n) {
            app(TicketIssuer::class)->issueComp($this->event->id, $type->id, "ada{$n}@example.com", 'Ada Okafor');
        }

        $url = "/api/organizer/events/{$this->event->id}/guests?per_page=25";
        $ids = [];

        foreach ([1, 2, 3] as $page) {
            $response = $this->getJson("{$url}&page={$page}")->assertOk()->assertJsonPath('meta.last_page', 3);
            $ids = array_merge($ids, $response->json('data.*.id'));
        }

        $this->assertCount(60, array_unique($ids));
    }

    public function test_codes_are_paged(): void
    {
        foreach (range(1, 55) as $n) {
            Code::create(['organization_id' => $this->org->id, 'event_id' => null, 'code' => "PROMO{$n}", 'discount_type' => 'percentage', 'discount_value' => 1000, 'is_active' => true]);
        }

        $url = "/api/organizer/events/{$this->event->id}/codes";

        $this->getJson($url)->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.total', 55);
        $this->getJson("{$url}?page=2")->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_sent_messages_are_paged_and_the_audience_still_comes_with_them(): void
    {
        foreach (range(1, 23) as $n) {
            EventMessage::create([
                'event_id' => $this->event->id,
                'sent_by' => $this->owner->id,
                'subject' => "Update {$n}",
                'body' => 'Doors at nine.',
                'status' => 'sent',
            ]);
        }

        $this->getJson("/api/organizer/events/{$this->event->id}/messages")
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 23)
            ->assertJsonStructure(['audience' => ['holders', 'reachable']]);
    }

    // --- bounded where written ----------------------------------------------

    public function test_an_event_cannot_grow_past_the_tier_limit(): void
    {
        foreach (range(1, TicketTypeController::MAX_PER_EVENT) as $n) {
            TicketType::create(['event_id' => $this->event->id, 'name' => "Tier {$n}", 'price_amount' => 1000, 'status' => 'on_sale']);
        }

        $this->postJson("/api/organizer/events/{$this->event->id}/ticket-types", [
            'name' => 'One too many',
            'price_amount' => 1000,
        ])->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'up to 50'));
    }

    public function test_a_full_gallery_refuses_another_picture(): void
    {
        foreach (range(1, EventImageController::MAX_GALLERY) as $n) {
            EventImage::create(['event_id' => $this->event->id, 'kind' => 'gallery', 'path' => "events/x/{$n}.jpg"]);
        }

        $this->postJson("/api/organizer/events/{$this->event->id}/images", [
            'kind' => 'gallery',
            'file' => UploadedFile::fake()->image('room.jpg', 800, 600),
        ])->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'up to 30'));
    }

    public function test_an_event_cannot_schedule_endless_reminders(): void
    {
        foreach (range(1, ReminderController::MAX_PER_EVENT) as $n) {
            $this->event->reminders()->create(['offset_minutes' => $n * 60, 'status' => 'scheduled']);
        }

        $this->postJson("/api/organizer/events/{$this->event->id}/reminders", ['offset_minutes' => 24 * 60])
            ->assertStatus(422);

        // A cancelled one gives its place back.
        $this->event->reminders()->first()->update(['status' => 'cancelled']);

        $this->postJson("/api/organizer/events/{$this->event->id}/reminders", ['offset_minutes' => 24 * 60])
            ->assertCreated();
    }

    public function test_opt_outs_are_checked_for_an_audience_larger_than_one_statement_can_bind(): void
    {
        // Past Postgres's 65,535 bound parameters, where a single whereIn
        // failed and took messaging and reminders down for the biggest events.
        $emails = array_map(fn (int $n) => "guest{$n}@example.com", range(1, 66000));

        EmailPreference::forEmail('guest65999@example.com')->update(['reminders_opted_out_at' => now()]);

        $remindable = EmailPreference::remindable($emails);

        $this->assertCount(65999, $remindable);
        $this->assertNotContains('guest65999@example.com', $remindable);
    }
}
