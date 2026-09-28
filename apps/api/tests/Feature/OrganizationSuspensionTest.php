<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Exceptions\CheckoutException;
use App\Mail\EventReminderMail;
use App\Mail\EventRestored;
use App\Mail\OrganizationSuspended;
use App\Mail\OrganizationUnsuspended;
use App\Models\AuditLog;
use App\Models\EmailPreference;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Models\Settlement;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use App\Services\Door\DoorSales;
use App\Services\Organizations\Suspension;
use App\Services\Organizations\WhileSuspended;
use App\Services\Payouts\PayoutRequestRefused;
use App\Services\Payouts\PayoutRequests;
use App\Services\Payouts\SettlementRecorder;
use App\Services\Payouts\SettlementRefused;
use App\Services\Reminders\ReminderDispatcher;
use App\Services\Resale\Resale;
use App\Services\StaffSupport\EventModeration;
use App\Services\StaffSupport\StaffActionRefused;
use App\Support\Money;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReviewsEvents;
use Tests\Feature\Admin\SupportFixtures;
use Tests\TestCase;

/**
 * Suspending an organization, and lifting it.
 *
 * "Unpublish its events and freeze its payouts, and reverse once
 * unsuspended." The reversal is the part most worth pinning down: lifting a
 * suspension must put back exactly what it took — not a draft, not an event
 * the organizer had taken off sale themselves, and not a night that has
 * already happened. And while it lasts, the people who already paid keep
 * their tickets and still get in.
 */
class OrganizationSuspensionTest extends TestCase
{
    use RefreshDatabase, ReviewsEvents, SupportFixtures;

