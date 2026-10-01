<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\AddOn;
use App\Models\Event;
use App\Models\EventImage;
use App\Models\EventQuestion;
use App\Models\EventTemplate;
use App\Models\Organization;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Venue;
use App\Services\Events\EventTemplates;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Event templates: a night kept as a starting point, and the next night made
 * from it.
 *
 * What matters is that the template is the night as it was when it was kept
 * — not as it is now, and not with anything that happened — and that what is
 * made from it is a draft that goes through review like any other event.
 */
class EventTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $early;

    private TicketType $second;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $venue = Venue::create([
            'organization_id' => $this->org->id,
            'name' => 'The Warehouse',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'timezone' => 'America/Toronto',
        ]);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'venue_id' => $venue->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'description' => '<p>Afrobeats until 3am.</p>',
            'currency' => 'CAD',
            'starts_at' => now()->addDays(7)->setTime(21, 0),
            'ends_at' => now()->addDays(8)->setTime(3, 0),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'min_age' => 19,
            'id_required' => true,
            'status' => 'published',
        ]);

        $this->early = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'Early bird',
            'price_amount' => 2500,
            'quantity_available' => 100,
            'status' => 'on_sale',
            'sort_order' => 0,
            // Closes two days before doors.
            'sales_end_at' => now()->addDays(5)->setTime(21, 0),
        ]);

        $this->second = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'Second release',
            'price_amount' => 3500,
            'status' => 'on_sale',
            'sort_order' => 1,
            'opens_after_id' => $this->early->id,
        ]);

        AddOn::create(['event_id' => $this->event->id, 'name' => 'Table for six', 'price_amount' => 40000, 'status' => 'on_sale']);
        EventQuestion::create(['event_id' => $this->event->id, 'label' => 'Name on the ticket', 'type' => 'text', 'required' => true, 'per_attendee' => true]);
        $this->event->reminders()->create(['offset_minutes' => 1440, 'status' => 'sent', 'sent_at' => now(), 'recipients' => 40]);

        $this->manager = $this->member(Role::Manager);
    }

    private function member(Role $role, ?Organization $org = null): User
    {
        $user = User::factory()->create();

        ($org ?? $this->org)->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    private function signIn(User $user): void
    {
        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    private function keep(string $name = 'Friday night'): string
    {
        $this->signIn($this->manager);

        return $this->postJson('/api/organizer/templates', [
            'from_event_id' => $this->event->id,
            'name' => $name,
        ])->assertCreated()->json('data.id');
    }

    private function poster(): EventImage
    {
        Storage::disk('public')->put('events/a/poster.jpg', 'poster bytes');
        Storage::disk('public')->put('events/a/poster-display.jpg', 'display bytes');

        return EventImage::create([
            'event_id' => $this->event->id,
            'kind' => 'banner',
            'path' => 'events/a/poster.jpg',
            'renditions' => ['display' => 'events/a/poster-display.jpg'],
            'width' => 1600,
            'height' => 900,
        ]);
    }

    // --- keeping one ---------------------------------------------------------

    public function test_an_event_is_kept_as_a_template_with_its_poster_in_files_of_its_own(): void
    {
        $poster = $this->poster();

        $id = $this->keep();
        $template = EventTemplate::findOrFail($id);

        $this->assertSame($this->org->id, $template->organization_id);
        $this->assertSame('Friday night', $template->name);
        $this->assertSame($this->manager->id, $template->created_by);
        $this->assertSame(['Early bird', 'Second release'], array_column($template->payload['ticket_types'], 'name'));
        $this->assertSame(['Table for six'], array_column($template->payload['add_ons'], 'name'));
        $this->assertSame([1440], $template->payload['reminders']);

        // The event may replace or delete its own poster; the template's is
        // its own copy.
        $this->assertStringStartsWith("templates/{$id}/", (string) $template->banner_path);
        Storage::disk('public')->assertExists($template->banner_path);
        Storage::disk('public')->assertExists($template->payload['banner']['renditions']['display']);
        Storage::disk('public')->assertExists($poster->path);
    }

    public function test_changing_the_event_afterwards_does_not_change_the_template(): void
    {
        $id = $this->keep();

        $this->early->update(['price_amount' => 9900, 'name' => 'Last minute']);

        $this->getJson('/api/organizer/templates')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.ticket_types.0.name', 'Early bird')
            ->assertJsonPath('data.0.ticket_types.0.price.amount', 2500);
    }

    public function test_two_templates_cannot_share_a_name(): void
    {
        $this->keep('Friday night');

        $this->postJson('/api/organizer/templates', [
            'from_event_id' => $this->event->id,
            'name' => '  friday   NIGHT ',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->assertSame(1, EventTemplate::count());
    }

    public function test_an_event_taken_off_sale_by_myfiesta_cannot_be_kept(): void
    {
        $this->event->forceFill(['taken_down_at' => now(), 'taken_down_reason' => 'The venue says it has not been booked.'])->save();
        $this->signIn($this->manager);

        $this->postJson('/api/organizer/templates', ['from_event_id' => $this->event->id, 'name' => 'Friday night'])
            ->assertStatus(422);

        $this->assertSame(0, EventTemplate::count());
    }

    /**
     * Kept the week before myFiesta took the night off sale, a template would
     * otherwise bring it straight back as a fresh draft.
     */
    public function test_a_template_kept_before_a_takedown_cannot_be_used_while_it_holds(): void
    {
        $id = $this->keep();
        $this->event->forceFill(['taken_down_at' => now(), 'taken_down_reason' => 'The venue says it has not been booked.'])->save();
        $body = ['starts_at' => now()->addDays(30)->toIso8601String()];

        $this->postJson("/api/organizer/templates/{$id}/events", $body)
            ->assertStatus(422)
            ->assertJsonPath('message', 'myFiesta has taken the event this template was kept from off sale, so it cannot be used until that is lifted.');

        // Deleting the event does not lift it.
        $this->event->delete();
        $this->postJson("/api/organizer/templates/{$id}/events", $body)->assertStatus(422);

        $this->assertSame(0, Event::query()->count());

        // Lifted, the template works again.
        Event::withTrashed()->findOrFail($this->event->id)->forceFill(['taken_down_at' => null, 'taken_down_reason' => null])->save();
        $this->postJson("/api/organizer/templates/{$id}/events", $body)->assertCreated();
    }

    public function test_the_fifty_first_template_is_refused(): void
    {
        foreach (range(1, EventTemplates::MAX_PER_ORGANIZATION) as $n) {
            EventTemplate::query()->forceCreate(['organization_id' => $this->org->id, 'name' => "Night {$n}", 'payload' => []]);
        }

        $this->signIn($this->manager);

        $this->postJson('/api/organizer/templates', ['from_event_id' => $this->event->id, 'name' => 'One more'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->assertSame(EventTemplates::MAX_PER_ORGANIZATION, EventTemplate::count());
    }

    /**
     * Two saves at the same moment both pass the check before either is
     * written; the index refuses the second, and its poster copy goes too.
     */
    public function test_the_database_holds_a_name_once_whatever_its_capitals(): void
    {
        $this->poster();
        $templates = app(EventTemplates::class);

        $templates->save($this->event, 'Friday night', $this->manager);

        try {
            $templates->save($this->event, 'FRIDAY NIGHT', $this->manager);
            $this->fail('A second template of the same name was kept.');
        } catch (UniqueConstraintViolationException) {
            // As it should be.
        }

        $this->assertSame(1, EventTemplate::count());
        $this->assertCount(1, Storage::disk('public')->directories('templates'));
    }

    public function test_marketing_cannot_keep_list_or_use_templates(): void
    {
        $id = $this->keep();
        $this->signIn($this->member(Role::Marketing));

        // A template is a way of making events, and marketing makes none.
        $this->postJson('/api/organizer/templates', ['from_event_id' => $this->event->id, 'name' => 'Another'])->assertForbidden();
        $this->getJson('/api/organizer/templates')->assertForbidden();
        $this->postJson("/api/organizer/templates/{$id}/events", ['starts_at' => now()->addMonth()->toIso8601String()])->assertForbidden();
        $this->deleteJson("/api/organizer/templates/{$id}")->assertForbidden();

        $this->assertSame(1, EventTemplate::count());
        $this->assertSame(1, Event::count());
    }

    public function test_another_organizations_templates_are_not_there(): void
    {
        $id = $this->keep();

        $theirs = Organization::create(['name' => 'Someone Else', 'slug' => 'someone-else']);
        $this->signIn($this->member(Role::Owner, $theirs));

        $this->getJson('/api/organizer/templates')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/organizer/templates/{$id}/events", ['starts_at' => now()->addMonth()->toIso8601String()])->assertNotFound();
        $this->deleteJson("/api/organizer/templates/{$id}")->assertNotFound();

        // Nor can their events be kept as templates of their own.
        $this->postJson('/api/organizer/templates', ['from_event_id' => $this->event->id, 'name' => 'Theirs'])->assertForbidden();
        $this->assertSame(1, EventTemplate::count());
    }

    // --- making an event from one ----------------------------------------------

    public function test_an_event_made_from_a_template_is_a_draft_of_the_night_it_was_kept_from(): void
    {
        $this->poster();
        $id = $this->keep();
        $template = EventTemplate::findOrFail($id);

        $startsAt = now()->addDays(40)->setTime(21, 0);

        $response = $this->postJson("/api/organizer/templates/{$id}/events", [
            'starts_at' => $startsAt->toIso8601String(),
        ])->assertCreated()->assertJsonPath('status', 'draft');

        $event = Event::findOrFail($response->json('id'));

        // A template was never approved, only the night it came from.
        $this->assertSame('draft', $event->status);
        $this->assertNull($event->approved_at);
        $this->assertSame('Afro Fest', $event->title);
        $this->assertNotSame('afro-fest', $event->slug);
        $this->assertSame($this->event->venue_id, $event->venue_id);
        $this->assertSame(6 * 60, (int) $event->starts_at->diffInMinutes($event->ends_at));

        $first = $event->ticketTypes()->where('name', 'Early bird')->sole();
        $next = $event->ticketTypes()->where('name', 'Second release')->sole();

        // The ladder is a ladder of this event's own tiers, and the early-bird
        // window closes two days before these doors.
        $this->assertSame($first->id, $next->opens_after_id);
        $this->assertSame(2, (int) round($first->sales_end_at->diffInDays($event->starts_at)));

        $this->assertSame(['Table for six'], $event->addOns()->pluck('name')->all());
        $this->assertSame(['Name on the ticket'], $event->questions()->pluck('label')->all());

        // The reminder times, with nothing sent.
        $reminder = $event->reminders()->sole();
        $this->assertSame([1440, 'scheduled', null], [$reminder->offset_minutes, $reminder->status, $reminder->sent_at]);

        // The poster in the event's own files, so deleting the template later
        // leaves it alone.
        $this->assertStringStartsWith("events/{$event->id}/", $event->banner->path);
        $this->assertNotSame($template->banner_path, $event->banner->path);
        Storage::disk('public')->assertExists($event->banner->path);
    }

    public function test_an_event_is_made_from_a_template_with_the_changes_asked_for(): void
    {
        $id = $this->keep();

        $response = $this->postJson("/api/organizer/templates/{$id}/events", [
            'starts_at' => now()->addDays(40)->setTime(20, 0)->toIso8601String(),
            'title' => 'Afro Fest: Spring',
            'ticket_types' => [
                ['id' => $this->early->id, 'include' => false],
                ['id' => $this->second->id, 'price_amount' => 3000, 'quantity_available' => 150],
            ],
            'include' => ['add_ons' => false, 'questions' => false, 'reminders' => false],
        ])->assertCreated();

        $event = Event::findOrFail($response->json('id'));

        $this->assertSame('Afro Fest: Spring', $event->title);

        // The step it waited for was left out, so it opens like any tier.
        $tier = $event->ticketTypes()->sole();
        $this->assertSame(['Second release', 3000, 150, null], [$tier->name, $tier->price_amount, $tier->quantity_available, $tier->opens_after_id]);

        $this->assertSame(0, $event->addOns()->count());
        $this->assertSame(0, $event->questions()->count());
        $this->assertSame(0, $event->reminders()->count());
    }

    public function test_an_event_from_a_template_needs_a_date_to_come(): void
    {
        $id = $this->keep();

        $this->postJson("/api/organizer/templates/{$id}/events", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['starts_at']);

        $this->postJson("/api/organizer/templates/{$id}/events", ['starts_at' => now()->subDay()->toIso8601String()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['starts_at']);

        $this->assertSame(1, Event::count());
    }

    public function test_a_venue_removed_since_is_left_off(): void
    {
        $id = $this->keep();
        Venue::query()->whereKey($this->event->venue_id)->first()?->delete();

        $response = $this->postJson("/api/organizer/templates/{$id}/events", [
            'starts_at' => now()->addMonth()->toIso8601String(),
        ])->assertCreated();

        $this->assertNull(Event::findOrFail($response->json('id'))->venue_id);
    }

    // --- deleting one --------------------------------------------------------

    public function test_deleting_a_template_removes_its_files_and_leaves_events_made_from_it(): void
    {
        $this->poster();
        $id = $this->keep();
        $paths = EventTemplate::findOrFail($id)->paths();

        $made = $this->postJson("/api/organizer/templates/{$id}/events", [
            'starts_at' => now()->addMonth()->toIso8601String(),
        ])->assertCreated()->json('id');

        $this->deleteJson("/api/organizer/templates/{$id}")->assertNoContent();

        $this->assertSame(0, EventTemplate::count());
        $this->assertCount(2, $paths);

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }

        // The event made from it has files of its own.
        Storage::disk('public')->assertExists(Event::findOrFail($made)->banner->path);
    }

    // --- the contract ----------------------------------------------------------

    public function test_a_template_carries_what_the_contract_promises(): void
    {
        $this->keep();

        $spec = Yaml::parseFile(base_path('../../packages/contract/openapi.yaml'));
        $declared = array_keys($spec['components']['schemas']['EventTemplate']['properties']);
        $template = $this->getJson('/api/organizer/templates')->assertOk()->json('data.0');

        foreach ($declared as $field) {
            $this->assertArrayHasKey($field, $template, "EventTemplate declares '{$field}' and the API does not return it.");
        }

        $copy = $this->postJson("/api/organizer/templates/{$template['id']}/events", [
            'starts_at' => now()->addMonth()->toIso8601String(),
        ])->assertCreated()->json();

        foreach (array_keys($spec['components']['schemas']['EventCopy']['properties']) as $field) {
            $this->assertArrayHasKey($field, $copy, "EventCopy declares '{$field}' and the API does not return it.");
        }
    }
}
