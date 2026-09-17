<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Http\Controllers\Api\OrganizerController;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrganizationFollow;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The page behind an organizer's name.
 *
 * Two things are being protected here. That the page shows the nights a
 * stranger is allowed to see — and only those, because an organization also
 * runs weddings that are published solely so their invited guests can reach
 * them. And that a slug nobody has ever published under is a 404 rather than
 * an empty page, since a page per registered account is a way to ask which
 * names are taken.
 */
class OrganizerPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Lagos Nights',
            'slug' => 'lagos-nights',
            'description' => 'Afrobeats every second Friday.',
            'verified_at' => now(),
            'verified_name' => 'Lagos Nights',
        ]);
    }

    private function event(string $slug, array $attributes = []): Event
    {
        $event = Event::factory()->published()->create([
            'organization_id' => $this->org->id,
            'slug' => $slug,
            'title' => ucfirst(str_replace('-', ' ', $slug)),
            'starts_at' => now()->addWeek(),
            ...$attributes,
        ]);

        TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 4000,
            'quantity_available' => 100,
            'status' => 'on_sale',
        ]);

        return $event;
    }

    public function test_the_page_carries_who_they_are_and_what_is_on(): void
    {
        $this->event('afro-fest');

        // No token. This is a link somebody pastes into a bio.
        $this->getJson('/api/organizers/lagos-nights')
            ->assertOk()
            ->assertJsonPath('data.name', 'Lagos Nights')
            ->assertJsonPath('data.slug', 'lagos-nights')
            ->assertJsonPath('data.description', 'Afrobeats every second Friday.')
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.following', false)
            ->assertJsonCount(1, 'data.upcoming')
            ->assertJsonPath('data.upcoming.0.slug', 'afro-fest')
            // The card needs a price to show, and it comes from the tickets.
            ->assertJsonPath('data.upcoming.0.from_price.amount', 4000)
            ->assertJsonCount(0, 'data.past');
    }

    public function test_a_renamed_organizer_loses_the_tick_here_too(): void
    {
        $this->event('afro-fest');

        // Verified is "somebody checked this name", not "this account was once
        // checked" — the same rule the event page applies.
        $this->org->update(['name' => 'Lagos Nights Global']);

        $this->getJson('/api/organizers/lagos-nights')
            ->assertOk()
            ->assertJsonPath('data.name', 'Lagos Nights Global')
            ->assertJsonPath('data.is_verified', false);
    }

    public function test_nights_are_ordered_soonest_first_and_history_newest_first(): void
    {
        $this->event('in-three-weeks', ['starts_at' => now()->addWeeks(3)]);
        $this->event('next-week', ['starts_at' => now()->addWeek()]);
        $this->event('last-month', ['starts_at' => now()->subMonth()]);
        $this->event('last-year', ['starts_at' => now()->subYear()]);

        $body = $this->getJson('/api/organizers/lagos-nights')->assertOk()->json('data');

        $this->assertSame(['next-week', 'in-three-weeks'], array_column($body['upcoming'], 'slug'));
        $this->assertSame(['last-month', 'last-year'], array_column($body['past'], 'slug'));
    }

    public function test_history_is_bounded(): void
    {
        // A promoter who has run nights for five years should not hand a
        // stranger three hundred cards to scroll past what is on.
        for ($i = 1; $i <= OrganizerController::PAST + 4; $i++) {
            $this->event('night-'.$i, ['starts_at' => now()->subDays($i)]);
        }

        $this->getJson('/api/organizers/lagos-nights')
            ->assertOk()
            ->assertJsonCount(OrganizerController::PAST, 'data.past')
            // Bounded from the recent end, not the old one.
            ->assertJsonPath('data.past.0.slug', 'night-1');
    }

    public function test_it_shows_only_the_nights_a_stranger_may_see(): void
    {
        $this->event('afro-fest');
        $this->event('still-a-draft', ['status' => 'draft']);
        $this->event('called-off', ['status' => 'cancelled', 'cancelled_at' => now()]);
        // Published so its invited guests can reach it by token, and for no
        // other reason. Listing it here would put somebody's wedding on a
        // public page.
        $this->event('ada-and-tunde', ['kind' => 'invitation']);

        $body = $this->getJson('/api/organizers/lagos-nights')->assertOk()->json('data');

        $this->assertSame(['afro-fest'], array_column($body['upcoming'], 'slug'));
    }

    public function test_another_organizers_nights_are_not_on_this_page(): void
    {
        $this->event('afro-fest');

        $other = Organization::create(['name' => 'Toronto Basement', 'slug' => 'toronto-basement']);
        Event::factory()->published()->create([
            'organization_id' => $other->id,
            'slug' => 'not-theirs',
            'starts_at' => now()->addWeek(),
        ]);

        $body = $this->getJson('/api/organizers/lagos-nights')->assertOk()->json('data');

        $this->assertSame(['afro-fest'], array_column($body['upcoming'], 'slug'));
    }

    public function test_an_account_that_has_never_published_has_no_page(): void
    {
        Organization::create(['name' => 'Signed Up Yesterday', 'slug' => 'signed-up-yesterday']);

        $this->getJson('/api/organizers/signed-up-yesterday')->assertNotFound();
    }

    public function test_an_organizer_whose_nights_are_all_behind_them_still_has_one(): void
    {
        $this->event('last-summer', ['starts_at' => now()->subMonths(8)]);

        $this->getJson('/api/organizers/lagos-nights')
            ->assertOk()
            ->assertJsonCount(0, 'data.upcoming')
            ->assertJsonCount(1, 'data.past');
    }

    public function test_an_unknown_slug_is_not_found(): void
    {
        $this->getJson('/api/organizers/nobody')->assertNotFound();
    }

    public function test_a_reader_who_follows_them_is_told_so(): void
    {
        $this->event('afro-fest');

        $ada = User::factory()->create();
        OrganizationFollow::create(['user_id' => $ada->id, 'organization_id' => $this->org->id]);

        Sanctum::actingAs($ada, [TokenAbility::Attendee->value]);

        $this->getJson('/api/organizers/lagos-nights')
            ->assertOk()
            ->assertJsonPath('data.following', true);
    }

    public function test_a_reader_who_does_not_follow_them_is_told_that(): void
    {
        $this->event('afro-fest');

        Sanctum::actingAs(User::factory()->create(), [TokenAbility::Attendee->value]);

        $this->getJson('/api/organizers/lagos-nights')
            ->assertOk()
            ->assertJsonPath('data.following', false);
    }

    public function test_the_page_never_says_how_many_follow_them(): void
    {
        $this->event('afro-fest');

        foreach (User::factory()->count(3)->create() as $user) {
            OrganizationFollow::create(['user_id' => $user->id, 'organization_id' => $this->org->id]);
        }

        $body = $this->getJson('/api/organizers/lagos-nights')->assertOk()->json('data');

        // A follower count is a number an organizer would start managing
        // instead of running nights, and it publishes how quiet a quiet name
        // is. Whoever adds one should have to delete this test first.
        $this->assertSame(
            ['slug', 'name', 'description', 'is_verified', 'logo_url', 'following', 'upcoming', 'past'],
            array_keys($body),
        );
    }
}
