<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Code;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Managing an event once it exists: the order tiers are offered in, and
 * changing a code that is already on a poster.
 *
 * Both of these were reachable from the database and not from the console —
 * sort_order was accepted and never sent, and a code could only be recreated,
 * which threw away everything it had sold.
 */
class EventManagementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afrobeats-rooftop',
            'title' => 'Afrobeats Rooftop',
            'currency' => 'CAD',
            'starts_at' => now()->addWeek(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);
    }

    private function signedInAs(Role $role): User
    {
        $user = User::factory()->create();

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        return $user;
    }

    private function tier(string $name, int $sort = 0): TicketType
    {
        return TicketType::create([
            'event_id' => $this->event->id,
            'name' => $name,
            'price_amount' => 5000,
            'status' => 'on_sale',
            'quantity_available' => 50,
            'sort_order' => $sort,
        ]);
    }

    // --- the order tiers are offered in --------------------------------------

    public function test_tiers_come_back_in_the_order_they_were_put_in(): void
    {
        $this->signedInAs(Role::Owner);

        $general = $this->tier('General', 0);
        $early = $this->tier('Early Bird', 1);
        $vip = $this->tier('VIP', 2);

        $response = $this->postJson("/api/organizer/events/{$this->event->id}/ticket-types/order", [
            'ids' => [$early->id, $general->id, $vip->id],
        ])->assertOk();

        // Early Bird above General reads as a deadline; the other way round
        // reads as a list. That is why this is settable at all.
        $this->assertSame(
            ['Early Bird', 'General', 'VIP'],
            array_column($response->json('data'), 'name'),
        );

        $this->assertSame(0, $early->refresh()->sort_order);
        $this->assertSame(1, $general->refresh()->sort_order);

        // And it survives a plain read.
        $this->assertSame(
            ['Early Bird', 'General', 'VIP'],
            array_column(
                $this->getJson("/api/organizer/events/{$this->event->id}/ticket-types")->json('data'),
                'name',
            ),
        );
    }

    public function test_a_tier_from_another_event_is_refused_rather_than_skipped(): void
    {
        $this->signedInAs(Role::Owner);

        $mine = $this->tier('General');

        $other = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'other-night',
            'title' => 'Other Night',
            'currency' => 'CAD',
            'starts_at' => now()->addWeeks(2),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $theirs = TicketType::create([
            'event_id' => $other->id,
            'name' => 'Theirs',
            'price_amount' => 1000,
            'status' => 'on_sale',
        ]);

        // Silently ignoring it would let this endpoint confirm which ids exist.
        $this->postJson("/api/organizer/events/{$this->event->id}/ticket-types/order", [
            'ids' => [$theirs->id, $mine->id],
        ])->assertStatus(422);

        $this->assertSame(0, $mine->refresh()->sort_order);
    }

    public function test_door_staff_cannot_reorder_what_is_on_sale(): void
    {
        $this->signedInAs(Role::Door);
        $tier = $this->tier('General');

        $this->postJson("/api/organizer/events/{$this->event->id}/ticket-types/order", [
            'ids' => [$tier->id],
        ])->assertForbidden();
    }

    public function test_a_tier_can_be_taken_off_sale_without_being_deleted(): void
    {
        $this->signedInAs(Role::Owner);
        $tier = $this->tier('VIP');

        $this->patchJson(
            "/api/organizer/events/{$this->event->id}/ticket-types/{$tier->id}",
            ['status' => 'hidden'],
        )->assertOk();

        $this->assertSame('hidden', $tier->refresh()->status);
    }

    // --- editing a code that is already out there ----------------------------

    private function code(array $attributes = []): Code
    {
        return Code::create(array_merge([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'code' => 'EARLYBIRD',
            'discount_type' => 'percentage',
            'discount_value' => 1500,
            'promoter_name' => 'Tobi',
            'ref_slug' => 'tobi',
            'redemption_count' => 7,
            'is_active' => true,
        ], $attributes));
    }

    public function test_editing_a_code_keeps_everything_it_has_already_sold(): void
    {
        $this->signedInAs(Role::Owner);
        $code = $this->code();

        $until = now()->addDays(3);

        $this->patchJson("/api/organizer/events/{$this->event->id}/codes/{$code->id}", [
            'promoter_name' => 'Tobi Adeyemi',
            'ends_at' => $until->toIso8601String(),
        ])->assertOk();

        $code->refresh();

        $this->assertSame('Tobi Adeyemi', $code->promoter_name);
        $this->assertSame($until->toDateString(), $code->ends_at->toDateString());
        // The whole reason editing exists rather than delete-and-recreate.
        $this->assertSame(7, $code->redemption_count);
    }

    public function test_the_code_itself_cannot_be_renamed(): void
    {
        $this->signedInAs(Role::Owner);
        $code = $this->code();

        $this->patchJson("/api/organizer/events/{$this->event->id}/codes/{$code->id}", [
            'code' => 'SOMETHINGELSE',
            'label' => 'Radio giveaway',
        ])->assertOk();

        // It is on posters and in screenshots. The label changed; the code did
        // not, and the request did not have to fail to say so.
        $this->assertSame('EARLYBIRD', $code->refresh()->code);
        $this->assertSame('Radio giveaway', $code->label);
    }

    public function test_an_edit_cannot_leave_a_code_doing_nothing(): void
    {
        $this->signedInAs(Role::Owner);
        // Discount only — no tracking slug to fall back on.
        $code = $this->code(['ref_slug' => null, 'promoter_name' => null]);

        $this->patchJson("/api/organizer/events/{$this->event->id}/codes/{$code->id}", [
            'discount_type' => null,
            'discount_value' => null,
        ])->assertStatus(422);

        $this->assertSame('percentage', $code->refresh()->discount_type);
    }

    public function test_an_edit_is_checked_against_the_code_as_it_will_be(): void
    {
        $this->signedInAs(Role::Owner);
        $code = $this->code();

        // Only the value is sent. The type comes from what is stored, so this
        // has to be caught as a percentage over 100% rather than waved through
        // because 'discount_type' was absent from the request.
        $this->patchJson("/api/organizer/events/{$this->event->id}/codes/{$code->id}", [
            'discount_value' => 12000,
        ])->assertStatus(422);

        $this->assertSame(1500, $code->refresh()->discount_value);
    }

    public function test_a_window_that_ends_before_it_starts_is_refused(): void
    {
        $this->signedInAs(Role::Owner);
        $code = $this->code();

        $this->patchJson("/api/organizer/events/{$this->event->id}/codes/{$code->id}", [
            'starts_at' => now()->addDays(5)->toIso8601String(),
            'ends_at' => now()->addDays(2)->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_becoming_a_fixed_amount_takes_the_events_currency(): void
    {
        $this->signedInAs(Role::Owner);
        $code = $this->code();

        $this->patchJson("/api/organizer/events/{$this->event->id}/codes/{$code->id}", [
            'discount_type' => 'fixed',
            'discount_value' => 500,
        ])->assertOk();

        // An amount carries its currency; a percentage must not.
        $this->assertSame('CAD', $code->refresh()->discount_currency);

        $this->patchJson("/api/organizer/events/{$this->event->id}/codes/{$code->id}", [
            'discount_type' => 'percentage',
            'discount_value' => 1000,
        ])->assertOk();

        $this->assertNull($code->refresh()->discount_currency);
    }

    public function test_the_window_comes_back_so_the_console_can_show_it(): void
    {
        $this->signedInAs(Role::Owner);
        $this->code(['ends_at' => now()->addDay()]);

        $listed = $this->getJson("/api/organizer/events/{$this->event->id}/codes")->json('data.0');

        // It was enforced at checkout and absent from this response, which
        // made a window something a code could have and never show.
        $this->assertArrayHasKey('ends_at', $listed);
        $this->assertNotNull($listed['ends_at']);
        $this->assertArrayHasKey('max_per_customer', $listed);
    }

    public function test_a_code_from_another_organization_is_not_found(): void
    {
        $this->signedInAs(Role::Owner);

        $stranger = Organization::create(['name' => 'Other Co', 'slug' => 'other-co']);
        $theirs = Code::create([
            'organization_id' => $stranger->id,
            'code' => 'NOTYOURS',
            'discount_type' => 'percentage',
            'discount_value' => 5000,
            'is_active' => true,
        ]);

        $this->patchJson("/api/organizer/events/{$this->event->id}/codes/{$theirs->id}", [
            'label' => 'Mine now',
        ])->assertNotFound();

        $this->assertNull($theirs->refresh()->label);
    }
}
