<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Code;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\TicketIssuer;
use App\Services\Door\CheckInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The guest list and the codes behind an event.
 */
class GuestsAndCodesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);
    }

    private function member(Role $role): User
    {
        $user = User::factory()->create();

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user->fresh()->load('organizations');
    }

    private function asOrganizer(User $user): void
    {
        Sanctum::actingAs($user, [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    private function guest(string $name, string $email): Ticket
    {
        return app(TicketIssuer::class)
            ->issueComp($this->event->id, $this->type->id, $email, $name);
    }

    // --- guests ------------------------------------------------------------

    public function test_the_guest_list_shows_who_is_coming(): void
    {
        $this->guest('Ada Okafor', 'ada@example.com');
        $this->guest('Chidi Nwosu', 'chidi@example.com');

        $this->asOrganizer($this->member(Role::Manager));

        $this->getJson("/api/organizer/events/{$this->event->id}/guests")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Ada Okafor')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_the_guest_list_never_carries_ticket_codes(): void
    {
        $this->guest('Ada Okafor', 'ada@example.com');

        $this->asOrganizer($this->member(Role::Manager));

        $body = $this->getJson("/api/organizer/events/{$this->event->id}/guests")
            ->assertOk()
            ->json('data.0');

        // A guest list is read on a laptop in an office. A leaked screenshot of
        // it must not also be a set of working tickets.
        $this->assertArrayNotHasKey('code', $body);
    }

    public function test_arrivals_are_reflected_as_people_come_in(): void
    {
        $ticket = $this->guest('Ada Okafor', 'ada@example.com');
        $this->guest('Chidi Nwosu', 'chidi@example.com');

        app(CheckInService::class)->scan($ticket->code, $this->event->id);

        $this->asOrganizer($this->member(Role::Manager));

        $this->getJson("/api/organizer/events/{$this->event->id}/guests")
            ->assertOk()
            ->assertJsonPath('meta.checked_in', 1);
    }

    public function test_the_list_can_be_searched_by_name_or_email(): void
    {
        $this->guest('Ada Okafor', 'ada@example.com');
        $this->guest('Chidi Nwosu', 'chidi@example.com');

        $this->asOrganizer($this->member(Role::Manager));

        // Typed at a door, under time pressure, in whatever case.
        $this->getJson("/api/organizer/events/{$this->event->id}/guests?q=OKAFOR")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ada Okafor');

        $this->getJson("/api/organizer/events/{$this->event->id}/guests?q=chidi@")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_door_staff_cannot_read_the_guest_list(): void
    {
        $this->guest('Ada Okafor', 'ada@example.com');

        $this->asOrganizer($this->member(Role::Door));

        // Scanning is a separate ability. Somebody handed a phone for one night
        // is not also handed every attendee name and address.
        $this->getJson("/api/organizer/events/{$this->event->id}/guests")
            ->assertForbidden();
    }

    // --- codes -------------------------------------------------------------

    public function test_a_percentage_code_can_be_created(): void
    {
        $this->asOrganizer($this->member(Role::Marketing));

        $this->postJson("/api/organizer/events/{$this->event->id}/codes", [
            'code' => 'EARLY20',
            'discount_type' => 'percentage',
            'discount_value' => 2000,
        ])
            ->assertCreated()
            ->assertJsonPath('code', 'EARLY20')
            ->assertJsonPath('discount_currency', null)
            ->assertJsonPath('usable', true);
    }

    public function test_a_fixed_code_takes_the_event_currency(): void
    {
        $this->asOrganizer($this->member(Role::Marketing));

        $this->postJson("/api/organizer/events/{$this->event->id}/codes", [
            'code' => 'TENOFF',
            'discount_type' => 'fixed',
            'discount_value' => 1000,
        ])
            ->assertCreated()
            // A fixed amount without a currency means nothing, and one in the
            // wrong currency cannot apply to this event at all.
            ->assertJsonPath('discount_currency', 'CAD');
    }

    public function test_a_code_that_does_nothing_is_refused(): void
    {
        $this->asOrganizer($this->member(Role::Marketing));

        $this->postJson("/api/organizer/events/{$this->event->id}/codes", ['code' => 'NOOP'])
            ->assertStatus(422);
    }

    public function test_a_percentage_over_one_hundred_is_refused(): void
    {
        $this->asOrganizer($this->member(Role::Marketing));

        $this->postJson("/api/organizer/events/{$this->event->id}/codes", [
            'code' => 'TOOMUCH',
            'discount_type' => 'percentage',
            'discount_value' => 15000,
        ])->assertStatus(422);
    }

    public function test_an_attribution_only_code_needs_no_discount(): void
    {
        $this->asOrganizer($this->member(Role::Marketing));

        // How a promoter is credited without changing the price.
        $this->postJson("/api/organizer/events/{$this->event->id}/codes", [
            'code' => 'ADE',
            'ref_slug' => 'ade',
            'promoter_name' => 'Ade',
        ])
            ->assertCreated()
            ->assertJsonPath('discount_type', null)
            ->assertJsonPath('ref_slug', 'ade');
    }

    public function test_codes_are_unique_within_an_organization(): void
    {
        $this->asOrganizer($this->member(Role::Marketing));

        $payload = ['code' => 'SAME', 'discount_type' => 'percentage', 'discount_value' => 1000];

        $this->postJson("/api/organizer/events/{$this->event->id}/codes", $payload)->assertCreated();
        $this->postJson("/api/organizer/events/{$this->event->id}/codes", $payload)->assertStatus(422);
    }

    public function test_a_used_code_is_turned_off_rather_than_deleted(): void
    {
        $code = Code::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'code' => 'USED',
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            'redemption_count' => 3,
        ]);

        $this->asOrganizer($this->member(Role::Marketing));

        $this->deleteJson("/api/organizer/events/{$this->event->id}/codes/{$code->id}")
            ->assertOk();

        // Orders point at it. Removing it would make them impossible to explain
        // and would lose the attribution a promoter is owed for.
        $this->assertDatabaseHas('codes', ['id' => $code->id, 'is_active' => false]);
    }

    public function test_a_finance_role_cannot_create_codes(): void
    {
        $this->asOrganizer($this->member(Role::Finance));

        $this->postJson("/api/organizer/events/{$this->event->id}/codes", [
            'code' => 'NOPE',
            'discount_type' => 'percentage',
            'discount_value' => 1000,
        ])->assertForbidden();
    }
}
