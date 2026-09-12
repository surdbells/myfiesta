<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\EventImage;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Events\EventDuplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Copying an event.
 *
 * What is tested here is mostly what must *not* come across. A copy that
 * inherits last week's sales, or last week's sold-out flags, or a sales window
 * that closed before the new event was announced, looks right in the console
 * and is wrong on the night.
 */
class EventDuplicationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'description' => 'Afrobeats until 3am.',
            'currency' => 'CAD',
            'starts_at' => now()->addDays(7)->setTime(21, 0),
            'ends_at' => now()->addDays(8)->setTime(3, 0),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'category' => 'Music',
            'min_age' => 19,
            'id_required' => true,
            'status' => 'published',
        ]);

        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'Early bird',
            'description' => 'Cheapest way in.',
            'price_amount' => 2500,
            'quantity_available' => 100,
            'admits' => 1,
            'status' => 'on_sale',
            // Closes two days before doors.
            'sales_end_at' => now()->addDays(5)->setTime(21, 0),
        ]);
    }

    private function member(Role $role): User
    {
        $user = User::factory()->create();

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user->fresh()->load('organizations');
    }

    private function asOrganizer(Role $role = Role::Manager): void
    {
        Sanctum::actingAs($this->member($role), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    // --- what comes across -------------------------------------------------

    public function test_the_shape_of_the_event_is_copied(): void
    {
        $copy = app(EventDuplicator::class)->duplicate($this->event);

        $this->assertSame('Afro Fest', $copy->title);
        // Compared with the source rather than a literal: what matters is that
        // the copy carries the same description, and that copying clean HTML
        // does not wrap it a second time.
        $this->assertNotNull($copy->description);
        $this->assertSame($this->event->description, $copy->description);
        $this->assertSame('Toronto', $copy->city);
        $this->assertSame('ON', $copy->subdivision);
        $this->assertSame(19, $copy->min_age);
        $this->assertTrue($copy->id_required);
        $this->assertSame('CAD', $copy->currency);
    }

    public function test_tiers_come_across_with_their_prices(): void
    {
        $copy = app(EventDuplicator::class)->duplicate($this->event);
        $tier = $copy->ticketTypes()->first();

        $this->assertSame('Early bird', $tier->name);
        $this->assertSame(2500, $tier->price_amount);
        $this->assertSame(100, $tier->quantity_available);
        $this->assertSame('Cheapest way in.', $tier->description);
    }

    public function test_a_sales_window_moves_with_the_event(): void
    {
        $newStart = now()->addDays(30)->setTime(21, 0);

        $copy = app(EventDuplicator::class)->duplicate($this->event, $newStart);
        $tier = $copy->ticketTypes()->first();

        // Two days before doors on the original has to be two days before doors
        // on the copy. Carrying the absolute date over gives an early-bird tier
        // that expired before the event was announced.
        $this->assertSame(
            2,
            (int) round($tier->sales_end_at->diffInDays($copy->starts_at)),
        );
    }

    public function test_the_length_of_the_event_is_preserved_not_its_end_time(): void
    {
        $copy = app(EventDuplicator::class)->duplicate(
            $this->event,
            now()->addDays(30)->setTime(21, 0),
        );

        // Copying the absolute end would give an event that finishes before it
        // starts — refused by the database, and baffling in the console.
        $this->assertSame(6 * 60, (int) $copy->starts_at->diffInMinutes($copy->ends_at));
    }

    public function test_the_banner_is_copied_as_its_own_files(): void
    {
        $banner = EventImage::create([
            'event_id' => $this->event->id,
            'kind' => 'banner',
            'path' => 'events/a/original.jpg',
            'renditions' => ['og' => 'events/a/original-og.jpg'],
            'width' => 1600,
            'height' => 900,
        ]);

        Storage::disk('public')->put($banner->path, 'bytes');
        Storage::disk('public')->put($banner->renditions['og'], 'og bytes');

        $copy = app(EventDuplicator::class)->duplicate($this->event);

        // Sharing one path between two events looks like a saving until
        // replacing the banner on one changes it on the other.
        $this->assertNotSame($banner->path, $copy->banner->path);
        Storage::disk('public')->assertExists($copy->banner->path);
        Storage::disk('public')->assertExists($copy->banner->renditions['og']);
        // And the original is untouched.
        Storage::disk('public')->assertExists($banner->path);
    }

    public function test_reminder_offsets_come_across_with_nothing_sent(): void
    {
        $this->event->reminders()->create([
            'offset_minutes' => 1440,
            'status' => 'sent',
            'sent_at' => now(),
            'recipients' => 40,
        ]);

        $copy = app(EventDuplicator::class)->duplicate($this->event, now()->addDays(30));
        $reminder = $copy->reminders()->first();

        // The reminder that already went out went out about the original.
        $this->assertSame(1440, $reminder->offset_minutes);
        $this->assertSame('scheduled', $reminder->status);
        $this->assertNull($reminder->sent_at);
    }

    // --- what must not -----------------------------------------------------

    public function test_nothing_that_happened_is_copied(): void
    {
        Ticket::create([
            'event_id' => $this->event->id,
            'ticket_type_id' => $this->type->id,
            'code' => 'AAAA-BBBBBBBB',
            'owner_email' => 'ada@example.com',
            'status' => 'valid',
            'admits' => 1,
            'admitted_count' => 0,
        ]);

        $copy = app(EventDuplicator::class)->duplicate($this->event);

        // A copy that inherited last week's sales would be a lie about a night
        // that has not happened.
        $this->assertSame(0, $copy->tickets()->count());
        $this->assertSame(0, $copy->orders()->count());
    }

    public function test_a_copy_starts_as_a_draft(): void
    {
        $copy = app(EventDuplicator::class)->duplicate($this->event);

        // Publishing something the organizer has not looked at is not a favour.
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->published_at);
    }

    public function test_a_copy_gets_its_own_slug(): void
    {
        $copy = app(EventDuplicator::class)->duplicate($this->event);

        // The original's slug is in shared messages and printed QR codes and
        // belongs to it permanently.
        $this->assertNotSame('afro-fest', $copy->slug);
        $this->assertSame('afro-fest-2', $copy->slug);
    }

    public function test_a_sold_out_tier_is_on_sale_again_on_the_copy(): void
    {
        $this->type->update(['status' => 'sold_out']);

        $copy = app(EventDuplicator::class)->duplicate($this->event);

        // sold_out describes stock that does not exist on this event. The copy
        // has all of it.
        $this->assertSame('on_sale', $copy->ticketTypes()->first()->status);
    }

    public function test_a_closed_tier_stays_closed(): void
    {
        // An organizer who deliberately stopped selling a tier meant it.
        $this->type->update(['status' => 'closed']);

        $copy = app(EventDuplicator::class)->duplicate($this->event);

        $this->assertSame('closed', $copy->ticketTypes()->first()->status);
    }

    public function test_the_gallery_is_not_copied(): void
    {
        EventImage::create([
            'event_id' => $this->event->id,
            'kind' => 'gallery',
            'path' => 'events/a/night.jpg',
        ]);

        $copy = app(EventDuplicator::class)->duplicate($this->event);

        // Advertising a future event with photographs of a different party.
        $this->assertSame(0, $copy->gallery()->count());
    }

    // --- the endpoint ------------------------------------------------------

    public function test_a_manager_can_duplicate_an_event_to_a_new_date(): void
    {
        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/duplicate", [
            'starts_at' => now()->addDays(14)->toIso8601String(),
            'title' => 'Afro Fest — November',
        ])
            ->assertCreated()
            ->assertJsonPath('title', 'Afro Fest — November');

        $this->assertSame(2, Event::count());

        // Checked against the database rather than the response: the public
        // event resource carries no status, because everything it is used for
        // is already published by definition.
        $copy = Event::where('title', 'Afro Fest — November')->firstOrFail();
        $this->assertSame('draft', $copy->status);
    }

    public function test_a_copy_cannot_be_dated_in_the_past(): void
    {
        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/duplicate", [
            'starts_at' => now()->subDay()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_marketing_cannot_mint_an_event_by_copying_one(): void
    {
        $this->asOrganizer(Role::Marketing);

        // Reading an event is a long way from creating one.
        $this->postJson("/api/organizer/events/{$this->event->id}/duplicate")->assertForbidden();
        $this->assertSame(1, Event::count());
    }

    public function test_another_organizations_event_cannot_be_copied(): void
    {
        $otherOrg = Organization::create(['name' => 'Someone Else', 'slug' => 'someone-else']);
        $stranger = User::factory()->create();
        $otherOrg->members()->attach($stranger->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($stranger->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        // Otherwise a competitor's lineup, prices and capacity are one POST
        // away from being in your own console.
        $this->postJson("/api/organizer/events/{$this->event->id}/duplicate")->assertForbidden();
        $this->assertSame(1, Event::count());
    }
}
