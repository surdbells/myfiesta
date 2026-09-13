<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Services\Legacy\LegacyRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Categories come from one list, and the old platform's ids map onto it.
 */
class EventCategoryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $owner = User::factory()->create();
        $this->org->members()->attach($owner->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);
        Sanctum::actingAs($owner->fresh()->load('organizations'), [TokenAbility::Organizer->value]);
    }

    private function create(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/organizer/events', array_merge([
            'organization_id' => $this->org->id,
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth()->toIso8601String(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
        ], $overrides));
    }

    public function test_the_list_is_served_for_the_dropdown(): void
    {
        $this->getJson('/api/event-categories')
            ->assertOk()
            ->assertJsonPath('data', config('events.categories'));
    }

    public function test_an_event_is_filed_under_a_listed_category(): void
    {
        $this->create(['category' => 'Food & drink'])->assertCreated()->assertJsonPath('category', 'Food & drink');
        $this->create(['category' => null])->assertCreated();
    }

    public function test_a_typed_category_off_the_list_is_refused(): void
    {
        // What free text produced: the same night filed four ways.
        $this->create(['category' => 'night life'])->assertStatus(422)->assertJsonValidationErrors('category');
    }

    public function test_an_event_filed_off_the_list_keeps_its_category_but_cannot_move_to_another(): void
    {
        $event = Event::factory()->create(['organization_id' => $this->org->id, 'category' => 'Afro House']);

        $this->patchJson("/api/organizer/events/{$event->id}", ['category' => 'Afro House', 'title' => 'Renamed'])->assertOk();
        $this->patchJson("/api/organizer/events/{$event->id}", ['category' => 'Something Else'])->assertStatus(422);
        $this->patchJson("/api/organizer/events/{$event->id}", ['category' => 'Music'])->assertOk()->assertJsonPath('category', 'Music');
    }

    public function test_the_old_platforms_category_ids_become_names(): void
    {
        // Its events stored the event_types id, and the importer copied it:
        // imported events would have been filed under "2" and "8".
        $this->assertSame('Concert', LegacyRules::category('2'));
        $this->assertSame('Party', LegacyRules::category(8));
        $this->assertSame('Performing arts', LegacyRules::category('14'));
        $this->assertNull(LegacyRules::category('15'), 'Standard (Couple) was a ticket type, not a category.');
        $this->assertNull(LegacyRules::category('99'));
        $this->assertSame('Nightlife', LegacyRules::category(' nightlife '));
        $this->assertNull(LegacyRules::category(''));
    }
}
