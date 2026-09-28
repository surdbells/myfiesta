<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Code;
use App\Models\CodeBatch;
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

        $batch = CodeBatch::create([
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

    public function test_codes_are_found_by_where_they_stand(): void
    {
        $this->actAs(Role::Marketing);

        $this->code('LIVE', $this->fest);
        $this->code('OFF', $this->fest, ['is_active' => false]);
        $this->code('GONE', $this->fest, ['max_redemptions' => 2, 'redemption_count' => 2]);
        $this->code('OVER', $this->fest, ['ends_at' => now()->subDay()]);
        $this->code('SOON', $this->fest, ['starts_at' => now()->addDay()]);

        $codes = fn (array $query) => collect($this->getJson('/api/organizer/codes?'.http_build_query($query))->assertOk()->json('data'))
            ->pluck('code')->sort()->values()->all();

        $this->assertSame(['LIVE'], $codes(['state' => 'usable']));
        $this->assertSame(['OFF'], $codes(['state' => 'paused']));
        $this->assertSame(['GONE'], $codes(['state' => 'used_up']));
        $this->assertSame(['OVER'], $codes(['state' => 'expired']));
        $this->assertSame(['SOON'], $codes(['state' => 'scheduled']));
        $this->assertSame(['GONE', 'OVER'], $codes(['state' => ['used_up', 'expired']]));

        $this->getJson('/api/organizer/codes?state=broken')->assertUnprocessable()->assertJsonValidationErrors('state');
    }

    public function test_codes_are_found_by_what_they_do(): void
    {
        $this->actAs(Role::Marketing);

        $this->code('MONEY', $this->fest);
        $this->code('ADE', $this->fest, ['discount_type' => null, 'discount_value' => null, 'ref_slug' => 'ade', 'promoter_name' => 'Ade']);
        $this->code('EARLY', $this->fest, ['discount_type' => null, 'discount_value' => null, 'unlocks_tickets' => true]);

        $codes = fn (array $query) => collect($this->getJson('/api/organizer/codes?'.http_build_query($query))->assertOk()->json('data'))
            ->pluck('code')->sort()->values()->all();

        $this->assertSame(['MONEY'], $codes(['kind' => 'discount']));
        $this->assertSame(['ADE'], $codes(['kind' => 'promoter']));
        $this->assertSame(['ADE', 'EARLY'], $codes(['kind' => ['promoter', 'presale']]));
    }

    public function test_several_events_at_once_with_or_without_the_everywhere_codes(): void
    {
        $this->actAs(Role::Marketing);

        $this->code('FEST10', $this->fest);
        $this->code('BRUNCH', $this->brunch);
        $this->code('EVERYWHERE', null);

        $codes = fn (array $query) => collect($this->getJson('/api/organizer/codes?'.http_build_query($query))->assertOk()->json('data'))
            ->pluck('code')->sort()->values()->all();

        $this->assertSame(['BRUNCH', 'FEST10'], $codes(['event_id' => [$this->fest->id, $this->brunch->id]]));
        $this->assertSame(['EVERYWHERE', 'FEST10'], $codes(['event_id' => [$this->fest->id, 'all-events']]));
        $this->assertSame(['EVERYWHERE'], $codes(['event_id' => 'all-events']));

        $this->getJson('/api/organizer/codes?event_id[]=nonsense')->assertUnprocessable()->assertJsonValidationErrors('event_id');
    }

    public function test_codes_sort_by_the_column_asked_for(): void
    {
        $this->actAs(Role::Marketing);

        $this->code('bravo', $this->fest, ['redemption_count' => 5]);
        $this->code('ALPHA', $this->fest, ['redemption_count' => 9]);
        $this->code('charlie', $this->fest, ['redemption_count' => 1]);

        $codes = fn (array $query) => collect($this->getJson('/api/organizer/codes?'.http_build_query($query))->assertOk()->json('data'))
            ->pluck('code')->all();

        // Codes are kept in capitals; the sort would ignore case regardless.
        $this->assertSame(['ALPHA', 'BRAVO', 'CHARLIE'], $codes(['sort' => 'code']));
        $this->assertSame(['CHARLIE', 'BRAVO', 'ALPHA'], $codes(['sort' => 'used']));
        $this->assertSame(['ALPHA', 'BRAVO', 'CHARLIE'], $codes(['sort' => 'used', 'dir' => 'desc']));
    }

    public function test_several_codes_go_off_and_back_on_at_once(): void
    {
        $this->actAs(Role::Marketing);

        $one = $this->code('ONE', $this->fest);
        $two = $this->code('TWO', null);
        $theirs = Code::create([
            'organization_id' => Organization::create(['name' => 'Harbour', 'slug' => 'harbour'])->id,
            'code' => 'THEIRS',
            'discount_type' => 'percentage',
            'discount_value' => 1000,
        ]);

        $this->postJson('/api/organizer/codes/active', ['ids' => [$one->id, $two->id, $theirs->id], 'active' => false])
            ->assertOk()
            ->assertJsonPath('changed', 2)
            ->assertJsonPath('skipped', []);

        $this->assertFalse($one->fresh()->is_active);
        $this->assertFalse($two->fresh()->is_active);
        // Another organization's code is not found, let alone changed.
        $this->assertTrue($theirs->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'codes.paused', 'organization_id' => $this->org->id]);

        $this->postJson('/api/organizer/codes/active', ['ids' => [$one->id], 'active' => true])
            ->assertOk()
            ->assertJsonPath('changed', 1);
        $this->assertTrue($one->fresh()->is_active);
    }

    public function test_a_code_on_an_event_in_review_is_left_and_named(): void
    {
        $this->actAs(Role::Marketing);
        $this->fest->forceFill(['status' => 'in_review', 'submitted_at' => now()])->save();

        $held = $this->code('HELD', $this->fest);
        $free = $this->code('FREE', $this->brunch);

        $this->postJson('/api/organizer/codes/active', ['ids' => [$held->id, $free->id], 'active' => false])
            ->assertOk()
            ->assertJsonPath('changed', 1)
            ->assertJsonPath('skipped.0.code', 'HELD');

        $this->assertTrue($held->fresh()->is_active);
        $this->assertFalse($free->fresh()->is_active);
    }

    public function test_turning_codes_off_needs_the_right_to_manage_them(): void
    {
        $this->actAs(Role::Door);
        $code = $this->code('ONE', $this->fest);

        $this->postJson('/api/organizer/codes/active', ['ids' => [$code->id], 'active' => false])->assertForbidden();
        $this->assertTrue($code->fresh()->is_active);
    }
}