    private Organization $org;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->admin = $this->staff(PlatformRole::Admin);
        $this->org = $this->organization('Lagos Nights');
        $this->owner = $this->member($this->org, Role::Owner, ['email' => 'owner@lagosnights.test']);
    }

    private function suspension(): Suspension
    {
        return app(Suspension::class);
    }

    private function actAsMember(User $user): void
    {
        Sanctum::actingAs($user->fresh(), [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);
    }

    // --- what comes off sale, and what goes back ------------------------------

    public function test_suspending_takes_off_sale_only_what_was_on_sale_and_remembers_it(): void
    {
        $coming = $this->event($this->org, ['title' => 'Coming']);
        $past = $this->event($this->org, ['title' => 'Past', 'starts_at' => now()->subWeek()]);
        $draft = $this->event($this->org, ['title' => 'Draft', 'status' => 'draft', 'published_at' => null]);
        $pulled = $this->event($this->org, ['title' => 'Pulled by the organizer', 'status' => 'draft']);
        $elsewhere = $this->event($this->organization('Toronto Sound'), ['title' => 'Somebody else’s']);

        $done = $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        $this->assertFalse($done['already']);
        $this->assertEqualsCanonicalizing([$coming->id, $past->id], $done['events']);

        foreach ([$coming, $past] as $event) {
            $event->refresh();
            $this->assertSame('draft', $event->status);
            $this->assertNotNull($event->unpublished_by_suspension_at);
        }

        foreach ([$draft, $pulled] as $event) {
            $this->assertNull($event->fresh()->unpublished_by_suspension_at);
        }

        $this->assertSame('published', $elsewhere->fresh()->status);

        $this->org->refresh();
        $this->assertTrue($this->org->isSuspended());
        $this->assertSame($this->admin->id, $this->org->suspended_by);
        $this->assertSame('Chargebacks on three events in a week.', $this->org->suspension_reason);
        $this->assertFalse($this->org->suspension_reason_shared);
    }

    public function test_suspending_twice_does_not_lose_the_list_or_email_again(): void
    {
        $coming = $this->event($this->org);
        $this->ticketType($coming);

        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');
        $marked = $coming->fresh()->unpublished_by_suspension_at;

        $again = $this->suspension()->suspend($this->org, $this->admin, 'A second click, with other words.');

        $this->assertTrue($again['already']);
        $this->assertSame([], $again['events']);
        $this->assertSame($marked, $coming->fresh()->unpublished_by_suspension_at);
        $this->assertSame('Chargebacks on three events in a week.', $this->org->fresh()->suspension_reason);

        // One entry, one email: the second click did nothing.
        $this->assertSame(1, AuditLog::where('action', 'organization.suspended')->count());
        Mail::assertQueued(OrganizationSuspended::class, 1);

        // And lifting it still finds the event.
        $this->assertSame([$coming->id], $this->suspension()->unsuspend($this->org, $this->admin)['republished']);
    }

    public function test_unsuspending_puts_back_only_the_remembered_events_still_to_come(): void
    {
        $coming = $this->event($this->org, ['title' => 'Coming']);
        $this->ticketType($coming);
        $soon = $this->event($this->org, ['title' => 'Soon', 'starts_at' => now()->addHours(2)]);
        $this->ticketType($soon);
        $pulled = $this->event($this->org, ['title' => 'Pulled', 'status' => 'draft']);
        $this->ticketType($pulled);

        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        // Time passes: one of them happens while the organization is suspended.
        $this->travel(3)->hours();

        $done = $this->suspension()->unsuspend($this->org, $this->admin, 'Chargebacks explained by the bank.');

        $this->assertTrue($done['lifted']);
        $this->assertSame([$coming->id], $done['republished']);
        $this->assertSame([$soon->id => 'already happened'], $done['left']);

        $this->assertSame('published', $coming->fresh()->status);
        $this->assertSame('draft', $soon->fresh()->status);
        $this->assertSame('draft', $pulled->fresh()->status);

        // Nothing is left marked, so a later suspension starts from nothing.
        $this->assertSame(0, Event::whereNotNull('unpublished_by_suspension_at')->count());
        $this->assertFalse($this->org->fresh()->isSuspended());
        $this->assertNull($this->org->fresh()->suspension_reason);

        // A second lift is a no-op.
        $this->assertFalse($this->suspension()->unsuspend($this->org, $this->admin)['lifted']);
        $this->assertSame(1, AuditLog::where('action', 'organization.unsuspended')->count());
    }

    public function test_an_event_cancelled_or_deleted_meanwhile_stays_off_sale(): void
    {
        $cancelled = $this->event($this->org, ['title' => 'Cancelled']);
        $this->ticketType($cancelled);
        $deleted = $this->event($this->org, ['title' => 'Deleted']);
        $this->ticketType($deleted);

        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        $cancelled->fresh()->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $deleted->fresh()->delete();

        $done = $this->suspension()->unsuspend($this->org, $this->admin);

        $this->assertSame([], $done['republished']);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertSame('draft', Event::withTrashed()->find($deleted->id)->status);
        $this->assertEquals([$cancelled->id => 'now cancelled', $deleted->id => 'deleted by the organizer'], $done['left']);
    }

    public function test_an_event_on_sale_without_an_open_ticket_type_goes_back_as_it_was(): void
    {
        // A presale sold only through a hidden type, and a page kept up after
        // its only type closed. Both were on sale. Held to the publish
        // button's test they would stay drafts for good, because the
        // organizer's own button would refuse them the same way.
        $presale = $this->event($this->org, ['title' => 'Presale']);
        $this->ticketType($presale, ['status' => 'hidden']);
        $closed = $this->event($this->org, ['title' => 'Closed']);
        $this->ticketType($closed, ['status' => 'closed']);

        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        $done = $this->suspension()->unsuspend($this->org, $this->admin);

        $this->assertEqualsCanonicalizing([$presale->id, $closed->id], $done['republished']);
        $this->assertSame([], $done['left']);
        $this->assertSame('published', $presale->fresh()->status);
        $this->assertSame('published', $closed->fresh()->status);
    }

    public function test_an_event_the_organizer_takes_off_sale_meanwhile_stays_off_when_lifted(): void
    {
        $kept = $this->event($this->org, ['title' => 'Headliner pulled out']);
        $this->ticketType($kept);
        $back = $this->event($this->org, ['title' => 'Still on']);
        $this->ticketType($back);

        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        $this->actAsMember($this->owner);
        $this->postJson("/api/organizer/events/{$kept->id}/publish", ['status' => 'draft'])->assertOk();

        // The organizer's decision, on the record, and the mark gone with it.
        $this->assertNull($kept->fresh()->unpublished_by_suspension_at);
        $said = AuditLog::where('action', 'event.unpublished')->where('subject_id', $kept->id)->where('actor_id', $this->owner->id)->sole();
        $this->assertTrue($said->metadata['kept_off_after_suspension']);

        $done = $this->suspension()->unsuspend($this->org, $this->admin);

        $this->assertSame([$back->id], $done['republished']);
        $this->assertSame([], $done['left']);
        $this->assertSame('draft', $kept->fresh()->status);
        $this->assertSame('published', $back->fresh()->status);
    }

    public function test_a_takedown_lifted_during_a_suspension_waits_for_the_suspension(): void
    {
        $event = $this->event($this->org);
        $this->ticketType($event);
        $moderation = app(EventModeration::class);

        $moderation->takeDown($event, $this->admin, 'Checking the venue booking first.');
        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        // Lifting the takedown does not put it on sale while suspended…
        $this->assertSame('draft', $moderation->restore($event->fresh(), $this->admin));
        $this->assertNotNull($event->fresh()->unpublished_by_suspension_at);

        // …and the organizers are told it will come back by itself, not to
        // publish it from a console that would refuse them.
        Mail::assertQueued(EventRestored::class, fn (EventRestored $mail) => $mail->hasTo('owner@lagosnights.test')
            && $mail->waitsForSuspension);

        $said = (new EventRestored($event->fresh(), waitsForSuspension: true))->render();
        $this->assertStringContainsString('goes back on sale by itself when the suspension is lifted', $said);
        $this->assertStringNotContainsString('publish it from the console', $said);

        // …it goes back with the others when the suspension is lifted.
        $this->assertSame([$event->id], $this->suspension()->unsuspend($this->org, $this->admin)['republished']);
        $this->assertSame('published', $event->fresh()->status);
    }

    public function test_a_takedown_made_during_a_suspension_goes_back_once_both_are_lifted_in_either_order(): void
    {
        $moderation = app(EventModeration::class);
        $suspensionFirst = $this->event($this->org, ['title' => 'Suspension lifted first']);
        $this->ticketType($suspensionFirst);
        $takedownFirst = $this->event($this->org, ['title' => 'Takedown lifted first']);
        $this->ticketType($takedownFirst);

        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        // Drafts now only because of the suspension: on sale before it.
        $moderation->takeDown($suspensionFirst->fresh(), $this->admin, 'Checking the venue booking first.');
        $moderation->takeDown($takedownFirst->fresh(), $this->admin, 'Checking the venue booking first.');

        $this->assertSame('draft', $moderation->restore($takedownFirst->fresh(), $this->admin));

        $done = $this->suspension()->unsuspend($this->org, $this->admin);

        $this->assertSame([$takedownFirst->id], $done['republished']);
        $this->assertSame([$suspensionFirst->id => 'taken down by myFiesta'], $done['left']);

        $this->assertSame('published', $moderation->restore($suspensionFirst->fresh(), $this->admin));
        $this->assertSame('published', $suspensionFirst->fresh()->status);
        $this->assertSame('published', $takedownFirst->fresh()->status);
    }

    // --- nothing sells ----------------------------------------------------------

    public function test_checkout_is_refused_while_suspended_even_for_an_event_loaded_before(): void
    {
        $event = $this->event($this->org);
        $type = $this->ticketType($event);

        // The buyer's page loaded the event while it was on sale.
        $loaded = Event::find($event->id);

        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        try {
            app(CheckoutService::class)->reserve($loaded, [$type->id => 1], 'buyer@example.test', 'Buyer');
            $this->fail('A suspended organization sold a ticket.');
        } catch (CheckoutException $refused) {
            $this->assertStringContainsString('not selling tickets', $refused->getMessage());
        }

        // On the site the page is gone; a buyer part-way through checkout is
        // told why rather than that the event does not exist.
        $this->getJson("/api/events/{$event->slug}")->assertNotFound();
        $this->postJson("/api/events/{$event->slug}/quote", ['items' => [['ticket_type_id' => $type->id, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This organizer is not selling tickets on myFiesta at the moment. Tickets already bought are not affected.');

        // Lifted, the same basket prices again.
        $this->suspension()->unsuspend($this->org, $this->admin);
        $this->seed(TaxRateSeeder::class);
        $this->postJson("/api/events/{$event->slug}/quote", ['items' => [['ticket_type_id' => $type->id, 'quantity' => 1]]])
            ->assertOk();

        $this->assertSame(0, Order::count());
    }

    public function test_the_door_stops_selling_but_still_lets_ticket_holders_in(): void
    {
        $event = $this->event($this->org);
        $type = $this->ticketType($event);
        $order = $this->paidOrder($event, $type, 1);
        $door = $this->member($this->org, Role::Door);

        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        $this->actAsMember($this->owner);

        $this->postJson("/api/events/{$event->id}/door-sales", [
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
            'method' => 'cash',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', WhileSuspended::withContact(WhileSuspended::SELL));

        $this->postJson("/api/events/{$event->id}/door-quote", [
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertForbidden();

        // The service refuses too, for anything that reaches it another way.
        try {
            app(DoorSales::class)->sell($event->fresh(), [$type->id => 1], 'cash', $this->owner);
            $this->fail('A suspended organization sold at the door.');
        } catch (CheckoutException) {
            $this->assertSame(1, Order::count());
        }

        // Everybody who paid gets in.
        $this->actAsMember($door);

        $this->getJson("/api/organizer/events/{$event->id}")->assertOk();
        $this->getJson("/api/events/{$event->id}/door-list")->assertOk();
        $this->postJson("/api/events/{$event->id}/scan", ['code' => $order->tickets()->first()->code])
            ->assertOk()
            ->assertJsonPath('accepted', true);
    }

    public function test_ticket_holders_are_still_reminded_while_suspended(): void
    {
        $event = $this->event($this->org, ['starts_at' => now()->addHours(72)]);
        $this->paidOrder($event, $this->ticketType($event), 1);
        $reminder = $event->reminders()->create(['offset_minutes' => 48 * 60, 'status' => 'scheduled']);

        // A draft of the organizer's own is not reminded, suspended or not.
        $draft = $this->event($this->org, ['status' => 'draft', 'published_at' => null, 'starts_at' => now()->addHours(72)]);
        $quiet = $draft->reminders()->create(['offset_minutes' => 48 * 60, 'status' => 'scheduled']);

        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        // Due while the suspension lasts. Waiting for the lift would make it
        // too late to send at all.
        $this->travel(25)->hours();
        app(ReminderDispatcher::class)->dispatchDue();

        $this->assertSame('sent', $reminder->fresh()->status);
        Mail::assertQueued(EventReminderMail::class, fn (EventReminderMail $mail) => $mail->hasTo('ada@example.com'));
        $this->assertSame('scheduled', $quiet->fresh()->status);

        // With no page on the site to send them to.
        $said = (new EventReminderMail($reminder->fresh()->load('event'), EmailPreference::forEmail('ada@example.com')))->render();
        $this->assertStringContainsString('Have the code ready at the door', $said);
        $this->assertStringNotContainsString('See the event', $said);
    }

    public function test_tickets_cannot_be_handed_back_for_resale_while_suspended(): void
    {
        $event = $this->event($this->org, ['resale_enabled' => true]);
        $order = $this->paidOrder($event, $this->ticketType($event), 1);

        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        $refusal = app(Resale::class)->refusal($order->tickets()->first(), $event->fresh());

        $this->assertStringContainsString('cannot be handed back', (string) $refusal);
        $this->assertSame('valid', $order->tickets()->first()->status);
    }

    // --- the console ------------------------------------------------------------

    public function test_the_api_refuses_publishing_and_selling_but_reading_stays_open(): void
    {
        $event = $this->event($this->org);
        $this->ticketType($event);

        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        $this->actAsMember($this->owner);

        $this->postJson("/api/organizer/events/{$event->id}/publish", ['status' => 'published'])
            ->assertForbidden()
            ->assertJsonPath('title', 'Organization suspended')
            ->assertJsonPath('message', WhileSuspended::withContact(WhileSuspended::PUBLISH));
        // Nor into the review queue, which is the way on sale now.
        $this->postJson("/api/organizer/events/{$event->id}/submit")
            ->assertForbidden()
            ->assertJsonPath('title', 'Organization suspended')
            ->assertJsonPath('message', WhileSuspended::withContact(WhileSuspended::PUBLISH));
        $this->assertSame('draft', $event->fresh()->status);

        // Keeping it off sale is still the organizer's to say.
        $this->postJson("/api/organizer/events/{$event->id}/publish", ['status' => 'draft'])->assertOk();

        $this->postJson("/api/organizer/events/{$event->id}/waitlist/notify")
            ->assertForbidden()
            ->assertJsonPath('message', WhileSuspended::withContact(WhileSuspended::SELL));

        $this->postJson('/api/organizer/campaigns', ['audience' => 'past_buyers', 'subject' => 'Hi', 'body' => 'Hello'])
            ->assertForbidden();

        // Reading, and looking after people who already bought, stay open.
        $this->getJson('/api/organizer/events')->assertOk();
        $this->getJson("/api/organizer/events/{$event->id}")->assertOk();
        $this->getJson('/api/organizer/payouts')->assertOk();
        $this->patchJson("/api/organizer/events/{$event->id}", ['title' => 'Afro Fest, renamed'])->assertOk();
    }

    public function test_another_organization_is_not_refused(): void
    {
        $other = $this->organization('Toronto Sound');
        $event = $this->event($other, ['status' => 'draft', 'published_at' => null]);
        $this->ticketType($event);
        $both = $this->member($other, Role::Owner);
        $this->org->members()->attach($both->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);

        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        $this->actAsMember($both);

        $this->publishThroughReview($event)->assertOk();
        $this->assertSame('published', $event->fresh()->status);

        // And a stranger poking at the suspended organization's events is
        // refused as on any day, without being told it is suspended.
        $theirs = $this->event($this->organization('Eko Live'));
        $mine = $this->event($this->org, ['status' => 'draft', 'published_at' => null]);
        $this->actAsMember($this->member(Organization::where('name', 'Eko Live')->sole(), Role::Owner));

        $refused = $this->postJson("/api/organizer/events/{$mine->id}/publish", ['status' => 'published']);
        $refused->assertForbidden();
        $this->assertNotSame('Organization suspended', $refused->json('title'));
        $this->assertSame('published', $theirs->fresh()->status);
    }

    public function test_the_console_is_told_it_is_suspended_and_why_only_if_shared(): void
    {
        $this->actAsMember($this->owner);

        $this->getJson('/api/organizer/standing')
            ->assertOk()
            ->assertJsonPath('suspended', false)
            ->assertJsonPath('suspension', null);

        $this->suspension()->suspend($this->org, $this->admin, 'Internal: under review by the fraud team.');

        $this->getJson('/api/organizer/standing')
            ->assertOk()
            ->assertJsonPath('suspended', true)
            ->assertJsonPath('organization.id', $this->org->id)
            ->assertJsonPath('suspension.reason', null)
            ->assertJsonStructure(['suspension' => ['since', 'reason', 'support_email']]);

        $this->suspension()->unsuspend($this->org, $this->admin);
        $this->suspension()->suspend($this->org, $this->admin, 'Your events used artwork you do not own.', shareReason: true);

        // Door staff see it too: they are the ones the publish button refuses least,
        // and the ones most likely to be asked why the page is gone.
        $this->actAsMember($this->member($this->org, Role::Door));

        $this->getJson('/api/organizer/standing')
            ->assertOk()
            ->assertJsonPath('suspension.reason', 'Your events used artwork you do not own.');
    }

    // --- payouts ----------------------------------------------------------------

    public function test_payouts_freeze_waiting_requests_are_held_and_released(): void
    {
        $this->event($this->org);
        LedgerEntry::create(['organization_id' => $this->org->id, 'type' => 'sale', 'amount' => 50_000, 'currency' => 'CAD', 'occurred_at' => now()]);
        OrganizationPayoutDetail::create([
            'organization_id' => $this->org->id,
            'rail' => 'interac',
            'currency' => 'CAD',
            'interac_email' => 'money@lagosnights.test',
            'verified_at' => now(),
            'verification_method' => 'interac_test_transfer',
        ]);

        $requests = app(PayoutRequests::class);
        $waiting = $requests->request($this->org, $this->owner, new Money(20_000, 'CAD'));

        $done = $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');
        $this->assertSame([$waiting->id], $done['held']);

        // Held, not rejected.
        $waiting->refresh();
        $this->assertSame('pending', $waiting->status);
        $this->assertNotNull($waiting->held_at);

        // Nothing new can be asked for…
        $this->actAsMember($this->owner);
        $this->postJson('/api/organizer/payouts/requests', ['amount' => 10_000])
            ->assertForbidden()
            ->assertJsonPath('message', WhileSuspended::withContact(WhileSuspended::PAYOUTS));

        // …the held one cannot be paid…
        $finance = $this->staff(PlatformRole::Finance);

        try {
            $requests->pay($waiting, $finance, new Money(20_000, 'CAD'), 'interac');
            $this->fail('A held request was paid.');
        } catch (PayoutRequestRefused $refused) {
            $this->assertStringContainsString('held', $refused->getMessage());
        }

        // …and nothing can be settled some other way.
        try {
            app(SettlementRecorder::class)->record($this->org, new Money(5_000, 'CAD'), 'interac', null, $finance);
            $this->fail('A suspended organization was paid out.');
        } catch (SettlementRefused) {
            $this->assertSame(0, Settlement::count());
        }

        $unsuspended = $this->suspension()->unsuspend($this->org, $this->admin);
        $this->assertSame([$waiting->id], $unsuspended['released']);

        $waiting->refresh();
        $this->assertSame('pending', $waiting->status);
        $this->assertNull($waiting->held_at);

        $requests->pay($waiting, $finance, new Money(20_000, 'CAD'), 'interac');
        $this->assertSame('paid', $waiting->fresh()->status);
    }

    public function test_a_held_request_can_still_be_withdrawn_by_the_organizer(): void
    {
        $this->event($this->org);
        LedgerEntry::create(['organization_id' => $this->org->id, 'type' => 'sale', 'amount' => 50_000, 'currency' => 'CAD', 'occurred_at' => now()]);
        OrganizationPayoutDetail::create(['organization_id' => $this->org->id, 'rail' => 'interac', 'currency' => 'CAD', 'interac_email' => 'money@lagosnights.test']);

        $waiting = app(PayoutRequests::class)->request($this->org, $this->owner, new Money(20_000, 'CAD'));
        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        $this->actAsMember($this->owner);
        $this->deleteJson("/api/organizer/payouts/requests/{$waiting->id}")->assertOk();

        $this->assertSame('cancelled', $waiting->fresh()->status);

        // A withdrawn request is not brought back by lifting the suspension.
        $this->assertSame([], $this->suspension()->unsuspend($this->org, $this->admin)['released']);
        $this->assertSame('cancelled', $waiting->fresh()->status);
    }

    // --- the record ---------------------------------------------------------------

    public function test_every_step_is_in_the_audit_trail_and_the_owners_are_told(): void
    {
        $manager = $this->member($this->org, Role::Manager, ['email' => 'manager@lagosnights.test']);
        $event = $this->event($this->org);
        $this->ticketType($event);

        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        $suspended = AuditLog::where('action', 'organization.suspended')->sole();
        $this->assertSame($this->admin->id, $suspended->actor_id);
        $this->assertSame($this->org->id, $suspended->organization_id);
        $this->assertSame(Organization::class, $suspended->subject_type);
        $this->assertSame($this->org->id, $suspended->subject_id);
        $this->assertSame([$event->id], $suspended->metadata['events_unpublished']);
        $this->assertSame('Chargebacks on three events in a week.', $suspended->metadata['reason']);

        $unpublished = AuditLog::where('action', 'event.unpublished')->sole();
        $this->assertSame($event->id, $unpublished->subject_id);
        $this->assertSame('organization.suspended', $unpublished->metadata['because']);

        // The owners, not the whole team; and not the reason, which was not shared.
        Mail::assertQueued(OrganizationSuspended::class, fn (OrganizationSuspended $mail) => $mail->hasTo('owner@lagosnights.test')
            && $mail->reason === null
            && $mail->eventsOffSale === 1);
        Mail::assertNotQueued(OrganizationSuspended::class, fn (OrganizationSuspended $mail) => $mail->hasTo($manager->email));

        $this->suspension()->unsuspend($this->org, $this->admin, 'Bank confirmed the chargebacks were errors.');

        $lifted = AuditLog::where('action', 'organization.unsuspended')->sole();
        $this->assertSame($this->admin->id, $lifted->actor_id);
        $this->assertSame([$event->id], $lifted->metadata['events_republished']);
        $this->assertSame('Bank confirmed the chargebacks were errors.', $lifted->metadata['note']);
        $this->assertSame('organization.unsuspended', AuditLog::where('action', 'event.published')->sole()->metadata['because']);

        Mail::assertQueued(OrganizationUnsuspended::class, fn (OrganizationUnsuspended $mail) => $mail->hasTo('owner@lagosnights.test')
            && $mail->backOnSale === ['Afro Fest']);
    }

    public function test_the_reason_goes_to_the_owners_only_when_shared(): void
    {
        $this->suspension()->suspend($this->org, $this->admin, 'Your events used artwork you do not own.', shareReason: true);

        Mail::assertQueued(OrganizationSuspended::class, fn (OrganizationSuspended $mail) => $mail->reason === 'Your events used artwork you do not own.');

        $rendered = (new OrganizationSuspended($this->org->fresh(), 'Your events used artwork you do not own.', 0))->render();
        $this->assertStringContainsString('Your events used artwork you do not own.', $rendered);
        $this->assertStringContainsString('your door still lets them in', $rendered);
    }

    public function test_only_an_administrator_suspends_and_a_reason_is_needed(): void
    {
        $event = $this->event($this->org);

        foreach ([PlatformRole::Support, PlatformRole::Finance] as $role) {
            try {
                $this->suspension()->suspend($this->org, $this->staff($role), 'Support should not be able to do this.');
                $this->fail($role->value.' suspended an organization.');
            } catch (StaffActionRefused) {
                // Refused.
            }
        }

        try {
            $this->suspension()->suspend($this->org, $this->admin, 'Because');
            $this->fail('A suspension went through without a reason.');
        } catch (StaffActionRefused) {
            // Refused.
        }

        // An organizer is not staff at all.
        try {
            $this->suspension()->suspend($this->org, $this->owner, 'Suspending my own organization.');
            $this->fail('An organizer suspended an organization.');
        } catch (StaffActionRefused) {
            // Refused.
        }

        $this->assertFalse($this->org->fresh()->isSuspended());
        $this->assertSame('published', $event->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'organization.suspended')->count());
        Mail::assertNothingQueued();

        // Lifting is the administrator's too.
        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');

        $this->expectException(StaffActionRefused::class);
        $this->suspension()->unsuspend($this->org, $this->staff(PlatformRole::Support));
    }

    public function test_a_type_kept_on_sale_is_not_invented(): void
    {
        // An invitation needs no ticket on sale to go back up.
        $invitation = $this->event($this->org, ['kind' => 'invitation']);

        $this->suspension()->suspend($this->org, $this->admin, 'Chargebacks on three events in a week.');
        $this->assertSame([$invitation->id], $this->suspension()->unsuspend($this->org, $this->admin)['republished']);
        $this->assertSame(0, TicketType::count());
    }
}
