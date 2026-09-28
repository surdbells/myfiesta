<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Http\Controllers\Api\Organizer\SavedViewController;
use App\Models\Organization;
use App\Models\SavedView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The views somebody keeps of a list in the console.
 *
 * Theirs alone, for one organization, in a shape that is data and nothing
 * else — and a second save under the same name replaces the first.
 */
class SavedViewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->other = Organization::create(['name' => 'Harbour Club', 'slug' => 'harbour-club']);
        $this->ada = $this->member($this->org, Role::Marketing);
    }

    private function member(Organization $org, Role $role, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        $org->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role->value, 'accepted_at' => now()]);

        return $user->fresh()->load('organizations');
    }

    private function actAs(User $user, ?Organization $org = null): void
    {
        Sanctum::actingAs($user->fresh()->load('organizations'), [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);
        $this->withHeader('X-Organization', ($org ?? $this->org)->id);
    }

    private function save(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/organizer/saved-views', array_merge([
            'list' => 'orders',
            'name' => 'Refunds this week',
            'state' => ['status' => ['refunded', 'partially_refunded'], 'from' => '2026-09-21', 'sort' => 'total', 'dir' => 'desc', 'columns' => ['buyer', 'total']],
        ], $overrides));
    }

    public function test_any_member_keeps_a_view_and_reads_it_back(): void
    {
        $this->actAs($this->ada);

        $this->save()
            ->assertCreated()
            ->assertJsonPath('data.name', 'Refunds this week')
            ->assertJsonPath('data.state.status', ['refunded', 'partially_refunded'])
            ->assertJsonPath('data.state.sort', 'total');

        $this->getJson('/api/organizer/saved-views?list=orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.list', 'orders');

        $this->getJson('/api/organizer/saved-views?list=codes')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_saving_a_name_again_replaces_that_view(): void
    {
        $this->actAs($this->ada);

        $this->save()->assertCreated();
        $this->save(['name' => '  Refunds   this week ', 'state' => ['q' => 'ada']])
            ->assertOk()
            ->assertJsonPath('data.state.q', 'ada');

        $this->assertSame(1, SavedView::query()->count());
        $this->assertSame(['q' => 'ada'], SavedView::query()->first()->state);
    }

    public function test_an_empty_state_comes_back_as_an_object(): void
    {
        $this->actAs($this->ada);

        $this->save(['state' => []])->assertCreated();

        $this->assertStringContainsString('"state":{}', $this->getJson('/api/organizer/saved-views?list=orders')->getContent());
    }

    public function test_views_belong_to_one_person_and_one_organization(): void
    {
        $this->actAs($this->ada);
        $id = $this->save()->json('data.id');

        // A teammate sees none of Ada's views, and cannot delete them.
        $bisi = $this->member($this->org, Role::Owner);
        $this->actAs($bisi);
        $this->getJson('/api/organizer/saved-views?list=orders')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/organizer/saved-views/{$id}")->assertNotFound();

        // Ada, reading her other organization, sees none of this one's.
        $this->member($this->other, Role::Owner, $this->ada);
        $this->actAs($this->ada, $this->other);
        $this->getJson('/api/organizer/saved-views?list=orders')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/organizer/saved-views/{$id}")->assertNotFound();

        // And back in her own, it goes.
        $this->actAs($this->ada);
        $this->deleteJson("/api/organizer/saved-views/{$id}")->assertNoContent();
        $this->assertSame(0, SavedView::query()->count());
    }

    public function test_an_organization_she_is_not_in_is_refused(): void
    {
        $this->actAs($this->ada, $this->other);

        $this->getJson('/api/organizer/saved-views?list=orders')->assertForbidden();
        $this->save()->assertForbidden();
    }

    public function test_only_known_lists_and_flat_data_are_kept(): void
    {
        $this->actAs($this->ada);

        $this->save(['list' => 'passwords'])->assertUnprocessable()->assertJsonValidationErrors('list');
        $this->save(['name' => '   '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->save(['name' => str_repeat('a', 61)])->assertUnprocessable()->assertJsonValidationErrors('name');

        foreach ([
            ['nested' => ['deeper' => 'no']],
            ['Bad-Key' => 'x'],
            ['q' => str_repeat('x', 201)],
            ['ids' => array_fill(0, 51, 'x')],
            ['ids' => [null]],
            ['a', 'b'],
        ] as $state) {
            $this->save(['state' => $state])->assertUnprocessable()->assertJsonValidationErrors('state');
        }

        $big = [];
        foreach (range(1, 30) as $i) {
            $big['k'.$i] = str_repeat('x', 180);
        }
        $this->save(['state' => $big])->assertUnprocessable()->assertJsonValidationErrors('state');

        $this->assertSame(0, SavedView::query()->count());
    }

    public function test_a_list_holds_a_limited_number_of_views(): void
    {
        $this->actAs($this->ada);

        foreach (range(1, SavedViewController::MAX_PER_LIST) as $i) {
            $this->save(['name' => "View {$i}"])->assertCreated();
        }

        $this->save(['name' => 'One more'])->assertUnprocessable()->assertJsonValidationErrors('name');

        // Replacing one that exists is still allowed at the limit.
        $this->save(['name' => 'View 1', 'state' => ['q' => 'x']])->assertOk();
    }

    public function test_signed_out_is_refused(): void
    {
        $this->getJson('/api/organizer/saved-views?list=orders')->assertUnauthorized();
    }
}
