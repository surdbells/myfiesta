<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\AddOn;
use App\Models\Event;
use App\Models\EventImage;
use App\Models\EventQuestion;
use App\Models\EventReview;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Events\DuplicateOptions;
use App\Services\Events\EventDuplicator;
use App\Services\Events\EventSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReviewsEvents;
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
    use RefreshDatabase, ReviewsEvents;

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

    // --- extras, questions and ladders -----------------------------------------

    /**
     * Two of each, made in the same moment and told apart only by their ids:
     * the order that is hardest to keep, and the one a review compares.
     */
    private function twoOfEverything(): void
    {
        $this->freezeTime();

        // Made first, with the higher id: listed second by the snapshot.
        $this->made(new AddOn, 'ffffffff-ffff-7fff-bfff-ffffffffffff', ['name' => 'Bottle', 'price_amount' => 15000, 'status' => 'on_sale']);
        $this->made(new AddOn, '00000000-0000-7000-8000-000000000001', ['name' => 'Table for six', 'price_amount' => 40000, 'quantity_available' => 10, 'status' => 'on_sale']);

        $this->made(new EventQuestion, 'ffffffff-ffff-7fff-bfff-fffffffffffe', ['label' => 'Name on the ticket', 'type' => 'text', 'required' => true, 'per_attendee' => true]);
        $this->made(new EventQuestion, '00000000-0000-7000-8000-000000000002', ['label' => 'Dietary needs', 'type' => 'choice', 'options' => ['None', 'Vegan'], 'required' => false, 'per_attendee' => false]);

        $this->made(new TicketType, '00000000-0000-7000-8000-000000000003', ['name' => 'Door', 'price_amount' => 4000, 'status' => 'on_sale']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function made(AddOn|EventQuestion|TicketType $row, string $id, array $attributes): void
    {
        $row->forceFill(['id' => $id, 'event_id' => $this->event->id, ...$attributes])->save();
    }

    /** @return array<string, mixed> */
    private function shapeOf(Event $event): array
    {
        return EventSnapshot::withoutDates(EventSnapshot::of($event->fresh()));
    }

    public function test_extras_and_questions_come_across_listed_as_the_original_lists_them(): void
    {
        $this->twoOfEverything();

        $copy = app(EventDuplicator::class)->duplicate($this->event, now()->addDays(30));

        $this->assertSame(['Table for six', 'Bottle'], array_column(EventSnapshot::of($copy)['add_ons'], 'name'));
        $this->assertSame(['Dietary needs', 'Name on the ticket'], array_column(EventSnapshot::of($copy)['questions'], 'label'));

        // The whole night, apart from its dates, reads the same: what a
        // review compares to put the next date of a series straight on sale.
        $this->assertSame($this->shapeOf($this->event), $this->shapeOf($copy));
        $this->assertSame(0, AddOn::where('event_id', $copy->id)->whereIn('id', AddOn::where('event_id', $this->event->id)->pluck('id'))->count());
    }

    public function test_a_price_ladder_comes_across_as_a_ladder(): void
    {
        $second = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'Second release',
            'price_amount' => 3500,
            'status' => 'on_sale',
            'sort_order' => 1,
            'opens_after_id' => $this->type->id,
        ]);

        $copy = app(EventDuplicator::class)->duplicate($this->event, now()->addDays(30));

        $first = $copy->ticketTypes()->where('name', 'Early bird')->sole();
        $next = $copy->ticketTypes()->where('name', 'Second release')->sole();

        // Waiting for the copy's first step, not last month's: pointing at the
        // original would open it when the old night's early birds sold out.
        $this->assertSame($first->id, $next->opens_after_id);
        $this->assertNotSame($second->id, $next->id);
        $this->assertSame($this->shapeOf($this->event), $this->shapeOf($copy));
    }

    public function test_a_step_left_out_of_the_ladder_leaves_the_next_one_open(): void
    {
        TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'Second release',
            'price_amount' => 3500,
            'status' => 'on_sale',
            'sort_order' => 1,
            'opens_after_id' => $this->type->id,
        ]);

        $copy = app(EventDuplicator::class)->duplicate(
            $this->event,
            now()->addDays(30),
            options: new DuplicateOptions(ticketTypes: [$this->type->id => ['include' => false]]),
        );

        $this->assertSame(['Second release'], $copy->ticketTypes()->pluck('name')->all());
        $this->assertNull($copy->ticketTypes()->sole()->opens_after_id);
    }

    public function test_extras_questions_and_reminders_can_be_left_behind(): void
    {
        $this->twoOfEverything();
        $this->event->reminders()->create(['offset_minutes' => 1440, 'status' => 'scheduled']);

        $copy = app(EventDuplicator::class)->duplicate(
            $this->event,
            now()->addDays(30),
            options: new DuplicateOptions(addOns: false, questions: false, reminders: false),
        );

        $this->assertSame(0, $copy->addOns()->count());
        $this->assertSame(0, $copy->questions()->count());
        $this->assertSame(0, $copy->reminders()->count());
        // The tiers still come: they are the night.
        $this->assertSame(2, $copy->ticketTypes()->count());
    }

    public function test_a_date_of_an_approved_series_with_extras_and_questions_goes_on_sale_as_the_approved_night(): void
    {
        Mail::fake();
        $this->twoOfEverything();
        $this->event->update(['status' => 'draft', 'starts_at' => now()->addWeek()->setTime(21, 0), 'ends_at' => null]);
        $this->type->update(['sales_end_at' => null]);
        $this->asOrganizer(Role::Owner);

        $this->publishThroughReview($this->event)->assertOk();
        $this->postJson("/api/organizer/events/{$this->event->id}/series", ['frequency' => 'weekly', 'count' => 2])->assertCreated();

        $next = Event::query()->where('series_id', $this->event->fresh()->series_id)->whereKeyNot($this->event->id)->sole();

        $this->assertSame(2, $next->addOns()->count());
        $this->assertSame(2, $next->questions()->count());

        // Two extras made in one moment, listed as the approved night lists
        // them: the date is that night, not one with its extras swapped round.
        $this->postJson("/api/organizer/events/{$next->id}/submit")
            ->assertOk()
            ->assertJsonPath('status', 'published');

        $review = EventReview::where('event_id', $next->id)->sole();
        $this->assertSame(['approved', 'series'], [$review->action, $review->via]);
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

    // --- copying with changes ------------------------------------------------

    public function test_a_copy_is_made_with_the_changes_asked_for(): void
    {
        $this->twoOfEverything();
        $door = TicketType::where('name', 'Door')->sole();
        $this->asOrganizer();

        $startsAt = now()->addDays(30)->setTime(22, 0);

        $response = $this->postJson("/api/organizer/events/{$this->event->id}/duplicate", [
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $startsAt->copy()->addHours(4)->toIso8601String(),
            'title' => 'Afro Fest: Winter',
            'description' => '<p>Indoors this time.</p>',
            'ticket_types' => [
                ['id' => $this->type->id, 'name' => 'First release', 'price_amount' => 3000, 'quantity_available' => null],
                ['id' => $door->id, 'include' => false],
            ],
            'include' => ['add_ons' => false, 'questions' => true, 'reminders' => true],
        ])->assertCreated();

        $copy = Event::findOrFail($response->json('id'));

        // Said back with what the console needs to open it.
        $response->assertJsonPath('status', 'draft')->assertJsonPath('title', 'Afro Fest: Winter');

        $this->assertSame('draft', $copy->status);
        $this->assertSame('Afro Fest: Winter', $copy->title);
        $this->assertStringContainsString('Indoors this time.', (string) $copy->description);
        $this->assertSame(4 * 60, (int) $copy->starts_at->diffInMinutes($copy->ends_at));

        $tier = $copy->ticketTypes()->sole();
        $this->assertSame(['First release', 3000, null], [$tier->name, $tier->price_amount, $tier->quantity_available]);
        // The original's tier closed two days before doors; so does the copy's.
        $this->assertSame(2, (int) round($tier->sales_end_at->diffInDays($copy->starts_at)));

        $this->assertSame(0, $copy->addOns()->count());
        $this->assertSame(2, $copy->questions()->count());

        // The original is as it was.
        $this->assertSame('Early bird', $this->type->fresh()->name);
        $this->assertSame(2500, $this->type->fresh()->price_amount);
    }

    public function test_a_ticket_type_from_another_event_cannot_be_named(): void
    {
        $other = Event::create([...$this->event->only(['organization_id', 'title', 'currency', 'timezone', 'city', 'country']), 'slug' => 'other', 'starts_at' => now()->addMonth()]);
        $theirs = TicketType::create(['event_id' => $other->id, 'name' => 'VIP', 'price_amount' => 9000, 'status' => 'on_sale']);
        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/duplicate", [
            'starts_at' => now()->addDays(14)->toIso8601String(),
            'ticket_types' => [['id' => $theirs->id, 'price_amount' => 1]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ticket_types.0.id']);

        $this->assertSame(2, Event::count());
    }

    public function test_a_copy_cannot_end_before_it_starts(): void
    {
        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/duplicate", [
            'starts_at' => now()->addDays(14)->toIso8601String(),
            'ends_at' => now()->addDays(13)->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ends_at']);
    }

    public function test_a_price_cannot_be_made_negative_on_the_way(): void
    {
        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/duplicate", [
            'starts_at' => now()->addDays(14)->toIso8601String(),
            'ticket_types' => [['id' => $this->type->id, 'price_amount' => -100]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ticket_types.0.price_amount']);
    }

    /**
     * The rule takes 0 and "0" as well as false. Each leaves the tier out;
     * read as "not false" they would put it on sale at its old price.
     */
    #[DataProvider('leftOut')]
    public function test_a_tier_left_out_with_any_spelling_of_false_stays_out(mixed $include, bool $asForm): void
    {
        $this->twoOfEverything();
        $door = TicketType::where('name', 'Door')->sole();
        $this->asOrganizer();

        $body = [
            'starts_at' => now()->addDays(14)->toIso8601String(),
            'ticket_types' => [['id' => $door->id, 'include' => $include]],
        ];

        $response = $asForm
            ? $this->post("/api/organizer/events/{$this->event->id}/duplicate", $body, ['Accept' => 'application/json'])
            : $this->postJson("/api/organizer/events/{$this->event->id}/duplicate", $body);

        $copy = Event::findOrFail($response->assertCreated()->json('id'));

        $this->assertSame(['Early bird'], $copy->ticketTypes()->pluck('name')->all());
    }

    /** @return array<string, array{mixed, bool}> */
    public static function leftOut(): array
    {
        return [
            'false' => [false, false],
            'the number 0' => [0, false],
            'the string "0"' => ['0', false],
            'a form field of 0' => ['0', true],
        ];
    }

    public function test_a_tier_too_big_to_keep_is_refused_rather_than_failing_the_copy(): void
    {
        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/duplicate", [
            'starts_at' => now()->addDays(14)->toIso8601String(),
            'ticket_types' => [['id' => $this->type->id, 'quantity_available' => 3_000_000_000]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ticket_types.0.quantity_available']);

        $this->assertSame(1, Event::count());
    }

    public function test_an_event_taken_off_sale_by_myfiesta_cannot_be_copied(): void
    {
        $this->event->forceFill(['taken_down_at' => now(), 'taken_down_reason' => 'The venue says it has not been booked.'])->save();
        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/duplicate", [
            'starts_at' => now()->addDays(14)->toIso8601String(),
        ])->assertStatus(422);

        $this->assertSame(1, Event::count());
    }
}
