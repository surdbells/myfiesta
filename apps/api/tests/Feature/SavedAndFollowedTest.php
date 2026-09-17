<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Two private lists: nights worth coming back to, and organizers worth
 * hearing from.
 *
 * What is being protected here is that they stay private. An organizer may
 * know how many follow them; nobody may find out who, and nobody may find out
 * what anybody saved.
 */
class SavedAndFollowedTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->event = Event::factory()->published()->create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'starts_at' => now()->addWeek(),
        ]);
        TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 3000,
            'quantity_available' => 100,
            'status' => 'on_sale',
        ]);

        $this->ada = User::factory()->create();
    }

    private function asAda(): void
    {
        Sanctum::actingAs($this->ada, [TokenAbility::Attendee->value]);
    }

    public function test_saving_a_night_puts_it_on_the_list(): void
    {
        $this->asAda();

        $this->putJson('/api/events/afro-fest/save')->assertOk()->assertJson(['saved' => true]);

        $this->getJson('/api/me/saved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'afro-fest');
    }

    public function test_saving_twice_is_saving_once(): void
    {
        $this->asAda();

        // A phone on a bad signal sends the same tap twice. The second one is
        // not an error anybody should have to read.
        $this->putJson('/api/events/afro-fest/save')->assertOk();
        $this->putJson('/api/events/afro-fest/save')->assertOk();

        $this->getJson('/api/me/saved')->assertJsonCount(1, 'data');
    }

    public function test_unsaving_takes_it_off_again(): void
    {
        $this->asAda();
        $this->putJson('/api/events/afro-fest/save');

        $this->deleteJson('/api/events/afro-fest/save')->assertOk()->assertJson(['saved' => false]);

        $this->getJson('/api/me/saved')->assertJsonCount(0, 'data');
    }

    public function test_a_night_that_has_been_and_gone_drops_off_the_list(): void
    {
        $this->asAda();
        $this->putJson('/api/events/afro-fest/save')->assertOk();

        $this->event->update(['starts_at' => now()->subDay(), 'ends_at' => now()->subHours(20)]);

        // Saving is for later. Once later has happened the list is a museum,
        // and the tickets screen is where a past night belongs.
        $this->getJson('/api/me/saved')->assertJsonCount(0, 'data');
    }

    public function test_the_event_page_tells_this_reader_whether_they_saved_it(): void
    {
        $this->getJson('/api/events/afro-fest')->assertOk()->assertJsonPath('data.saved', false);

        $this->asAda();
        $this->putJson('/api/events/afro-fest/save');

        $this->getJson('/api/events/afro-fest')->assertOk()->assertJsonPath('data.saved', true);
    }

    public function test_one_persons_list_is_not_another_persons(): void
    {
        $this->asAda();
        $this->putJson('/api/events/afro-fest/save');

        Sanctum::actingAs(User::factory()->create(), [TokenAbility::Attendee->value]);

        $this->getJson('/api/me/saved')->assertJsonCount(0, 'data');
        $this->getJson('/api/events/afro-fest')->assertJsonPath('data.saved', false);
    }

    public function test_saving_needs_an_account(): void
    {
        $this->putJson('/api/events/afro-fest/save')->assertUnauthorized();
        $this->getJson('/api/me/saved')->assertUnauthorized();
    }

    public function test_an_invitation_event_cannot_be_saved_by_slug(): void
    {
        $this->asAda();
        $this->event->update(['kind' => 'invitation']);

        // Invitation events are reachable by their guests through a token and
        // never by slug. A 404 keeps this from confirming a wedding exists.
        $this->putJson('/api/events/afro-fest/save')->assertNotFound();
    }

    public function test_following_an_organizer_and_letting_go_again(): void
    {
        $this->asAda();

        $this->putJson('/api/organizers/lagos-nights/follow')->assertOk()->assertJson(['following' => true]);
        $this->getJson('/api/me/following')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'lagos-nights');

        $this->getJson('/api/events/afro-fest')->assertJsonPath('data.organizer.following', true);

        $this->deleteJson('/api/organizers/lagos-nights/follow')->assertOk()->assertJson(['following' => false]);
        $this->getJson('/api/me/following')->assertJsonCount(0, 'data');
    }

    public function test_an_event_page_never_says_how_many_follow_or_saved(): void
    {
        $this->asAda();
        $this->putJson('/api/organizers/lagos-nights/follow');
        $this->putJson('/api/events/afro-fest/save');

        $page = $this->getJson('/api/events/afro-fest')->json('data');

        // A follower count is a number an organizer starts managing instead of
        // running nights, and a save count tells everyone how quiet a night is.
        $this->assertArrayNotHasKey('followers', $page['organizer']);
        $this->assertArrayNotHasKey('follower_count', $page['organizer']);
        $this->assertArrayNotHasKey('saves', $page);
        $this->assertArrayNotHasKey('save_count', $page);
    }

    public function test_a_door_token_cannot_read_somebodys_lists(): void
    {
        // A door phone is lent to staff for a night. It scans, and that is all
        // it does.
        Sanctum::actingAs($this->ada, ['door:'.$this->event->id]);

        $this->getJson('/api/me/saved')->assertForbidden();
        $this->putJson('/api/events/afro-fest/save')->assertForbidden();
        $this->getJson('/api/me/following')->assertForbidden();
    }
}
