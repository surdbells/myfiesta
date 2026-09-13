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
use App\Services\Checkout\Fulfiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Presale: tiers that only a code opens.
 *
 * Before this a hidden tier could not be bought by anybody — checkout refused
 * anything not on sale — and sales dates were stored and never enforced.
 */
class AccessCodeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $general;

    private TicketType $listOnly;

    private TicketType $earlyAccess;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->event = Event::factory()->published()->create(['organization_id' => $this->org->id, 'slug' => 'afro-fest']);

        $this->general = TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 5000, 'status' => 'on_sale', 'sort_order' => 1]);
        // Hidden: for the list, never shown publicly.
        $this->listOnly = TicketType::create(['event_id' => $this->event->id, 'name' => 'Guest list', 'price_amount' => 2000, 'status' => 'hidden', 'sort_order' => 2]);
        // On sale to everyone from next week; a presale before that.
        $this->earlyAccess = TicketType::create(['event_id' => $this->event->id, 'name' => 'Early access', 'price_amount' => 3000, 'status' => 'on_sale', 'sales_start_at' => now()->addWeek(), 'sort_order' => 3]);
    }

    private function accessCode(array $attributes = [], ?array $unlocks = null): Code
    {
        $code = Code::create(array_merge([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'code' => 'THELIST',
            'unlocks_tickets' => true,
            'is_active' => true,
        ], $attributes));

        $code->unlocks()->sync($unlocks ?? [$this->listOnly->id, $this->earlyAccess->id]);

        return $code;
    }

    private function quote(array $quantities, ?string $access = null, ?string $code = null)
    {
        return app(CheckoutService::class)->quote($this->event, $quantities, $code, null, $access);
    }

    private function reserve(array $quantities, string $email = 'ada@example.com', ?string $access = 'THELIST')
    {
        return app(CheckoutService::class)->reserve($this->event, $quantities, $email, 'Ada', null, null, null, null, $access);
    }

    // --- locked tiers -----------------------------------------------------------

    public function test_a_hidden_tier_is_refused_without_its_code_and_without_admitting_it_exists(): void
    {
        $this->expectExceptionMessage('That ticket is not on sale for this event.');

        $this->quote([$this->listOnly->id => 1]);
    }

    public function test_a_hidden_tier_can_be_bought_with_the_code_that_unlocks_it(): void
    {
        $code = $this->accessCode();

        $order = $this->reserve([$this->listOnly->id => 2]);

        $this->assertSame($code->id, $order->access_code_id);
        $this->assertSame(4000, $order->subtotal_amount);
    }

    public function test_a_tier_before_its_sales_open_says_when_and_opens_early_with_a_code(): void
    {
        $this->accessCode();

        try {
            $this->quote([$this->earlyAccess->id => 1]);
            $this->fail('A tier sold before its sales opened.');
        } catch (CheckoutException $e) {
            $this->assertStringContainsString('Early access goes on sale', $e->getMessage());
        }

        $this->assertSame(3000, $this->quote([$this->earlyAccess->id => 1], 'thelist')->subtotal->amount);
    }

    public function test_a_code_does_not_open_a_tier_it_does_not_name(): void
    {
        $this->accessCode(unlocks: [$this->earlyAccess->id]);

        $this->expectException(CheckoutException::class);

        $this->quote([$this->listOnly->id => 1], 'THELIST');
    }

    public function test_sales_that_have_ended_stay_ended_code_or_not(): void
    {
        $this->general->update(['sales_end_at' => now()->subHour()]);
        $this->accessCode(unlocks: [$this->general->id]);

        // The end date was stored and ignored; this tier kept selling.
        $this->expectExceptionMessage('Sales for General have ended.');

        $this->quote([$this->general->id => 1], 'THELIST');
    }

    public function test_an_access_code_that_is_wrong_says_so(): void
    {
        $this->expectExceptionMessage('That access code is not valid for this event.');

        $this->quote([$this->general->id => 1], 'NOPE');
    }

    public function test_a_discount_code_that_also_unlocks_needs_typing_once(): void
    {
        $this->accessCode(['code' => 'PRESALE10', 'discount_type' => 'percentage', 'discount_value' => 1000]);

        $quote = $this->quote([$this->listOnly->id => 1], null, 'PRESALE10');

        $this->assertSame(200, $quote->discount->amount);
        $this->assertSame('PRESALE10', $quote->accessCode?->code);
    }

    public function test_holding_a_presale_code_while_buying_public_tickets_uses_nothing(): void
    {
        $code = $this->accessCode(['max_redemptions' => 1]);

        $order = $this->reserve([$this->general->id => 1], 'public@example.com');

        $this->assertNull($order->access_code_id);
        $this->assertSame(0, $code->usesInFlight(20));
    }

    // --- limits -----------------------------------------------------------------

    public function test_a_presale_for_the_first_so_many_stops_there(): void
    {
        $this->accessCode(['max_redemptions' => 1]);

        $this->reserve([$this->listOnly->id => 1], 'first@example.com');

        $this->expectExceptionMessage('fully redeemed');

        $this->reserve([$this->listOnly->id => 1], 'second@example.com');
    }

    public function test_paid_presale_orders_count_as_uses(): void
    {
        $code = $this->accessCode();

        $order = $this->reserve([$this->listOnly->id => 1]);
        $order->update(['gateway' => 'stripe']);
        app(Fulfiller::class)->fulfil($order->refresh());

        $this->assertSame(1, $code->fresh()->redemption_count);
    }

    // --- the public page -----------------------------------------------------------

    public function test_the_event_page_does_not_list_a_hidden_tier(): void
    {
        $names = collect($this->getJson('/api/events/afro-fest')->assertOk()->json('data.ticket_types'))->pluck('name');

        $this->assertNotContains('Guest list', $names);
        $this->assertContains('Early access', $names);
    }

    public function test_typing_the_code_reveals_what_it_opens(): void
    {
        $this->accessCode();

        $this->postJson('/api/events/afro-fest/access', ['code' => 'thelist'])
            ->assertOk()
            ->assertJsonPath('code', 'THELIST')
            ->assertJsonPath('ticket_types.0.name', 'Guest list')
            ->assertJsonPath('ticket_types.1.name', 'Early access');

        $this->postJson('/api/events/afro-fest/access', ['code' => 'WRONG'])->assertStatus(422);
    }

    public function test_a_code_with_nothing_left_to_open_says_so_rather_than_invalid(): void
    {
        $this->listOnly->update(['status' => 'closed']);
        $this->accessCode(unlocks: [$this->listOnly->id]);

        $this->postJson('/api/events/afro-fest/access', ['code' => 'THELIST'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That code has nothing left to unlock for this event.');
    }

    public function test_guessing_codes_is_throttled(): void
    {
        foreach (range(1, 10) as $i) {
            $this->postJson('/api/events/afro-fest/access', ['code' => "GUESS{$i}"])->assertStatus(422);
        }

        $this->postJson('/api/events/afro-fest/access', ['code' => 'GUESS11'])->assertStatus(429);
    }

    // --- the organizer's side ---------------------------------------------------------

    private function asOwner(): void
    {
        $owner = User::factory()->create();
        $this->org->members()->attach($owner->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);
        Sanctum::actingAs($owner->fresh()->load('organizations'), [TokenAbility::Organizer->value]);
    }

    public function test_an_organizer_makes_a_code_that_only_unlocks(): void
    {
        $this->asOwner();

        $this->postJson("/api/organizer/events/{$this->event->id}/codes", [
            'code' => 'ONTHELIST',
            'unlock_ticket_type_ids' => [$this->listOnly->id],
            'max_redemptions' => 200,
        ])->assertCreated()
            ->assertJsonPath('unlocks.0.name', 'Guest list')
            ->assertJsonPath('discount_type', null);

        $this->assertTrue(Code::where('code', 'ONTHELIST')->value('unlocks_tickets'));
    }

    public function test_a_code_that_does_nothing_is_still_refused(): void
    {
        $this->asOwner();

        $this->postJson("/api/organizer/events/{$this->event->id}/codes", ['code' => 'NOTHING'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'unlock tickets'));
    }

    public function test_removing_every_unlock_from_an_access_only_code_is_refused(): void
    {
        $this->asOwner();
        $code = $this->accessCode(unlocks: [$this->listOnly->id]);

        $this->patchJson("/api/organizer/events/{$this->event->id}/codes/{$code->id}", ['unlock_ticket_type_ids' => []])
            ->assertStatus(422);
    }

    public function test_an_all_events_code_cannot_unlock_one_events_tiers(): void
    {
        $this->asOwner();

        $this->postJson("/api/organizer/events/{$this->event->id}/codes", [
            'code' => 'EVERYWHERE', 'event_scoped' => false, 'unlock_ticket_type_ids' => [$this->listOnly->id],
        ])->assertStatus(422);
    }
}
