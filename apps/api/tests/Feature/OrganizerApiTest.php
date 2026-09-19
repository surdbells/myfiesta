<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\TicketIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The organizer console's API.
 *
 * The important tests here are the ones that fail. The previous platform
 * checked a token through a verifier that always returned success, so any
 * account could edit any event by id — these exist so that cannot come back.
 */
class OrganizerApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->owner = $this->member(Role::Owner);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'draft',
        ]);
    }

    private function member(Role $role, ?Organization $org = null): User
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        ($org ?? $this->org)->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user->fresh()->load('organizations');
    }

    private function asOrganizer(User $user): void
    {
        Sanctum::actingAs($user, [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    // --- signing in --------------------------------------------------------

    public function test_signing_in_returns_a_token_and_the_organizations(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => $this->owner->email,
            'password' => 'correct-horse',
        ])
            ->assertOk()
            ->assertJsonPath('organizations.0.slug', 'lagos-nights')
            ->assertJsonPath('organizations.0.role', 'owner')
            ->assertJsonStructure(['token', 'abilities']);
    }

    public function test_a_wrong_password_and_an_unknown_address_answer_the_same(): void
    {
        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => $this->owner->email,
            'password' => 'not-it',
        ])->assertStatus(422);

        $noSuchUser = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'not-it',
        ])->assertStatus(422);

        // Distinguishing them turns login into a way to find out who holds an
        // account here.
        $this->assertSame(
            $wrongPassword->json('message'),
            $noSuchUser->json('message'),
        );
    }

    public function test_an_unclaimed_account_cannot_sign_in(): void
    {
        // Migrated buyers exist with a null password so their tickets are
        // waiting for them. That must not be a way in.
        $unclaimed = User::factory()->create(['password' => null]);

        $this->postJson('/api/auth/login', [
            'email' => $unclaimed->email,
            'password' => '',
        ])->assertStatus(422);
    }

    public function test_an_attendee_without_an_organization_gets_no_organizer_ability(): void
    {
        $buyer = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $abilities = $this->postJson('/api/auth/login', [
            'email' => $buyer->email,
            'password' => 'correct-horse',
        ])->assertOk()->json('abilities');

        $this->assertSame(['attendee'], $abilities);
    }

    // --- ownership ---------------------------------------------------------

    public function test_the_event_list_shows_only_my_organizations_events(): void
    {
        $otherOrg = Organization::create(['name' => 'Someone Else', 'slug' => 'someone-else']);
        Event::create([
            'organization_id' => $otherOrg->id,
            'slug' => 'not-mine',
            'title' => 'Not Mine',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $this->asOrganizer($this->owner);

        $this->getJson('/api/organizer/events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'afro-fest');
    }

    public function test_one_event_comes_back_with_everything_the_edit_form_changes(): void
    {
        $this->event->update([
            'description' => 'Afrobeats until 3am.',
            'ends_at' => $this->event->starts_at->copy()->addHours(6),
            'category' => 'Music',
            'min_age' => 19,
        ]);

        $this->asOrganizer($this->owner);

        // Every field the form can send has to come back, or editing one thing
        // silently blanks the rest on save.
        $this->getJson("/api/organizer/events/{$this->event->id}")
            ->assertOk()
            ->assertJsonPath('title', 'Afro Fest')
            // Plain text is stored as a paragraph, and the editor loads exactly
            // that — see EventDescriptionTest for the conversion itself.
            ->assertJsonPath('description', '<p>Afrobeats until 3am.</p>')
            ->assertJsonPath('city', 'Toronto')
            ->assertJsonPath('subdivision', 'ON')
            ->assertJsonPath('country', 'CA')
            ->assertJsonPath('timezone', 'America/Toronto')
            ->assertJsonPath('category', 'Music')
            ->assertJsonPath('min_age', 19)
            ->assertJsonStructure(['starts_at', 'ends_at', 'currency', 'slug', 'status']);
    }

    public function test_another_organizations_event_cannot_even_be_read(): void
    {
        $otherOrg = Organization::create(['name' => 'Someone Else', 'slug' => 'someone-else']);
        $this->asOrganizer($this->member(Role::Owner, $otherOrg));

        // Reading is a smaller thing than editing and still not theirs — an
        // unpublished event's date and description are not public.
        $this->getJson("/api/organizer/events/{$this->event->id}")->assertForbidden();
    }

    public function test_an_organizer_cannot_edit_another_organizations_event(): void
    {
        $otherOrg = Organization::create(['name' => 'Someone Else', 'slug' => 'someone-else']);
        $stranger = $this->member(Role::Owner, $otherOrg);

        $this->asOrganizer($stranger);

        // Holding an organizer token is not the same as owning this event.
        $this->patchJson("/api/organizer/events/{$this->event->id}", ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('Afro Fest', $this->event->refresh()->title);
    }

    public function test_a_door_token_cannot_reach_the_console(): void
    {
        Sanctum::actingAs($this->owner, [TokenAbility::doorFor($this->event->id)]);

        // The scanner and the console live in one binary. This is the boundary.
        $this->getJson('/api/organizer/events')->assertForbidden();
    }

    public function test_finance_cannot_edit_an_event_but_can_read_the_numbers(): void
    {
        $finance = $this->member(Role::Finance);
        $this->asOrganizer($finance);

        $this->patchJson("/api/organizer/events/{$this->event->id}", ['title' => 'Nope'])
            ->assertForbidden();

        $this->getJson("/api/organizer/events/{$this->event->id}/summary")
            ->assertOk();
    }

    public function test_marketing_cannot_read_the_numbers(): void
    {
        $marketing = $this->member(Role::Marketing);
        $this->asOrganizer($marketing);

        // Sending the newsletter is not the same as seeing the takings.
        $this->getJson("/api/organizer/events/{$this->event->id}/summary")
            ->assertForbidden();
    }

    // --- events ------------------------------------------------------------

    public function test_creating_an_event_starts_it_as_a_draft(): void
    {
        $this->asOrganizer($this->owner);

        $this->postJson('/api/organizer/events', [
            'organization_id' => $this->org->id,
            'title' => 'Detty December',
            'currency' => 'NGN',
            'starts_at' => now()->addMonths(4)->toIso8601String(),
            'timezone' => 'Africa/Lagos',
            'city' => 'Lagos',
            'country' => 'NG',
        ])
            ->assertCreated()
            ->assertJsonPath('slug', 'detty-december')
            ->assertJsonPath('currency', 'NGN');

        // Nothing is publishable by accident.
        $this->assertSame('draft', Event::where('slug', 'detty-december')->first()->status);
    }

    public function test_a_duplicate_title_gets_its_own_slug(): void
    {
        $this->asOrganizer($this->owner);

        $payload = [
            'organization_id' => $this->org->id,
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonths(2)->toIso8601String(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
        ];

        // Slugs are permanent once shared, so a collision must never silently
        // claim an existing event's URL.
        $this->postJson('/api/organizer/events', $payload)
            ->assertCreated()
            ->assertJsonPath('slug', 'afro-fest-2');
    }

    public function test_a_title_the_site_already_answers_to_gets_a_suffix(): void
    {
        $this->asOrganizer($this->owner);

        // myfiesta.ca/help is the help page, and myfiesta.ca/embed is where
        // framed checkouts live. An event given either slug could never be
        // reached at its own address.
        foreach (['Help' => 'help-2', 'Embed' => 'embed-2'] as $title => $slug) {
            $this->postJson('/api/organizer/events', [
                'organization_id' => $this->org->id,
                'title' => $title,
                'currency' => 'CAD',
                'starts_at' => now()->addMonths(2)->toIso8601String(),
                'timezone' => 'America/Toronto',
                'city' => 'Toronto',
                'country' => 'CA',
            ])
                ->assertCreated()
                ->assertJsonPath('slug', $slug);
        }
    }

    public function test_currency_cannot_be_changed_after_the_fact(): void
    {
        $this->asOrganizer($this->owner);

        $this->patchJson("/api/organizer/events/{$this->event->id}", [
            'title' => 'Afro Fest',
            'currency' => 'NGN',
        ])->assertOk();

        // Orders snapshot their currency. An event switching underneath them
        // would leave the ledger unable to explain itself.
        $this->assertSame('CAD', $this->event->refresh()->currency);
    }

    public function test_publishing_needs_something_to_sell(): void
    {
        $this->asOrganizer($this->owner);

        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'published'])
            ->assertStatus(422);

        TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);

        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('status', 'published');
    }

    public function test_republishing_keeps_the_original_announcement_date(): void
    {
        TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);

        $this->asOrganizer($this->owner);

        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'published']);
        $first = $this->event->refresh()->published_at;

        $this->travel(2)->days();

        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'draft']);
        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'published']);

        // Otherwise pulling an event down for an hour makes it look newly
        // announced in every feed sorted by that date.
        $this->assertEquals($first, $this->event->refresh()->published_at);
    }

    // --- ticket types ------------------------------------------------------

    public function test_a_ticket_type_can_be_created_and_repriced(): void
    {
        $this->asOrganizer($this->owner);

        $id = $this->postJson("/api/organizer/events/{$this->event->id}/ticket-types", [
            'name' => 'Early bird',
            'price_amount' => 2500,
            'quantity_available' => 100,
        ])->assertCreated()->json('price.amount');

        $this->assertSame(2500, $id);

        $type = TicketType::first();

        $this->patchJson(
            "/api/organizer/events/{$this->event->id}/ticket-types/{$type->id}",
            ['price_amount' => 4000],
        )->assertOk()->assertJsonPath('price.amount', 4000);
    }

    public function test_a_sold_ticket_type_is_closed_rather_than_deleted(): void
    {
        $type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);

        app(TicketIssuer::class)
            ->issueComp($this->event->id, $type->id, 'guest@example.com', 'Guest');

        $this->asOrganizer($this->owner);

        $this->deleteJson("/api/organizer/events/{$this->event->id}/ticket-types/{$type->id}")
            ->assertOk()
            ->assertJsonPath('status', 'closed');

        // Deleting it would leave issued tickets pointing at nothing and make
        // their orders impossible to explain.
        $this->assertDatabaseHas('ticket_types', ['id' => $type->id, 'status' => 'closed']);
    }

    public function test_a_ticket_type_from_another_event_is_not_reachable(): void
    {
        $otherEvent = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'other',
            'title' => 'Other',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'draft',
        ]);

        $foreign = TicketType::create([
            'event_id' => $otherEvent->id,
            'name' => 'Theirs',
            'price_amount' => 100,
            'status' => 'on_sale',
        ]);

        $this->asOrganizer($this->owner);

        // Same organization, wrong event. The id alone is not authority.
        $this->patchJson(
            "/api/organizer/events/{$this->event->id}/ticket-types/{$foreign->id}",
            ['price_amount' => 1],
        )->assertNotFound();
    }
}
