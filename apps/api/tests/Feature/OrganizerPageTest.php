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
            ['slug', 'name', 'description', 'is_verified', 'logo_url', 'following', 'upcoming', 'past', 'past_has_more', 'socials'],
            array_keys($body),
        );
    }

    public function test_the_page_says_whether_there_are_older_nights_to_show(): void
    {
        for ($i = 1; $i <= OrganizerController::PAST; $i++) {
            $this->event('night-'.$i, ['starts_at' => now()->subDays($i)]);
        }

        // Exactly a page: nothing more to ask for.
        $this->getJson('/api/organizers/lagos-nights')
            ->assertOk()
            ->assertJsonPath('data.past_has_more', false);

        $this->event('one-more', ['starts_at' => now()->subYear()]);

        $this->getJson('/api/organizers/lagos-nights')
            ->assertOk()
            ->assertJsonCount(OrganizerController::PAST, 'data.past')
            ->assertJsonPath('data.past_has_more', true);
    }

    public function test_older_nights_come_twelve_at_a_time_without_repeating_or_skipping_one(): void
    {
        $total = OrganizerController::PAST * 2 + 3;

        for ($i = 1; $i <= $total; $i++) {
            $this->event('night-'.$i, ['starts_at' => now()->subDays($i)]);
        }

        $first = $this->getJson('/api/organizers/lagos-nights')->assertOk()->json('data.past');

        // Page 1 is the twelve the page already carries.
        $this->getJson('/api/organizers/lagos-nights/events?when=past&page=1')
            ->assertOk()
            ->assertJsonPath('data', $first)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', OrganizerController::PAST)
            ->assertJsonPath('meta.has_more', true);

        $second = $this->getJson('/api/organizers/lagos-nights/events?when=past&page=2')
            ->assertOk()
            ->assertJsonCount(OrganizerController::PAST, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->json('data');

        $third = $this->getJson('/api/organizers/lagos-nights/events?when=past&page=3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.has_more', false)
            ->json('data');

        $slugs = array_column([...$first, ...$second, ...$third], 'slug');

        // Newest first, every one of them once.
        $this->assertSame(array_map(fn ($i) => 'night-'.$i, range(1, $total)), $slugs);

        $this->getJson('/api/organizers/lagos-nights/events?when=past&page=4')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_nights_on_the_same_evening_keep_their_place_across_pages(): void
    {
        $evening = now()->subWeek()->setTime(21, 0);

        for ($i = 1; $i <= OrganizerController::PAST + 2; $i++) {
            $this->event('same-night-'.$i, ['starts_at' => $evening]);
        }

        $first = $this->getJson('/api/organizers/lagos-nights/events?when=past&page=1')->json('data');
        $second = $this->getJson('/api/organizers/lagos-nights/events?when=past&page=2')->json('data');

        $slugs = array_column([...$first, ...$second], 'slug');

        $this->assertCount(OrganizerController::PAST + 2, $slugs);
        $this->assertSame($slugs, array_values(array_unique($slugs)));
    }

    public function test_paging_shows_only_the_nights_a_stranger_may_see(): void
    {
        $this->event('afro-fest', ['starts_at' => now()->subWeek()]);
        $this->event('still-a-draft', ['status' => 'draft', 'starts_at' => now()->subWeek()]);
        $this->event('called-off', ['status' => 'cancelled', 'cancelled_at' => now(), 'starts_at' => now()->subWeek()]);
        // An invitation event is never listed, on any page of it.
        $this->event('ada-and-tunde', ['kind' => 'invitation', 'starts_at' => now()->subWeek()]);
        $this->event('ada-and-tunde-reception', ['kind' => 'invitation', 'starts_at' => now()->addWeek()]);

        $this->assertSame(
            ['afro-fest'],
            array_column($this->getJson('/api/organizers/lagos-nights/events?when=past')->assertOk()->json('data'), 'slug'),
        );

        $this->getJson('/api/organizers/lagos-nights/events?when=upcoming')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_what_is_on_pages_soonest_first(): void
    {
        $this->event('in-three-weeks', ['starts_at' => now()->addWeeks(3)]);
        $this->event('next-week', ['starts_at' => now()->addWeek()]);
        $this->event('last-month', ['starts_at' => now()->subMonth()]);

        $this->assertSame(
            ['next-week', 'in-three-weeks'],
            array_column($this->getJson('/api/organizers/lagos-nights/events?when=upcoming')->assertOk()->json('data'), 'slug'),
        );
    }

    public function test_what_is_on_the_same_evening_keeps_its_place_across_pages_and_on_the_page(): void
    {
        $evening = now()->addWeek()->setTime(21, 0);

        for ($i = 1; $i <= OrganizerController::PAST + 2; $i++) {
            $this->event('same-night-'.$i, ['starts_at' => $evening]);
        }

        $first = $this->getJson('/api/organizers/lagos-nights/events?when=upcoming&page=1')->json('data');
        $second = $this->getJson('/api/organizers/lagos-nights/events?when=upcoming&page=2')->json('data');

        $slugs = array_column([...$first, ...$second], 'slug');

        $this->assertCount(OrganizerController::PAST + 2, $slugs);
        $this->assertSame($slugs, array_values(array_unique($slugs)));
        // In the order the organizer page lists them.
        $this->assertSame(array_column($this->getJson('/api/organizers/lagos-nights')->json('data.upcoming'), 'slug'), $slugs);
    }

    public function test_an_organizer_with_no_page_has_no_nights_to_page_through(): void
    {
        Organization::create(['name' => 'Signed Up Yesterday', 'slug' => 'signed-up-yesterday']);

        $this->getJson('/api/organizers/signed-up-yesterday/events?when=past')->assertNotFound();
        $this->getJson('/api/organizers/nobody/events?when=past')->assertNotFound();
    }

    public function test_paging_asks_which_nights_and_a_sensible_page(): void
    {
        $this->event('afro-fest');

        $this->getJson('/api/organizers/lagos-nights/events')->assertUnprocessable()->assertJsonValidationErrors(['when']);
        $this->getJson('/api/organizers/lagos-nights/events?when=everything')->assertUnprocessable()->assertJsonValidationErrors(['when']);
        $this->getJson('/api/organizers/lagos-nights/events?when=past&page=0')->assertUnprocessable()->assertJsonValidationErrors(['page']);
        $this->getJson('/api/organizers/lagos-nights/events?when=past&page=lots')->assertUnprocessable()->assertJsonValidationErrors(['page']);
    }

    public function test_the_page_links_to_where_else_to_find_them(): void
    {
        $this->event('afro-fest');

        $this->org->update([
            'instagram' => 'https://www.instagram.com/lagos.nights/',
            'x_handle' => '@LagosNights',
            'tiktok' => 'lagosnights',
            'facebook' => 'lagosnightsto',
            'website' => 'https://lagosnights.com/about',
        ]);

        $this->getJson('/api/organizers/lagos-nights')
            ->assertOk()
            ->assertJsonPath('data.socials', [
                ['network' => 'instagram', 'label' => '@lagos.nights', 'url' => 'https://www.instagram.com/lagos.nights/'],
                ['network' => 'tiktok', 'label' => '@lagosnights', 'url' => 'https://www.tiktok.com/@lagosnights'],
                ['network' => 'x', 'label' => '@LagosNights', 'url' => 'https://x.com/LagosNights'],
                ['network' => 'facebook', 'label' => 'lagosnightsto', 'url' => 'https://www.facebook.com/lagosnightsto'],
                ['network' => 'website', 'label' => 'lagosnights.com', 'url' => 'https://lagosnights.com/about'],
            ]);
    }

    public function test_a_facebook_page_with_no_username_goes_by_the_organizers_name(): void
    {
        $this->event('afro-fest');

        // The pages put "Facebook" in front of the label themselves, so a
        // label of "Facebook" would read twice.
        foreach ([
            'https://www.facebook.com/profile.php?id=100064',
            'https://www.facebook.com/pages/Caf%C3%A9-Montr%C3%A9al/123456789',
        ] as $kept) {
            $this->org->update(['facebook' => $kept]);

            $this->getJson('/api/organizers/lagos-nights')
                ->assertOk()
                ->assertJsonPath('data.socials', [
                    ['network' => 'facebook', 'label' => 'Lagos Nights', 'url' => $kept],
                ]);
        }
    }

    public function test_what_the_importer_left_that_cannot_be_read_is_not_linked(): void
    {
        $this->event('afro-fest');

        // Straight into the columns, as LegacyImporter writes them.
        $this->org->update([
            'instagram' => 'N/A',
            'facebook' => 'https://www.facebook.com/events/123456/',
            'x_handle' => 'https://evil.example/LagosNights',
        ]);

        $this->getJson('/api/organizers/lagos-nights')
            ->assertOk()
            ->assertJsonPath('data.socials', []);
    }
}
