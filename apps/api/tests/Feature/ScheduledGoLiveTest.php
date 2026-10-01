<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Filament\Resources\Events\Pages\ReviewEvent;
use App\Mail\EventAnnouncedMail;
use App\Mail\EventApproved;
use App\Mail\EventAwaitingReview;
use App\Mail\EventScheduledSale;
use App\Mail\EventSubmittedForReview;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventReview;
use App\Models\Organization;
use App\Models\OrganizationFollow;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Events\EventReviews;
use App\Services\Events\ScheduledGoLive;
use App\Services\Organizations\Suspension;
use App\Services\StaffSupport\EventModeration;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A night that goes on sale at a time the organizer set.
 *
 * The organizer sets the time and sends the night for review as usual.
 * Approved before then, it waits as a draft with its approval standing, and
 * events:go-live sends it at that time as the member who set it: on sale as
 * approved, or back through review if it changed meanwhile. What has to hold
 * is that nothing goes on sale that pressing the button then would not have
 * put on sale — not for a suspended organization, not after a takedown, not
 * for a member who has lost the right — and that nothing happens twice.
 */
class ScheduledGoLiveTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $manager;

    private User $staff;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // A Thursday afternoon in Toronto, a month before the night.
        $this->travelTo(CarbonImmutable::parse('2026-10-01 16:00:00', 'UTC'));

        $this->org = Organization::create([
            'name' => 'Lagos Nights',
            'slug' => 'lagos-nights',
            'contact_email' => 'hello@lagosnights.test',
        ]);

        $this->member(Role::Owner, 'owner@lagosnights.test');
        $this->manager = $this->member(Role::Manager, 'manager@lagosnights.test');
        $this->member(Role::Marketing, 'marketing@lagosnights.test');

        $this->staff = User::factory()->create([
            'email' => 'support@myfiesta.test',
            'platform_role' => PlatformRole::Support,
            'email_verified_at' => now(),
        ]);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'description' => '<p>Afrobeats, highlife and amapiano until late.</p>',
            'currency' => 'CAD',
            'starts_at' => CarbonImmutable::parse('2026-10-30 21:00:00', 'America/Toronto'),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'draft',
        ]);

        TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'quantity_available' => 200,
            'status' => 'on_sale',
        ]);

        $this->as($this->manager);
    }

    private function member(Role $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => now()]);

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    /** 10am in Toronto on 9 October, as the console sends it: with its offset. */
    private function setTime(string $local = '2026-10-09 10:00:00')
    {
        return $this->patchJson("/api/organizer/events/{$this->event->id}", [
            'publish_at' => CarbonImmutable::parse($local, 'America/Toronto')->toIso8601String(),
        ]);
    }

    private function sendAndApprove(): string
    {
        $this->postJson("/api/organizer/events/{$this->event->id}/submit")->assertOk()->assertJsonPath('status', 'in_review');

        return app(EventReviews::class)->approve($this->event->fresh(), $this->staff);
    }

    private function atTheTime(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00:30', 'America/Toronto'));
    }

    private function goLive(): void
    {
        $this->artisan('events:go-live')->assertSuccessful();
    }

    // --- setting the time ----------------------------------------------------------

    public function test_the_time_is_kept_and_the_console_reads_it_back(): void
    {
        $this->setTime()->assertOk();

        $event = $this->event->fresh();
        $this->assertSame('2026-10-09T14:00:00+00:00', $event->publish_at->toIso8601String());
        $this->assertSame($this->manager->id, $event->publish_scheduled_by);

        $this->getJson("/api/organizer/events/{$this->event->id}")
            ->assertOk()
            ->assertJsonPath('publish_at', '2026-10-09T14:00:00+00:00');

        $this->assertSame(1, AuditLog::where('action', 'event.sale_time_set')->count());
    }

    public function test_a_time_without_an_offset_is_the_venues(): void
    {
        $this->patchJson("/api/organizer/events/{$this->event->id}", ['publish_at' => '2026-10-09T10:00'])->assertOk();

        // 10am in Toronto, which is 2pm in UTC in October — not 10am UTC.
        $this->assertSame('2026-10-09 14:00', $this->event->fresh()->publish_at->format('Y-m-d H:i'));
    }

    public function test_the_time_has_to_be_to_come_and_before_the_night(): void
    {
        $this->setTime('2026-09-30 10:00:00')
            ->assertStatus(422)
            ->assertJsonPath('errors.publish_at.0', 'Choose a time that has not passed yet.');

        $this->setTime('2026-10-30 22:00:00')
            ->assertStatus(422)
            ->assertJsonPath('errors.publish_at.0', 'Choose a time before the event starts.');

        $this->assertNull($this->event->fresh()->publish_at);
    }

    public function test_the_night_cannot_be_moved_before_its_time(): void
    {
        $this->setTime()->assertOk();

        $this->patchJson("/api/organizer/events/{$this->event->id}", [
            'starts_at' => CarbonImmutable::parse('2026-10-08 21:00:00', 'America/Toronto')->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.starts_at.0', 'It is set to go on sale after that. Move the time it goes on sale first.');
    }

    public function test_only_somebody_who_can_put_it_on_sale_sets_the_time(): void
    {
        $marketing = User::where('email', 'marketing@lagosnights.test')->sole();
        $this->as($marketing);

        $this->setTime()->assertForbidden();
        $this->assertNull($this->event->fresh()->publish_at);
    }

    public function test_setting_the_time_waits_for_a_proved_address(): void
    {
        $this->manager->forceFill(['email_verified_at' => null])->save();
        $this->as($this->manager);

        $this->setTime()->assertStatus(403)->assertJsonPath('code', 'email_unverified');
        $this->assertNull($this->event->fresh()->publish_at);
    }

    public function test_a_night_on_sale_has_no_time_to_set(): void
    {
        $this->event->update(['status' => 'published', 'published_at' => now()]);

        $this->setTime()->assertStatus(422)->assertJsonPath('message', 'This event is already on sale.');
    }

    public function test_the_time_alone_can_move_while_it_waits_for_review(): void
    {
        $this->setTime()->assertOk();
        $this->postJson("/api/organizer/events/{$this->event->id}/submit")->assertOk()->assertJsonPath('status', 'in_review');

        $this->setTime('2026-10-10 12:00:00')->assertOk();
        $this->assertSame('2026-10-10 16:00', $this->event->fresh()->publish_at->format('Y-m-d H:i'));

        // Anything a buyer sees is still frozen.
        $this->patchJson("/api/organizer/events/{$this->event->id}", ['publish_at' => null, 'title' => 'Changed'])
            ->assertStatus(423);
    }

    // --- approved before its time ------------------------------------------------

    public function test_approved_before_its_time_it_stays_a_draft(): void
    {
        $fan = User::factory()->create(['email' => 'fan@example.com']);
        OrganizationFollow::create(['user_id' => $fan->id, 'organization_id' => $this->org->id]);

        $this->setTime()->assertOk();

        $this->assertSame('draft', $this->sendAndApprove());

        $event = $this->event->fresh();
        $this->assertSame('draft', $event->status);
        $this->assertNull($event->published_at);
        $this->assertNotNull($event->approved_fingerprint);
        $this->assertNotNull($event->publish_at);

        // Its followers hear when it goes on sale, not now.
        Mail::assertNotQueued(EventAnnouncedMail::class);
        Mail::assertQueued(EventApproved::class, fn (EventApproved $mail) => $mail->hasTo('owner@lagosnights.test')
            && $mail->goesOnSaleAt !== null
            && str_contains($mail->render(), 'Friday 9 October 2026, 10:00am EDT'));

        $this->getJson("/api/organizer/events/{$this->event->id}")
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('review.on_submit', 'publish');
    }

    public function test_at_its_time_it_goes_on_sale_and_the_organizers_are_told(): void
    {
        $fan = User::factory()->create(['email' => 'fan@example.com']);
        OrganizationFollow::create(['user_id' => $fan->id, 'organization_id' => $this->org->id]);

        $this->setTime()->assertOk();
        $this->sendAndApprove();

        // A minute early, nothing happens.
        $this->travelTo(CarbonImmutable::parse('2026-10-09 09:59:00', 'America/Toronto'));
        $this->goLive();
        $this->assertSame('draft', $this->event->fresh()->status);

        $this->atTheTime();
        $this->goLive();

        $event = $this->event->fresh();
        $this->assertSame('published', $event->status);
        $this->assertNotNull($event->published_at);
        $this->assertNull($event->publish_at);
        $this->assertNull($event->publish_scheduled_by);

        Mail::assertQueued(EventScheduledSale::class, fn (EventScheduledSale $mail) => $mail->hasTo('owner@lagosnights.test')
            && $mail->outcome === EventScheduledSale::ON_SALE);
        Mail::assertQueued(EventScheduledSale::class, fn (EventScheduledSale $mail) => $mail->hasTo('manager@lagosnights.test'));
        Mail::assertQueued(EventAnnouncedMail::class, fn ($mail) => $mail->hasTo('fan@example.com'));

        // As the member who set the time, and approved once only, by staff.
        $this->assertSame($this->manager->id, AuditLog::where('action', 'event.published')->sole()->actor_id);
        $this->assertSame(['review'], EventReview::where('event_id', $event->id)->where('action', 'approved')->pluck('via')->all());
    }

    public function test_going_live_twice_sends_nothing_twice(): void
    {
        $this->setTime()->assertOk();
        $this->sendAndApprove();
        $this->atTheTime();

        $this->goLive();
        $this->goLive();
        app(ScheduledGoLive::class)->goLive($this->event->fresh());

        $this->assertSame('published', $this->event->fresh()->status);
        Mail::assertQueued(EventScheduledSale::class, 2); // owner and manager, once each
        $this->assertSame(1, AuditLog::where('action', 'event.published')->count());
    }

    public function test_taken_off_sale_later_it_is_not_put_back_by_a_time_gone_by(): void
    {
        $this->setTime()->assertOk();
        $this->sendAndApprove();
        $this->atTheTime();
        $this->goLive();

        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'draft'])->assertOk();
        $this->goLive();

        $this->assertSame('draft', $this->event->fresh()->status);
    }

    public function test_changed_since_it_was_approved_it_goes_for_review_at_its_time(): void
    {
        $this->setTime()->assertOk();
        $this->sendAndApprove();

        $this->patchJson("/api/organizer/events/{$this->event->id}", ['title' => 'Afro Fest: special guest'])->assertOk();

        $this->atTheTime();
        $this->goLive();

        $event = $this->event->fresh();
        $this->assertSame('in_review', $event->status);
        $this->assertNull($event->publish_at);

        Mail::assertQueued(EventScheduledSale::class, fn (EventScheduledSale $mail) => $mail->hasTo('owner@lagosnights.test')
            && $mail->outcome === EventScheduledSale::IN_REVIEW);
        Mail::assertQueued(EventAwaitingReview::class, fn ($mail) => $mail->hasTo('support@myfiesta.test'));
        // One email about it, not two.
        Mail::assertNotQueued(EventSubmittedForReview::class, fn ($mail) => $mail->hasTo('owner@lagosnights.test')
            && $mail->event->title === 'Afro Fest: special guest');

        // Approved now, its time has gone: it goes straight on sale.
        $other = User::factory()->create(['platform_role' => PlatformRole::Admin, 'email_verified_at' => now()]);
        $this->assertSame('published', app(EventReviews::class)->approve($event, $other));
    }

    public function test_never_sent_for_review_it_goes_for_review_at_its_time(): void
    {
        $this->setTime()->assertOk();
        $this->atTheTime();
        $this->goLive();

        $this->assertSame('in_review', $this->event->fresh()->status);
        Mail::assertQueued(EventScheduledSale::class, fn (EventScheduledSale $mail) => $mail->outcome === EventScheduledSale::IN_REVIEW);
    }

    public function test_sent_back_by_staff_it_is_not_sent_again_unchanged_at_its_time(): void
    {
        $this->setTime()->assertOk();
        $this->postJson("/api/organizer/events/{$this->event->id}/submit")->assertOk()->assertJsonPath('status', 'in_review');

        app(EventReviews::class)->reject($this->event->fresh(), $this->staff, 'The poster says a different date from the listing.');

        $event = $this->event->fresh();
        $this->assertSame('draft', $event->status);
        $this->assertNull($event->publish_at);
        $this->assertNull($event->publish_scheduled_by);
        $this->assertSame(
            CarbonImmutable::parse('2026-10-09 10:00:00', 'America/Toronto')->toIso8601String(),
            CarbonImmutable::parse(AuditLog::where('action', 'event.rejected')->sole()->metadata['sale_time_dropped'])->timezone('America/Toronto')->toIso8601String(),
        );

        $this->atTheTime();
        $this->goLive();

        $this->assertSame('draft', $this->event->fresh()->status);
        Mail::assertNotQueued(EventScheduledSale::class);
    }

    public function test_the_organizer_can_put_it_on_sale_before_its_time(): void
    {
        $this->setTime()->assertOk();
        $this->sendAndApprove();

        $this->postJson("/api/organizer/events/{$this->event->id}/submit")
            ->assertOk()
            ->assertJsonPath('status', 'published')
            ->assertJsonPath('message', 'On sale. Nothing has changed since it was approved, so it did not need another review.');

        $this->assertNull($this->event->fresh()->publish_at);
    }

    public function test_clearing_the_time_keeps_the_approval(): void
    {
        $this->setTime()->assertOk();
        $this->sendAndApprove();

        $this->patchJson("/api/organizer/events/{$this->event->id}", ['publish_at' => null])->assertOk();
        $this->assertNull($this->event->fresh()->publish_at);

        $this->atTheTime();
        $this->goLive();
        $this->assertSame('draft', $this->event->fresh()->status);

        $this->postJson("/api/organizer/events/{$this->event->id}/submit")->assertOk()->assertJsonPath('status', 'published');
    }

    // --- asked again at its time ---------------------------------------------------

    public function test_a_suspended_organization_does_not_go_on_sale_until_it_is_lifted(): void
    {
        $this->setTime()->assertOk();
        $this->sendAndApprove();

        $admin = User::factory()->create(['platform_role' => PlatformRole::Admin, 'email_verified_at' => now()]);
        app(Suspension::class)->suspend($this->org, $admin, 'Chargebacks on three events in a week.');

        $this->atTheTime();
        $this->goLive();

        $event = $this->event->fresh();
        $this->assertSame('draft', $event->status);
        $this->assertNotNull($event->publish_at, 'The time is kept for when the suspension is lifted.');
        Mail::assertNotQueued(EventScheduledSale::class);

        app(Suspension::class)->unsuspend($this->org, $admin);
        $this->goLive();

        $this->assertSame('published', $this->event->fresh()->status);
    }

    public function test_approved_while_suspended_it_still_waits_for_its_time(): void
    {
        $this->setTime()->assertOk();
        $this->postJson("/api/organizer/events/{$this->event->id}/submit")->assertOk();

        $admin = User::factory()->create(['platform_role' => PlatformRole::Admin, 'email_verified_at' => now()]);
        app(Suspension::class)->suspend($this->org, $admin, 'Chargebacks on three events in a week.');

        $this->assertSame('draft', app(EventReviews::class)->approve($this->event->fresh(), $this->staff));

        // Not marked for the suspension: lifting it before the time must not
        // put the night on sale early.
        $this->assertNull($this->event->fresh()->unpublished_by_suspension_at);
        app(Suspension::class)->unsuspend($this->org, $admin);
        $this->assertSame('draft', $this->event->fresh()->status);

        $this->atTheTime();
        $this->goLive();
        $this->assertSame('published', $this->event->fresh()->status);
    }

    public function test_a_night_taken_down_is_not_put_on_sale(): void
    {
        $this->setTime()->assertOk();
        $this->sendAndApprove();

        $admin = User::factory()->create(['platform_role' => PlatformRole::Admin, 'email_verified_at' => now()]);
        app(EventModeration::class)->takeDown($this->event->fresh(), $admin, 'The poster uses another promoter’s artwork.');

        $this->atTheTime();
        $this->goLive();

        $this->assertSame('draft', $this->event->fresh()->status);
        $this->assertNotNull($this->event->fresh()->taken_down_at);
        Mail::assertNotQueued(EventScheduledSale::class);
    }

    public function test_a_member_who_can_no_longer_put_events_on_sale_does_not_put_this_one_on_sale(): void
    {
        $this->setTime()->assertOk();
        $this->sendAndApprove();

        // Moved to marketing, who cannot put events on sale.
        $this->org->members()->updateExistingPivot($this->manager->id, ['role' => Role::Marketing->value]);

        $this->atTheTime();
        $this->goLive();

        $event = $this->event->fresh();
        $this->assertSame('draft', $event->status);
        $this->assertNull($event->publish_at, 'Dropped, so it is not tried every minute.');

        Mail::assertQueued(EventScheduledSale::class, fn (EventScheduledSale $mail) => $mail->hasTo('owner@lagosnights.test')
            && $mail->outcome === EventScheduledSale::NOT_SENT
            && str_contains($mail->reasons[0], 'can no longer put events on sale'));
        $this->assertSame(1, AuditLog::where('action', 'event.scheduled_sale_not_sent')->count());

        // Once.
        $this->goLive();
        Mail::assertQueued(EventScheduledSale::class, 1);
    }

    public function test_a_night_that_is_not_ready_is_not_sent_and_the_organizers_hear_why(): void
    {
        $this->setTime()->assertOk();
        TicketType::where('event_id', $this->event->id)->update(['status' => 'hidden']);

        $this->atTheTime();
        $this->goLive();

        $this->assertSame('draft', $this->event->fresh()->status);
        $this->assertNull($this->event->fresh()->publish_at);
        Mail::assertQueued(EventScheduledSale::class, fn (EventScheduledSale $mail) => $mail->outcome === EventScheduledSale::NOT_SENT
            && $mail->reasons === ['Add at least one ticket on sale.']);
    }

    public function test_a_member_whose_address_is_no_longer_proved_does_not_put_it_on_sale(): void
    {
        $this->setTime()->assertOk();
        $this->sendAndApprove();

        // Changed their address since, and not yet confirmed the new one.
        $this->manager->forceFill(['email_verified_at' => null])->save();

        $this->atTheTime();
        $this->goLive();

        $this->assertSame('draft', $this->event->fresh()->status);
        $this->assertNull($this->event->fresh()->publish_at);
        Mail::assertQueued(EventScheduledSale::class, fn (EventScheduledSale $mail) => $mail->outcome === EventScheduledSale::NOT_SENT
            && str_contains($mail->reasons[0], 'has not confirmed their email address'));
        Mail::assertNotQueued(EventAnnouncedMail::class);
    }

    public function test_a_time_cleared_after_the_run_picked_the_night_is_honoured(): void
    {
        $this->setTime()->assertOk();
        $this->sendAndApprove();
        $this->atTheTime();

        // The run reads its nights first and gets to this one later.
        $picked = app(ScheduledGoLive::class)->due()->sole();

        $this->patchJson("/api/organizer/events/{$this->event->id}", ['publish_at' => null])->assertOk();

        $this->assertSame(ScheduledGoLive::LEFT, app(ScheduledGoLive::class)->goLive($picked));
        $this->assertSame('draft', $this->event->fresh()->status);
        Mail::assertNotQueued(EventScheduledSale::class);
        Mail::assertNotQueued(EventAnnouncedMail::class);
    }

    public function test_a_time_moved_later_after_the_run_picked_the_night_is_kept(): void
    {
        $this->setTime()->assertOk();
        $this->sendAndApprove();
        $this->atTheTime();

        $picked = app(ScheduledGoLive::class)->due()->sole();

        $this->setTime('2026-10-09 18:00:00')->assertOk();

        $this->assertSame(ScheduledGoLive::LEFT, app(ScheduledGoLive::class)->goLive($picked));

        $event = $this->event->fresh();
        $this->assertSame('draft', $event->status);
        $this->assertTrue($event->publish_at->equalTo(CarbonImmutable::parse('2026-10-09 18:00:00', 'America/Toronto')));
        Mail::assertNotQueued(EventScheduledSale::class);

        // And at the new time it goes.
        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00:30', 'America/Toronto'));
        $this->goLive();
        $this->assertSame('published', $this->event->fresh()->status);
    }

    public function test_taken_back_from_review_after_its_time_it_is_not_sent_again(): void
    {
        $this->setTime()->assertOk();
        $this->postJson("/api/organizer/events/{$this->event->id}/submit")->assertOk()->assertJsonPath('status', 'in_review');

        // Still waiting for review when its time comes, and taken back to change.
        $this->atTheTime();
        $this->postJson("/api/organizer/events/{$this->event->id}/withdraw")
            ->assertOk()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('message', fn (string $said) => str_contains($said, 'has passed'));

        $this->goLive();

        $event = $this->event->fresh();
        $this->assertSame('draft', $event->status);
        $this->assertNull($event->publish_at);
        Mail::assertNotQueued(EventScheduledSale::class);
        $this->assertNotNull(AuditLog::where('action', 'event.withdrawn_from_review')->sole()->metadata['sale_time_dropped'] ?? null);
    }

    public function test_taken_back_from_review_before_its_time_it_keeps_it(): void
    {
        $this->setTime()->assertOk();
        $this->postJson("/api/organizer/events/{$this->event->id}/submit")->assertOk();
        $this->postJson("/api/organizer/events/{$this->event->id}/withdraw")->assertOk()->assertJsonPath('status', 'draft');

        $this->assertNotNull($this->event->fresh()->publish_at);
    }

    public function test_a_takedown_lifted_after_its_time_leaves_the_night_a_draft(): void
    {
        $fan = User::factory()->create(['email' => 'fan@example.com']);
        OrganizationFollow::create(['user_id' => $fan->id, 'organization_id' => $this->org->id]);

        $this->setTime()->assertOk();
        $this->sendAndApprove();

        $admin = User::factory()->create(['platform_role' => PlatformRole::Admin, 'email_verified_at' => now()]);
        app(EventModeration::class)->takeDown($this->event->fresh(), $admin, 'The poster uses another promoter’s artwork.');
        $this->assertNull($this->event->fresh()->publish_at, 'Dropped with the takedown.');

        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'America/Toronto'));
        $this->goLive();

        $this->assertSame('draft', app(EventModeration::class)->restore($this->event->fresh(), $admin));
        $this->goLive();

        $this->assertSame('draft', $this->event->fresh()->status);
        Mail::assertNotQueued(EventScheduledSale::class);
        Mail::assertNotQueued(EventAnnouncedMail::class);
        $this->assertNotNull(AuditLog::where('action', 'event.taken_down')->sole()->metadata['sale_time_dropped'] ?? null);
    }

    public function test_the_review_page_says_an_approval_before_its_time_waits_for_it(): void
    {
        $this->setTime()->assertOk();
        $this->postJson("/api/organizer/events/{$this->event->id}/submit")->assertOk();

        $this->actingAs($this->staff);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewEvent::class, ['record' => $this->event->getKey()])
            ->callAction('approveEvent')
            ->assertHasNoActionErrors()
            ->assertNotified(Notification::make()
                ->title('Approved')
                ->body('It goes on sale at the time the organizer set. The organizer has been emailed.')
                ->success());

        $this->assertSame('draft', $this->event->fresh()->status);
        $this->assertNotNull($this->event->fresh()->publish_at);
    }

    public function test_the_job_runs_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('events:go-live')->assertSuccessful();
    }
}
