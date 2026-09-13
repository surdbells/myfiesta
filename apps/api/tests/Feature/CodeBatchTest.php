<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Exceptions\CheckoutException;
use App\Models\AuditLog;
use App\Models\Code;
use App\Models\CodeBatch;
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
 * Single-use codes, made in bulk.
 */
class CodeBatchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $general;

    private TicketType $vip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->event = Event::factory()->published()->create(['organization_id' => $this->org->id, 'title' => 'Afro Fest']);
        $this->general = TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 5000, 'status' => 'on_sale']);
        $this->vip = TicketType::create(['event_id' => $this->event->id, 'name' => 'VIP', 'price_amount' => 15000, 'status' => 'hidden']);

        $owner = User::factory()->create();
        $this->org->members()->attach($owner->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);
        Sanctum::actingAs($owner->fresh()->load('organizations'), [TokenAbility::Organizer->value]);
    }

    private function make(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/organizer/events/{$this->event->id}/code-batches", array_merge([
            'name' => 'Sponsor giveaway',
            'quantity' => 50,
            'discount_type' => 'percentage',
            'discount_value' => 10000,
        ], $overrides));
    }

    public function test_a_batch_makes_that_many_distinct_single_use_codes(): void
    {
        $batch = $this->make()->assertCreated()
            ->assertJsonPath('quantity', 50)
            ->assertJsonPath('prefix', 'SPONSOR')
            ->assertJsonPath('used', 0)
            ->json();

        $codes = Code::where('batch_id', $batch['id'])->get();

        $this->assertCount(50, $codes);
        $this->assertCount(50, $codes->pluck('code')->unique());
        $this->assertTrue($codes->every(fn (Code $c) => $c->max_redemptions === 1));
        // Nothing that reads as something else on a printed card.
        $this->assertTrue($codes->every(fn (Code $c) => preg_match('/^SPONSOR-[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{6}$/', $c->code) === 1));
    }

    public function test_each_code_works_once(): void
    {
        $this->make(['quantity' => 2]);
        $value = Code::whereNotNull('batch_id')->value('code');

        $order = app(CheckoutService::class)->reserve($this->event, [$this->general->id => 1], 'winner@example.com', 'Winner', $value);
        $this->assertSame(0, $order->total_amount, 'A 100% giveaway code covers the ticket.');

        $this->expectException(CheckoutException::class);

        app(CheckoutService::class)->reserve($this->event, [$this->general->id => 1], 'friend@example.com', 'Friend', $value);
    }

    public function test_a_batch_can_unlock_a_hidden_tier(): void
    {
        $this->make(['name' => 'Staff', 'quantity' => 3, 'discount_type' => null, 'discount_value' => null, 'unlock_ticket_type_ids' => [$this->vip->id]])
            ->assertCreated()
            ->assertJsonPath('prefix', 'STAFF');

        $value = Code::whereNotNull('batch_id')->value('code');

        $quote = app(CheckoutService::class)->quote($this->event, [$this->vip->id => 1], null, null, $value);

        $this->assertSame(15000, $quote->subtotal->amount);
    }

    public function test_batch_codes_stay_out_of_the_codes_list(): void
    {
        $this->make(['quantity' => 30]);

        $this->getJson("/api/organizer/events/{$this->event->id}/codes")->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/organizer/events/{$this->event->id}/code-batches")->assertOk()->assertJsonPath('data.0.quantity', 30);
    }

    public function test_the_spreadsheet_lists_every_code_and_which_were_used(): void
    {
        $batchId = $this->make(['quantity' => 3])->json('id');
        $used = Code::where('batch_id', $batchId)->orderBy('code')->first();

        $order = app(CheckoutService::class)->reserve($this->event, [$this->general->id => 1], 'winner@example.com', 'Winner', $used->code);
        app(Fulfiller::class)->fulfilFree($order);

        $csv = $this->get("/api/organizer/events/{$this->event->id}/code-batches/{$batchId}/export")
            ->assertOk()
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv)))));

        $this->assertSame('Code,Status,"Order reference"', trim($lines[0]));
        $this->assertCount(4, $lines);
        $this->assertStringContainsString("{$used->code},Used,{$order->reference}", $csv);
        $this->assertSame(2, substr_count($csv, ',Unused,'));
        $this->assertSame(1, AuditLog::where('action', 'code_batch.exported')->count());

        $this->getJson("/api/organizer/events/{$this->event->id}/code-batches")->assertJsonPath('data.0.used', 1);
    }

    public function test_turning_a_batch_off_stops_only_the_unused_codes(): void
    {
        $batchId = $this->make(['quantity' => 3])->json('id');
        $used = Code::where('batch_id', $batchId)->first();
        $order = app(CheckoutService::class)->reserve($this->event, [$this->general->id => 1], 'winner@example.com', 'Winner', $used->code);
        app(Fulfiller::class)->fulfilFree($order);

        $this->postJson("/api/organizer/events/{$this->event->id}/code-batches/{$batchId}/deactivate")
            ->assertOk()
            ->assertJsonPath('message', 'Turned off 2 unused codes.');

        $this->assertTrue($used->fresh()->is_active, 'A used code keeps its record as it was.');
        $this->assertSame(2, Code::where('batch_id', $batchId)->where('is_active', false)->count());
    }

    public function test_a_batch_must_do_something_and_has_a_ceiling(): void
    {
        $this->make(['discount_type' => null, 'discount_value' => null])->assertStatus(422);
        $this->make(['quantity' => 1001])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->make(['prefix' => 'no-dashes'])->assertStatus(422)->assertJsonValidationErrors('prefix');
    }

    public function test_another_events_batch_cannot_be_reached_through_this_one(): void
    {
        $other = Event::factory()->published()->create(['organization_id' => $this->org->id]);
        $batch = CodeBatch::create(['organization_id' => $this->org->id, 'event_id' => $other->id, 'name' => 'Theirs', 'prefix' => 'X', 'quantity' => 0]);

        $this->get("/api/organizer/events/{$this->event->id}/code-batches/{$batch->id}/export")->assertNotFound();
        $this->postJson("/api/organizer/events/{$this->event->id}/code-batches/{$batch->id}/deactivate")->assertNotFound();
    }

    public function test_a_thousand_codes_are_made_in_one_request(): void
    {
        $this->make(['quantity' => 1000])->assertCreated();

        $this->assertSame(1000, Code::whereNotNull('batch_id')->distinct('code')->count('code'));
    }
}
