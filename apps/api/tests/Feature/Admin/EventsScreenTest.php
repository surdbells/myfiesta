<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Mail\EventRestored;
use App\Mail\EventTakenDown;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\User;
use App\Services\Events\SeriesGenerator;
use App\Services\StaffSupport\EventModeration;
use App\Services\StaffSupport\StaffActionRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Events screen: every event with how it is selling, money per currency,
 * and the two things the platform decides about an event — whether it is
 * featured, and whether it stays on sale. Only administrators decide either,
 * a takedown deletes nothing, and the organizer is told why.
 */
class EventsScreenTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_every_staff_role_reads_events_and_nobody_else(): void
    {
        foreach (PlatformRole::cases() as $role) {
            $this->actAs($this->staff($role));
            $this->assertTrue(EventResource::canViewAny(), $role->value.' could not open Events.');
        }

        $this->actAs(User::factory()->create());
        $this->assertFalse(EventResource::canViewAny());

        $this->actAs($this->staff(PlatformRole::Admin));
        $this->assertFalse(EventResource::canCreate());
        $this->assertFalse(EventResource::canDeleteAny());
    }

    public function test_events_are_found_by_title_organizer_or_city(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));

        $afro = $this->event($this->organization('Toronto Sound'), ['title' => 'Afro Fest']);
        $eko = $this->event($this->organization('Eko Live'), ['title' => 'Eko Nights', 'currency' => 'NGN']);

        Livewire::test(ListEvents::class)
            ->assertCanSeeTableRecords([$afro, $eko])
            ->searchTable('afro')
            ->assertCanSeeTableRecords([$afro])
            ->assertCanNotSeeTableRecords([$eko])
            ->searchTable('Eko Live')
            ->assertCanSeeTableRecords([$eko])
            ->assertCanNotSeeTableRecords([$afro])
            ->searchTable('lagos')
            ->assertCanSeeTableRecords([$eko])
            ->assertCanNotSeeTableRecords([$afro]);
    }

    public function test_the_filters_narrow_to_what_they_say(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));

        $toronto = $this->organization('Toronto Sound');
        $lagos = $this->organization('Eko Live');

        $selling = $this->event($toronto, ['title' => 'Selling']);
        $this->paidOrder($selling, $this->ticketType($selling), 1);
        $draft = $this->event($toronto, ['title' => 'Draft', 'status' => 'draft', 'published_at' => null]);
        $past = $this->event($lagos, ['title' => 'Past', 'currency' => 'NGN', 'starts_at' => now()->subMonths(2)]);
        $down = $this->event($lagos, ['title' => 'Down', 'currency' => 'NGN', 'status' => 'draft',
            'taken_down_at' => now(), 'taken_down_reason' => 'Counterfeit listing.']);

        Livewire::test(ListEvents::class)
            ->filterTable('status', ['draft'])
            ->assertCanSeeTableRecords([$draft, $down])
            ->assertCanNotSeeTableRecords([$selling, $past])
            ->resetTableFilters()
            ->filterTable('organization', $lagos->id)
            ->assertCanSeeTableRecords([$past, $down])
            ->assertCanNotSeeTableRecords([$selling, $draft])
            ->resetTableFilters()
            ->filterTable('country', 'NG')
            ->assertCanSeeTableRecords([$past, $down])
            ->assertCanNotSeeTableRecords([$selling])
            ->resetTableFilters()
            ->filterTable('currency', 'CAD')
            ->assertCanSeeTableRecords([$selling, $draft])
            ->assertCanNotSeeTableRecords([$past])
            ->resetTableFilters()
            ->filterTable('upcoming', false)
            ->assertCanSeeTableRecords([$past])
            ->assertCanNotSeeTableRecords([$selling, $draft])
            ->resetTableFilters()
            ->filterTable('starts', ['from' => now()->subMonths(3)->toDateString(), 'until' => now()->subMonth()->toDateString()])
            ->assertCanSeeTableRecords([$past])
            ->assertCanNotSeeTableRecords([$selling])
            ->resetTableFilters()
            ->filterTable('has_sales', true)
            ->assertCanSeeTableRecords([$selling])
            ->assertCanNotSeeTableRecords([$draft, $past, $down])
            ->resetTableFilters()
            ->filterTable('taken_down', true)
            ->assertCanSeeTableRecords([$down])
            ->assertCanNotSeeTableRecords([$selling, $draft, $past]);
    }

    public function test_sold_capacity_and_gross_are_per_event_and_totalled_per_currency(): void
    {
        $this->actAs($this->staff(PlatformRole::Finance));

        $cad = $this->event($this->organization());
        $ngn = $this->event($this->organization('Eko Live'), ['currency' => 'NGN']);

        // 2 × 50.00 + 13% = $113.00; 1 × ₦5,000 + 13% = ₦5,650.
        $this->paidOrder($cad, $this->ticketType($cad, ['quantity_available' => 150]));
        $this->paidOrder($ngn, $this->ticketType($ngn, ['price_amount' => 500000, 'quantity_available' => null]), 1);

        Livewire::test(ListEvents::class)
            ->assertTableColumnStateSet('sold_count', 2, $cad)
            ->assertTableColumnStateSet('sold_count', 1, $ngn)
            ->assertSee('of 150')
            ->assertSee('of unlimited')
            ->assertSee('$113.00 · ₦5,650')
            ->sortTable('sold_count', 'desc')
            ->assertSuccessful()
            ->sortTable('gross_amount', 'desc')
            ->assertSuccessful();
    }

    /**
     * Sorted by gross, the events that sold come first, whichever way, and
     * each currency is ranked on its own. Postgres puts nothing (an event
     * with no sales) first when sorting down, and ranked ₦5,650 above $113.
     */
    public function test_sorting_by_gross_ranks_what_sold_within_each_currency(): void
    {
        $this->actAs($this->staff(PlatformRole::Finance));

        $small = $this->event($this->organization('Small Room'), ['title' => 'Small Room']);
        $big = $this->event($this->organization('Big Room'), ['title' => 'Big Room']);
        $lagos = $this->event($this->organization('Eko Live'), ['title' => 'Eko Live', 'currency' => 'NGN']);
        $empty = $this->event($this->organization('Nobody Came'), ['title' => 'Nobody Came']);

        $this->paidOrder($small, $this->ticketType($small), 1);   // $56.50
        $this->paidOrder($big, $this->ticketType($big), 4);       // $226.00
        $this->paidOrder($lagos, $this->ticketType($lagos, ['price_amount' => 500000]), 1); // ₦5,650

        Livewire::test(ListEvents::class)
            ->sortTable('gross_amount', 'desc')
            ->assertCanSeeTableRecords([$big, $small, $empty, $lagos], inOrder: true)
            ->sortTable('gross_amount', 'asc')
            ->assertCanSeeTableRecords([$small, $big, $empty, $lagos], inOrder: true);
    }

    /** "1 order", not "1 orders". */
    public function test_the_event_page_counts_one_order_as_one(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization('Toronto Sound'));
        $this->paidOrder($event, $this->ticketType($event), 1, ['channel' => 'door']);

        Livewire::test(ViewEvent::class, ['record' => $event->getKey()])
            ->assertSee('1 order, 1 at the door')
            ->assertDontSee('1 orders');
    }

    public function test_the_event_page_shows_key_numbers_and_ticket_types(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization('Toronto Sound'), ['title' => 'Highlife Night']);
        $order = $this->paidOrder($event, $this->ticketType($event, ['name' => 'Early Bird']));

        Livewire::test(ViewEvent::class, ['record' => $event->getKey()])
            ->assertSuccessful()
            ->assertSee('Highlife Night')
            ->assertSee('Toronto Sound')
            ->assertSee('Early Bird')
            ->assertSee('$113.00')
            ->assertDontSee($order->tickets->first()->code);
    }

    public function test_only_an_administrator_features_an_event(): void
    {
        $event = $this->event($this->organization());

        foreach ([PlatformRole::Support, PlatformRole::Finance] as $role) {
            $this->actAs($this->staff($role));

            Livewire::test(ListEvents::class)
                ->assertTableActionHidden('feature', $event)
                ->assertTableActionHidden('takeDown', $event);
        }

        $admin = $this->actAs($this->staff(PlatformRole::Admin));

        Livewire::test(ListEvents::class)
            ->callTableAction('feature', $event)
            ->assertHasNoTableActionErrors();

        $this->assertTrue($event->fresh()->is_featured);
        $this->assertSame($admin->id, AuditLog::where('action', 'event.featured')->sole()->actor_id);

        Livewire::test(ListEvents::class)
            ->assertTableActionHidden('feature', $event)
            ->callTableAction('unfeature', $event)
            ->assertHasNoTableActionErrors();

        $this->assertFalse($event->fresh()->is_featured);
        $this->assertSame($admin->id, AuditLog::where('action', 'event.unfeatured')->sole()->actor_id);
    }

    public function test_a_draft_is_not_featured(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));
        $draft = $this->event($this->organization(), ['status' => 'draft', 'published_at' => null]);

        Livewire::test(ListEvents::class)
            ->assertTableActionHidden('feature', $draft);

        $this->expectException(StaffActionRefused::class);
        app(EventModeration::class)->feature($draft, $admin, true);
    }

    public function test_a_takedown_hides_the_event_tells_the_organizer_and_deletes_nothing(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));
        $organization = $this->organization();
        $owner = $this->member($organization, Role::Owner, ['email' => 'owner@toronto.test']);
        $this->member($organization, Role::Door, ['email' => 'door@toronto.test']);
        $event = $this->event($organization, ['is_featured' => true]);
        $order = $this->paidOrder($event, $this->ticketType($event));

        Livewire::test(ViewEvent::class, ['record' => $event->getKey()])
            ->callAction('takeDown', data: ['reason' => 'The venue says it has not been booked.'])
            ->assertHasNoActionErrors()
            ->assertNotified('Event taken down');

        $event->refresh();
        $this->assertSame('draft', $event->status);
        $this->assertFalse($event->is_featured);
        $this->assertNotNull($event->taken_down_at);
        $this->assertSame('The venue says it has not been booked.', $event->taken_down_reason);
        $this->assertSame($admin->id, $event->taken_down_by);
        $this->assertNull($event->deleted_at);

        // Nothing sold is touched.
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(['valid'], $order->tickets()->pluck('status')->unique()->values()->all());

        // The people who can publish hear why; door staff do not.
        Mail::assertQueued(EventTakenDown::class, fn (EventTakenDown $mail) => $mail->hasTo('owner@toronto.test')
            && $mail->reason === 'The venue says it has not been booked.');
        Mail::assertNotQueued(EventTakenDown::class, fn (EventTakenDown $mail) => $mail->hasTo('door@toronto.test'));

        $entry = AuditLog::where('action', 'event.taken_down')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame('published', $entry->metadata['from']);

        // And the organizer's own publish button no longer lifts it.
        Sanctum::actingAs($owner->fresh(), [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);
        $this->postJson("/api/organizer/events/{$event->id}/publish", ['status' => 'published'])
            ->assertStatus(422);
        $this->assertSame('draft', $event->fresh()->status);
    }

    public function test_a_taken_down_event_cannot_be_copied_or_repeated_to_get_round_it(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));
        $organization = $this->organization();
        $owner = $this->member($organization, Role::Owner);
        $event = $this->event($organization, ['title' => 'Afro Fest']);
        $this->ticketType($event);

        app(EventModeration::class)->takeDown($event, $admin, 'The venue says it has not been booked.');
        $events = Event::withTrashed()->count();

        Sanctum::actingAs($owner->fresh(), [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);

        // A copy would carry no takedown, and then publish would let it out.
        $this->postJson("/api/organizer/events/{$event->id}/duplicate", ['starts_at' => now()->addMonths(2)->toIso8601String()])
            ->assertStatus(422);

        // Every occurrence of a series is such a copy.
        $this->postJson("/api/organizer/events/{$event->id}/series", ['frequency' => 'weekly', 'count' => 4])
            ->assertStatus(422);

        $this->assertSame($events, Event::withTrashed()->count());
        $this->assertSame(0, EventSeries::count());
    }

    public function test_a_series_stops_making_copies_while_its_source_is_taken_down(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));
        $organization = $this->organization();
        $owner = $this->member($organization, Role::Owner);
        $event = $this->event($organization, ['starts_at' => now()->addWeek()->setTime(21, 0)]);
        $this->ticketType($event);

        // Made to repeat before anything was wrong with it.
        Sanctum::actingAs($owner->fresh(), [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);
        $this->postJson("/api/organizer/events/{$event->id}/series", ['frequency' => 'weekly'])->assertCreated();

        $series = EventSeries::sole();
        $moderation = app(EventModeration::class);
        $generator = app(SeriesGenerator::class);

        $moderation->takeDown($event->fresh(), $admin, 'The venue says it has not been booked.');
        $before = Event::count();

        $this->assertSame([], $generator->generate($series->fresh(), now()->addYear()));
        $this->assertSame($before, Event::count());

        // Lifted, the window catches up on the next run.
        $moderation->restore($event->fresh(), $admin);
        $this->assertNotEmpty($generator->generate($series->fresh(), now()->addYear()));
    }

    public function test_a_takedown_on_an_event_the_organizer_since_deleted_is_refused_not_a_crash(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));
        $event = $this->event($this->organization());
        $moderation = app(EventModeration::class);

        $moderation->takeDown($event, $admin, 'Checking the venue booking first.');
        $event->fresh()->delete();

        Livewire::test(ListEvents::class)
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$event])
            ->assertTableActionHidden('restoreEvent', $event);

        try {
            $moderation->restore($event->fresh(), $admin);
            $this->fail('A deleted event had its takedown lifted.');
        } catch (StaffActionRefused $refused) {
            $this->assertSame('This event was deleted by the organizer.', $refused->getMessage());
        }

        $this->assertNotNull($event->fresh()->taken_down_at);
        $this->assertSame(0, AuditLog::where('action', 'event.restored')->count());
        Mail::assertNotQueued(EventRestored::class);
    }

    public function test_a_takedown_needs_a_reason_and_an_administrator(): void
    {
        $event = $this->event($this->organization());

        $this->actAs($this->staff(PlatformRole::Admin));
        Livewire::test(ListEvents::class)
            ->callTableAction('takeDown', $event, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $finance = $this->staff(PlatformRole::Finance);

        try {
            app(EventModeration::class)->takeDown($event, $finance, 'Finance should not be able to do this.');
            $this->fail('Finance took an event down.');
        } catch (StaffActionRefused) {
            $this->assertNull($event->fresh()->taken_down_at);
        }

        Mail::assertNothingQueued();
    }

    public function test_restoring_puts_it_back_on_sale_and_tells_the_organizer(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));
        $organization = $this->organization();
        $this->member($organization, Role::Owner, ['email' => 'owner@toronto.test']);
        $event = $this->event($organization);
        $this->ticketType($event);

        app(EventModeration::class)->takeDown($event, $admin, 'Checking the venue booking first.');

        Livewire::test(ListEvents::class)
            ->filterTable('taken_down', true)
            ->assertTableActionHidden('takeDown', $event)
            ->callTableAction('restoreEvent', $event, data: ['note' => 'Venue confirmed by phone.'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Takedown lifted');

        $event->refresh();
        $this->assertSame('published', $event->status);
        $this->assertNull($event->taken_down_at);
        $this->assertNull($event->taken_down_reason);

        Mail::assertQueued(EventRestored::class, fn (EventRestored $mail) => $mail->hasTo('owner@toronto.test'));

        $entry = AuditLog::where('action', 'event.restored')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame('published', $entry->metadata['status']);
    }

    public function test_a_draft_taken_down_comes_back_a_draft(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));
        $event = $this->event($this->organization(), ['status' => 'draft', 'published_at' => null]);
        $this->ticketType($event);

        $moderation = app(EventModeration::class);
        $moderation->takeDown($event, $admin, 'Copying another organizer’s artwork.');

        $this->assertSame('draft', $moderation->restore($event->fresh(), $admin));
        $this->assertSame('draft', $event->fresh()->status);
    }

    public function test_a_cancelled_event_is_not_taken_down(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));
        $event = $this->event($this->organization(), ['status' => 'cancelled', 'cancelled_at' => now()]);

        Livewire::test(ListEvents::class)
            ->assertTableActionHidden('takeDown', $event);

        $this->expectException(StaffActionRefused::class);
        app(EventModeration::class)->takeDown($event, $admin, 'It is already cancelled though.');
    }

    public function test_deleted_events_can_still_be_found_and_opened(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization(), ['title' => 'Gone Night']);
        $event->delete();

        Livewire::test(ListEvents::class)
            ->assertCanNotSeeTableRecords([$event])
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$event]);

        Livewire::test(ViewEvent::class, ['record' => $event->getKey()])
            ->assertSuccessful()
            ->assertSee('Gone Night')
            ->assertSee('Deleted by the organizer');
    }

    public function test_the_orders_and_tickets_links_filter_to_this_event(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization());

        $filter = ['filters' => ['event' => ['value' => $event->id]]];

        Livewire::test(ViewEvent::class, ['record' => $event->getKey()])
            ->assertActionHasUrl('orders', OrderResource::getUrl('index', $filter))
            ->assertActionHasUrl('tickets', TicketResource::getUrl('index', $filter));
    }

    public function test_a_published_event_can_be_opened_on_the_public_site(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $event = $this->event($this->organization());
        $draft = $this->event($this->organization(), ['status' => 'draft', 'published_at' => null]);

        Livewire::test(ListEvents::class)
            ->assertTableActionVisible('publicPage', $event)
            ->assertTableActionHasUrl('publicPage', rtrim((string) config('app.public_url'), '/').'/'.$event->slug, $event)
            ->assertTableActionHidden('publicPage', $draft);
    }
}
