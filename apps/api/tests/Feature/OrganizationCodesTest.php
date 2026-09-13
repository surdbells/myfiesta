<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Code;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Discount codes screen: every code in the organization, across events.
 */
class OrganizationCodesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $fest;

    private Event $brunch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->fest = $this->event('afro-fest', 'Afro Fest');
        $this->brunch = $this->event('day-party', 'Day Party');
    }

    private function event(string $slug, string $title, ?Organization $org = null): Event
    {
        return Event::create([
            'organization_id' => ($org ?? $this->org)->id,
            'slug' => $slug,
            'title' => $title,
            'currency' => 'CAD',
            'starts_at' => now()->addWeek(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);
    }

    private function code(string $code, ?Event $event, array $attributes = []): Code
    {
        return Code::create([
            'organization_id' => $this->org->id,
            'event_id' => $event?->id,
            'code' => $code,
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            ...$attributes,
        ]);
    }

    private function actAs(Role $role, ?Organization $org = null): void
    {
        $user = User::factory()->create();
        ($org ?? $this->org)->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role->value, 'accepted_at' => now()]);
        Sanctum::actingAs($user->fresh()->load('organizations'), [TokenAbility::Organizer->value]);
    }

    public function test_it_lists_codes_across_events_with_the_event_they_belong_to(): void
    {
        $this->actAs(Role::Marketing);

        $this->code('FEST10', $this->fest, ['label' => 'Instagram']);
        $this->code('BRUNCH', $this->brunch, ['promoter_name' => 'Ade']);
        $this->code('EVERYWHERE', null);

        $response = $this->getJson('/api/organizer/codes')->assertOk();

        $rows = collect($response->json('data'))->keyBy('code');

        $this->assertSame(['EVERYWHERE', 'BRUNCH', 'FEST10'], $rows->keys()->all());
        $this->assertSame('Afro Fest', $rows['FEST10']['event']['title']);
        $this->assertNull($rows['EVERYWHERE']['event']);
        $this->assertFalse($rows['EVERYWHERE']['event_scoped']);
        $this->assertTrue($rows['EVERYWHERE']['usable']);
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_it_filters_by_event_by_all_events_and_by_search(): void
    {
        $this->actAs(Role::Manager);

        $this->code('FEST10', $this->fest, ['label' => 'Instagram']);
        $this->code('BRUNCH', $this->brunch, ['promoter_name' => 'Ade Bello']);
        $this->code('EVERYWHERE', null);

        $codes = fn (string $query) => collect($this->getJson('/api/organizer/codes?'.$query)->assertOk()->json('data'))->pluck('code')->all();

        $this->assertSame(['FEST10'], $codes('event_id='.$this->fest->id));
        $this->assertSame(['EVERYWHERE'], $codes('event_id=all-events'));
        $this->assertSame(['FEST10'], $codes('q=insta'));
        $this->assertSame(['BRUNCH'], $codes('q=bello'));
        // A wildcard typed into search is a character, not a pattern.
        $this->assertSame([], $codes('q=%25'));
    }

    public function test_batch_codes_are_left_to_their_batch(): void
    {
        $this->actAs(Role::Owner);

        $batch = \App\Models\CodeBatch::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->fest->id,
            'name' => 'Giveaway',
            'prefix' => 'GIFT',
            'quantity' => 1,
            'discount_type' => 'percentage',
            'discount_value' => 10000,
        ]);
        $this->code('GIFT-ABC234', $this->fest, ['batch_id' => $batch->id, 'max_redemptions' => 1]);
        $this->code('FEST10', $this->fest);

        $this->assertSame(['FEST10'], collect($this->getJson('/api/organizer/codes')->json('data'))->pluck('code')->all());
    }

    public function test_only_this_organizations_codes_and_only_for_people_who_manage_codes(): void
    {
        $this->code('FEST10', $this->fest);

        $rival = Organization::create(['name' => 'Rival', 'slug' => 'rival']);
        $this->actAs(Role::Owner, $rival);
        $this->assertSame([], $this->getJson('/api/organizer/codes')->assertOk()->json('data'));

        // Naming an organization you are not in gets you nothing either.
        $this->getJson('/api/organizer/codes', ['X-Organization' => $this->org->id])->assertForbidden();

        foreach ([Role::Finance, Role::Door] as $role) {
            $this->actAs($role);
            $this->getJson('/api/organizer/codes', ['X-Organization' => $this->org->id])->assertForbidden();
        }
    }
}
