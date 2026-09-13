<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Exceptions\CheckoutException;
use App\Models\Code;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\TicketIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Early Bird, then Tier 1 the moment Early Bird is gone.
 */
class PriceLadderTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $early;

    private TicketType $tier1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->event = Event::factory()->published()->create(['organization_id' => $this->org->id, 'slug' => 'afro-fest']);

        $this->early = TicketType::create(['event_id' => $this->event->id, 'name' => 'Early Bird', 'price_amount' => 2000, 'quantity_available' => 2, 'status' => 'on_sale', 'sort_order' => 1]);
        $this->tier1 = TicketType::create(['event_id' => $this->event->id, 'name' => 'Tier 1', 'price_amount' => 3000, 'status' => 'on_sale', 'opens_after_id' => $this->early->id, 'sort_order' => 2]);
    }

    private function quote(TicketType $type, ?string $access = null)
    {
        return app(CheckoutService::class)->quote($this->event, [$type->id => 1], null, null, $access);
    }

    private function sellEarlyBirds(int $count): void
    {
        foreach (range(1, $count) as $n) {
            app(TicketIssuer::class)->issueComp($this->event->id, $this->early->id, "guest{$n}@example.com", "Guest {$n}");
        }
    }

    public function test_the_next_tier_waits_while_the_first_has_places(): void
    {
        $this->expectExceptionMessage('Tier 1 opens when Early Bird sells out.');

        $this->quote($this->tier1);
    }

    public function test_it_opens_when_the_first_sells_out(): void
    {
        $this->sellEarlyBirds(2);

        $this->assertSame(3000, $this->quote($this->tier1)->subtotal->amount);

        // And Early Bird itself is now refused as sold out.
        $this->expectException(CheckoutException::class);
        app(CheckoutService::class)->reserve($this->event, [$this->early->id => 1], 'late@example.com', 'Late');
    }

    public function test_the_last_places_in_baskets_open_the_next_tier(): void
    {
        $this->sellEarlyBirds(1);
        app(CheckoutService::class)->reserve($this->event, [$this->early->id => 1], 'buying@example.com', 'Buying');

        $this->assertSame(3000, $this->quote($this->tier1)->subtotal->amount);
    }

    public function test_closing_or_ending_the_first_tier_opens_the_next(): void
    {
        $this->early->update(['status' => 'closed']);
        $this->assertSame(3000, $this->quote($this->tier1)->subtotal->amount);

        $this->early->update(['status' => 'on_sale', 'sales_end_at' => now()->subMinute()]);
        $this->assertSame(3000, $this->quote($this->tier1->fresh())->subtotal->amount);
    }

    public function test_a_presale_code_can_open_a_waiting_tier_early(): void
    {
        $code = Code::create(['organization_id' => $this->org->id, 'event_id' => $this->event->id, 'code' => 'VIPLIST', 'unlocks_tickets' => true, 'is_active' => true]);
        $code->unlocks()->sync([$this->tier1->id]);

        $this->assertSame(3000, $this->quote($this->tier1, 'VIPLIST')->subtotal->amount);
    }

    public function test_the_event_page_says_what_is_waiting_and_what_is_gone(): void
    {
        $tiers = collect($this->getJson('/api/events/afro-fest')->assertOk()->json('data.ticket_types'))->keyBy('name');

        $this->assertTrue($tiers['Tier 1']['waiting']);
        $this->assertSame('Early Bird', $tiers['Tier 1']['opens_after']['name']);
        $this->assertFalse($tiers['Early Bird']['sold_out']);

        $this->sellEarlyBirds(2);

        $tiers = collect($this->getJson('/api/events/afro-fest')->json('data.ticket_types'))->keyBy('name');

        // It used to look on sale until checkout refused it.
        $this->assertTrue($tiers['Early Bird']['sold_out']);
        $this->assertFalse($tiers['Tier 1']['waiting']);
    }

    // --- setting it up ------------------------------------------------------------

    private function asOwner(): void
    {
        $owner = User::factory()->create();
        $this->org->members()->attach($owner->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);
        Sanctum::actingAs($owner->fresh()->load('organizations'), [TokenAbility::Organizer->value]);
    }

    public function test_an_organizer_builds_a_ladder_and_cannot_make_a_loop(): void
    {
        $this->asOwner();

        $tier2 = $this->postJson("/api/organizer/events/{$this->event->id}/ticket-types", [
            'name' => 'Tier 2', 'price_amount' => 4000, 'opens_after_id' => $this->tier1->id,
        ])->assertCreated()->assertJsonPath('opens_after.name', 'Tier 1')->json('id');

        // Early Bird after Tier 2 would close the loop: none would ever open.
        $this->patchJson("/api/organizer/events/{$this->event->id}/ticket-types/{$this->early->id}", ['opens_after_id' => $tier2])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'wait for each other'));

        $this->patchJson("/api/organizer/events/{$this->event->id}/ticket-types/{$this->early->id}", ['opens_after_id' => $this->early->id])
            ->assertStatus(422);
    }

    public function test_a_tier_from_another_event_cannot_be_waited_for(): void
    {
        $this->asOwner();
        $elsewhere = TicketType::create(['event_id' => Event::factory()->create(['organization_id' => $this->org->id])->id, 'name' => 'Other', 'price_amount' => 100, 'status' => 'on_sale']);

        $this->postJson("/api/organizer/events/{$this->event->id}/ticket-types", [
            'name' => 'Stray', 'price_amount' => 100, 'opens_after_id' => $elsewhere->id,
        ])->assertStatus(422)->assertJsonValidationErrors('opens_after_id');
    }
}
