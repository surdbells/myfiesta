<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Exceptions\CheckoutException;
use App\Filament\Pages\EventReviewQueue;
use App\Filament\Resources\Events\Pages\ReviewEvent;
use App\Mail\EventAnnouncedMail;
use App\Mail\EventApproved;
use App\Mail\EventAwaitingReview;
use App\Mail\EventRejected;
use App\Mail\EventSubmittedForReview;
use App\Mail\EventWithdrawnFromReview;
use App\Models\AddOn;
use App\Models\AuditLog;
use App\Models\Code;
use App\Models\CodeBatch;
use App\Models\Event;
use App\Models\EventImage;
use App\Models\EventQuestion;
use App\Models\EventReview;
use App\Models\EventSeries;
use App\Models\Organization;
use App\Models\OrganizationFollow;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use App\Services\Events\EventReviews;
use App\Services\Events\EventSnapshot;
use App\Services\Organizations\Suspension;
use App\Services\StaffSupport\EventModeration;
use App\Services\StaffSupport\StaffActionRefused;
use Database\Seeders\TaxRateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\ReviewsEvents;
use Tests\TestCase;

/**
 * Every event is looked at before it goes on sale.
 *
 * "All events must go through approval cycles before they become live,
 * transactional notifications for the entire cycles, when an event is
 * declined after review, reason must be provided and sent with the
 * notification." Draft, in review — frozen while it waits — then on sale when
 * staff approve it, or a draft again with the reviewer's reason when they do
 * not. What this pins down is the whole of that: every move and every
 * refusal, the freeze on each endpoint that changes what a buyer sees, who is
 * told what, who may decide, and the few moves that skip the queue because
 * the content was already approved.
 */
class EventReviewCycleTest extends TestCase
{
    use RefreshDatabase, ReviewsEvents;

    private const REASON = 'The poster is from last year’s event. Upload this year’s, then send it again.';

    private Organization $org;

    private User $owner;

    private User $manager;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->org = Organization::create([
            'name' => 'Lagos Nights',
            'slug' => 'lagos-nights',
            'contact_email' => 'hello@lagosnights.test',
        ]);

        $this->owner = $this->member(Role::Owner, 'owner@lagosnights.test');
        $this->manager = $this->member(Role::Manager, 'manager@lagosnights.test');
        $this->member(Role::Door, 'door@lagosnights.test');
        $this->member(Role::Marketing, 'marketing@lagosnights.test');

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'description' => '<p>Afrobeats, highlife and amapiano until late.</p>',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'draft',
        ]);

        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'quantity_available' => 200,
            'status' => 'on_sale',
        ]);

        $this->asOrganizer($this->owner);
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

    private function asOrganizer(User $user): void
    {
        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    private function staff(PlatformRole $role, string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'platform_role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    private function submit()
    {
        return $this->postJson("/api/organizer/events/{$this->event->id}/submit");
    }

    private function reviews(): EventReviews
    {
        return app(EventReviews::class);
    }

    /**
     * Another night of the same organization, ready to send, with one ticket.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function anotherEvent(string $slug, array $overrides = [], string $ticket = 'on_sale'): Event
    {
        $event = Event::create([
            ...$this->event->only(['organization_id', 'title', 'description', 'currency', 'timezone', 'city', 'subdivision', 'country']),
            'slug' => $slug,
            'starts_at' => now()->addMonths(2),
            'status' => 'draft',
            ...$overrides,
        ]);

        TicketType::create(['event_id' => $event->id, 'name' => 'General', 'price_amount' => 3000, 'status' => $ticket]);

        return $event;
    }

    // --- sending it --------------------------------------------------------------

    public function test_a_new_event_starts_as_a_draft(): void
    {
        $this->postJson('/api/organizer/events', [
            'organization_id' => $this->org->id,
            'title' => 'Highlife Night',
            'currency' => 'CAD',
            'starts_at' => now()->addMonths(2)->toIso8601String(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
        ])->assertCreated();

        $this->assertSame('draft', Event::where('title', 'Highlife Night')->sole()->status);
    }

    public function test_submitting_sends_it_for_review_and_tells_the_organizers_and_the_reviewers(): void
    {
        $this->staff(PlatformRole::Admin, 'admin@myfiesta.test');
        $this->staff(PlatformRole::Support, 'support@myfiesta.test');
        $this->staff(PlatformRole::Finance, 'finance@myfiesta.test');
        $follower = User::factory()->create(['email' => 'fan@example.com']);
        OrganizationFollow::create(['user_id' => $follower->id, 'organization_id' => $this->org->id]);

        $this->submit()
            ->assertOk()
            ->assertJsonPath('status', 'in_review')
            ->assertJsonPath('outcome', 'in_review');

        $event = $this->event->fresh();
        $this->assertSame('in_review', $event->status);
        $this->assertNotNull($event->submitted_at);
        $this->assertNull($event->published_at);

        // The people at the organization who can put events on sale.
        foreach (['owner@lagosnights.test', 'manager@lagosnights.test'] as $email) {
            Mail::assertQueued(EventSubmittedForReview::class, fn ($mail) => $mail->hasTo($email));
        }
        foreach (['door@lagosnights.test', 'marketing@lagosnights.test'] as $email) {
            Mail::assertNotQueued(EventSubmittedForReview::class, fn ($mail) => $mail->hasTo($email));
        }

        // The staff who review: administrators and support, by default.
        Mail::assertQueued(EventAwaitingReview::class, fn ($mail) => $mail->hasTo('admin@myfiesta.test') && $mail->title === 'Afro Fest');
        Mail::assertQueued(EventAwaitingReview::class, fn ($mail) => $mail->hasTo('support@myfiesta.test'));
        Mail::assertNotQueued(EventAwaitingReview::class, fn ($mail) => $mail->hasTo('finance@myfiesta.test'));

        // Replies reach a person.
        Mail::assertQueued(EventSubmittedForReview::class, fn ($mail) => $mail->hasReplyTo(config('mail.support.address') ?: config('mail.from.address')));

        // Followers hear about it when it is approved, never when it is sent.
        Mail::assertNotQueued(EventAnnouncedMail::class);

        $this->assertSame($this->owner->id, AuditLog::where('action', 'event.submitted')->sole()->actor_id);
        $this->assertSame(['submitted'], $event->reviews()->pluck('action')->all());
    }

    public function test_the_reviewers_can_be_a_configured_inbox_instead(): void
    {
        config(['events.review.notify' => ['Reviews@MyFiesta.test']]);
        $this->staff(PlatformRole::Admin, 'admin@myfiesta.test');

        $this->submit()->assertOk();

        Mail::assertQueued(EventAwaitingReview::class, fn ($mail) => $mail->hasTo('reviews@myfiesta.test'));
        Mail::assertNotQueued(EventAwaitingReview::class, fn ($mail) => $mail->hasTo('admin@myfiesta.test'));
    }

    public function test_only_somebody_who_may_publish_can_send_it(): void
    {
        $this->asOrganizer($this->member(Role::Marketing, 'promoter@lagosnights.test'));

        $this->submit()->assertForbidden();

        $this->asOrganizer($this->manager);

        $this->submit()->assertOk()->assertJsonPath('status', 'in_review');
    }

    public function test_an_event_that_is_not_ready_is_refused_with_every_reason_at_once(): void
    {
        $this->event->update(['description' => '<p> </p>', 'starts_at' => now()->subDay()]);
        $this->type->update(['status' => 'closed']);

        $this->submit()
            ->assertStatus(422)
            ->assertJsonPath('reasons', [
                'Write a description, so people know what they are buying a ticket to.',
                'The start date has passed. Change the date.',
                'Add at least one ticket on sale.',
            ]);

        $this->assertSame('draft', $this->event->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_a_cancelled_or_taken_down_event_is_not_sent(): void
    {
        $this->event->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->submit()->assertStatus(422)->assertJsonPath('message', 'A cancelled event cannot go back on sale. Copy it to a new date instead.');

        $this->event->update(['status' => 'draft', 'cancelled_at' => null, 'taken_down_at' => now(), 'taken_down_reason' => 'Counterfeit listing.']);
        $this->submit()->assertStatus(422);

        $this->assertSame(0, EventReview::count());
    }

    public function test_sending_twice_sends_one_review(): void
    {
        $this->submit()->assertOk();
        $this->submit()->assertOk()->assertJsonPath('outcome', 'already');

        $this->assertSame(1, EventReview::where('action', 'submitted')->count());
        Mail::assertQueued(EventSubmittedForReview::class, 2); // owner and manager, once each
    }

    // --- frozen while it waits ---------------------------------------------------

    public function test_every_change_a_buyer_would_see_is_refused_while_it_waits(): void
    {
        $id = $this->event->id;
        $addOn = AddOn::create(['event_id' => $id, 'name' => 'Table', 'price_amount' => 20000, 'status' => 'on_sale']);
        $question = EventQuestion::create(['event_id' => $id, 'label' => 'Dietary needs', 'type' => 'text', 'required' => false, 'per_attendee' => false]);
        $image = EventImage::create(['event_id' => $id, 'kind' => 'gallery', 'path' => 'events/x/a.jpg', 'position' => 0]);
        $batch = CodeBatch::create(['organization_id' => $this->org->id, 'event_id' => $id, 'name' => 'Flyers', 'prefix' => 'FLY', 'quantity' => 0]);
        $code = Code::create(['organization_id' => $this->org->id, 'event_id' => $id, 'code' => 'EARLY10', 'discount_type' => 'percentage', 'discount_value' => 1000, 'is_active' => true]);

        $this->submit()->assertOk();

        $locked = [
            ['patch', "/api/organizer/events/{$id}", ['title' => 'Something else']],
            ['post', "/api/organizer/events/{$id}/ticket-types", ['name' => 'VIP', 'price_amount' => 9000]],
            ['patch', "/api/organizer/events/{$id}/ticket-types/{$this->type->id}", ['price_amount' => 1]],
            ['delete', "/api/organizer/events/{$id}/ticket-types/{$this->type->id}", []],
            ['post', "/api/organizer/events/{$id}/ticket-types/order", ['ids' => [$this->type->id]]],
            ['post', "/api/organizer/events/{$id}/add-ons", ['name' => 'Bottle', 'price_amount' => 9000]],
            ['patch', "/api/organizer/events/{$id}/add-ons/{$addOn->id}", ['price_amount' => 1]],
            ['delete', "/api/organizer/events/{$id}/add-ons/{$addOn->id}", []],
            ['post', "/api/organizer/events/{$id}/add-ons/order", ['ids' => [$addOn->id]]],
            ['post', "/api/organizer/events/{$id}/questions", ['label' => 'Size', 'type' => 'text']],
            ['patch', "/api/organizer/events/{$id}/questions/{$question->id}", ['label' => 'Allergies']],
            ['delete', "/api/organizer/events/{$id}/questions/{$question->id}", []],
            ['post', "/api/organizer/events/{$id}/questions/order", ['ids' => [$question->id]]],
            ['post', "/api/organizer/events/{$id}/images", ['kind' => 'gallery']],
            ['patch', "/api/organizer/events/{$id}/images/{$image->id}", ['kind' => 'banner']],
            ['delete', "/api/organizer/events/{$id}/images/{$image->id}", []],
            ['post', "/api/organizer/events/{$id}/images/order", ['ids' => [$image->id]]],
            ['post', "/api/organizer/events/{$id}/series", ['frequency' => 'weekly', 'count' => 4]],
            ['post', "/api/organizer/events/{$id}/series/skip", ['occurrence_id' => (string) Str::uuid()]],
            ['delete', "/api/organizer/events/{$id}/series", []],
            ['post', "/api/organizer/events/{$id}/codes", ['code' => 'HALF', 'type' => 'discount']],
            ['patch', "/api/organizer/events/{$id}/codes/{$code->id}", ['discount_value' => 90]],
            ['delete', "/api/organizer/events/{$id}/codes/{$code->id}", []],
            ['post', "/api/organizer/events/{$id}/code-batches", ['count' => 10]],
            ['post', "/api/organizer/events/{$id}/code-batches/{$batch->id}/deactivate", []],
        ];

        $before = EventSnapshot::fingerprint(EventSnapshot::of($this->event->fresh()));

        foreach ($locked as [$method, $uri, $body]) {
            $this->json(strtoupper($method), $uri, $body)
                ->assertStatus(423)
                ->assertJsonPath('message', 'This event is being reviewed. Withdraw it to make changes.')
                ->assertJsonPath('code', 'event_in_review');
        }

        // Nothing moved: what staff are looking at is what would go on sale.
        $this->assertSame($before, EventSnapshot::fingerprint(EventSnapshot::of($this->event->fresh())));
        $this->assertSame(1000, (int) $code->fresh()->discount_value);
        $this->assertSame(0, EventSeries::count());
    }

    public function test_a_code_on_an_event_waiting_for_review_is_not_changed_through_another_event(): void
    {
        $sibling = $this->anotherEvent('second-night');
        $code = Code::create(['organization_id' => $this->org->id, 'event_id' => $this->event->id, 'code' => 'EARLY10', 'discount_type' => 'percentage', 'discount_value' => 1000, 'is_active' => true]);

        $this->submit()->assertOk();

        $this->patchJson("/api/organizer/events/{$sibling->id}/codes/{$code->id}", ['discount_value' => 9000])->assertNotFound();
        $this->deleteJson("/api/organizer/events/{$sibling->id}/codes/{$code->id}")->assertNotFound();

        $code->refresh();
        $this->assertSame(1000, (int) $code->discount_value);
        $this->assertTrue($code->is_active);

        // A code for all the organization's events belongs to no one event,
        // and one event's review does not freeze it.
        $everywhere = Code::create(['organization_id' => $this->org->id, 'code' => 'FRIENDS', 'discount_type' => 'percentage', 'discount_value' => 1000, 'is_active' => true]);
        $this->patchJson("/api/organizer/events/{$sibling->id}/codes/{$everywhere->id}", ['discount_value' => 1500])->assertOk();
    }

    public function test_somebody_outside_the_organization_is_refused_as_before_not_told_it_is_in_review(): void
    {
        $this->submit()->assertOk();

        $stranger = User::factory()->create();
        $other = Organization::create(['name' => 'Eko Live', 'slug' => 'eko-live']);
        $other->members()->attach($stranger->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);
        $this->asOrganizer($stranger);

        $this->patchJson("/api/organizer/events/{$this->event->id}", ['title' => 'Mine now'])->assertForbidden();
    }

    public function test_running_the_night_stays_open_while_it_waits(): void
    {
        $this->submit()->assertOk();
        $id = $this->event->id;

        $this->postJson("/api/organizer/events/{$id}/reminders", ['offset_minutes' => 120])->assertCreated();
        $this->postJson("/api/organizer/events/{$id}/door-passes", ['label' => 'Front door'])->assertCreated();
        $this->getJson("/api/organizer/events/{$id}")->assertOk()->assertJsonPath('status', 'in_review');
        $this->getJson("/api/organizer/events/{$id}/ticket-types")->assertOk();
        $this->getJson("/api/organizer/events/{$id}/guests")->assertOk();
    }

    public function test_withdrawing_opens_it_again_and_says_so(): void
    {
        $this->submit()->assertOk();

        $this->postJson("/api/organizer/events/{$this->event->id}/withdraw")
            ->assertOk()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('outcome', 'withdrawn');

        $event = $this->event->fresh();
        $this->assertSame('draft', $event->status);
        $this->assertNull($event->submitted_at);

        Mail::assertQueued(EventWithdrawnFromReview::class, fn ($mail) => $mail->hasTo('owner@lagosnights.test'));
        Mail::assertQueued(EventWithdrawnFromReview::class, fn ($mail) => $mail->hasTo('manager@lagosnights.test'));
        $this->assertSame(1, AuditLog::where('action', 'event.withdrawn_from_review')->count());
        $this->assertSame(['submitted', 'withdrawn'], $event->reviews()->pluck('action')->all());

        // Changeable again.
        $this->patchJson("/api/organizer/events/{$this->event->id}", ['title' => 'Afro Fest II'])->assertOk();

        // And the old "take it off sale" request does the same while it waits.
        $this->submit()->assertOk();
        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'draft'])
            ->assertOk()
            ->assertJsonPath('status', 'draft');

        // A second press has nothing to take back, and sends nothing more.
        $this->postJson("/api/organizer/events/{$this->event->id}/withdraw")->assertOk()->assertJsonPath('outcome', 'already');
        Mail::assertQueued(EventWithdrawnFromReview::class, 4);
    }

    // --- deciding ----------------------------------------------------------------

    public function test_approving_puts_it_on_sale_and_tells_the_organizers_and_the_followers(): void
    {
        $follower = User::factory()->create(['email' => 'fan@example.com']);
        OrganizationFollow::create(['user_id' => $follower->id, 'organization_id' => $this->org->id]);
        $support = $this->staff(PlatformRole::Support, 'support@myfiesta.test');

        $this->submit()->assertOk();

        $this->assertSame('published', $this->reviews()->approve($this->event->fresh(), $support));

        $event = $this->event->fresh();
        $this->assertSame('published', $event->status);
        $this->assertNotNull($event->published_at);
        $this->assertNull($event->submitted_at);
        $this->assertSame($support->id, $event->approved_by);
        $this->assertSame(EventSnapshot::fingerprint(EventSnapshot::of($event)), $event->approved_fingerprint);

        Mail::assertQueued(EventApproved::class, fn ($mail) => $mail->hasTo('owner@lagosnights.test') && ! $mail->waitsForSuspension);
        Mail::assertQueued(EventApproved::class, fn ($mail) => $mail->hasTo('manager@lagosnights.test'));
        Mail::assertNotQueued(EventApproved::class, fn ($mail) => $mail->hasTo('door@lagosnights.test'));
        Mail::assertQueued(EventAnnouncedMail::class, fn ($mail) => $mail->hasTo('fan@example.com'));

        $entry = AuditLog::where('action', 'event.approved')->sole();
        $this->assertSame($support->id, $entry->actor_id);
        $this->assertSame(1, $entry->metadata['followers_told']);

        // The link it is emailed is the public page.
        $html = (new EventApproved($event))->render();
        $this->assertStringContainsString('/afro-fest', $html);

        // Reminders the organizer did not have to think about.
        $this->assertSame([10080, 1440, 180], $event->reminders()->pluck('offset_minutes')->all());

        // And the public can see it.
        $this->getJson('/api/events/afro-fest')->assertOk();
    }

    public function test_rejecting_needs_a_reason_and_sends_it_exactly_as_written(): void
    {
        $admin = $this->staff(PlatformRole::Admin, 'admin@myfiesta.test');
        $this->submit()->assertOk();

        try {
            $this->reviews()->reject($this->event->fresh(), $admin, 'Poster.');
            $this->fail('A one-word rejection was accepted.');
        } catch (StaffActionRefused) {
            $this->assertSame('in_review', $this->event->fresh()->status);
        }

        $this->reviews()->reject($this->event->fresh(), $admin, '  '.self::REASON.'  ');

        $event = $this->event->fresh();
        $this->assertSame('draft', $event->status);
        $this->assertNull($event->submitted_at);
        $this->assertNull($event->published_at);

        Mail::assertQueued(EventRejected::class, fn ($mail) => $mail->hasTo('owner@lagosnights.test') && $mail->reason === self::REASON);
        Mail::assertQueued(EventRejected::class, fn ($mail) => $mail->hasTo('manager@lagosnights.test') && $mail->reason === self::REASON);
        $this->assertStringContainsString('last year’s event', (new EventRejected($event, self::REASON))->render());

        $entry = AuditLog::where('action', 'event.rejected')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame(self::REASON, $entry->metadata['reason']);

        // The console shows the reason on the event until it is sent again.
        $this->getJson("/api/organizer/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('review.rejection.reason', self::REASON)
            ->assertJsonPath('review.history.0.action', 'rejected')
            ->assertJsonPath('review.history.0.by', 'myFiesta')
            ->assertJsonPath('review.history.0.reason', self::REASON);

        $this->patchJson("/api/organizer/events/{$event->id}", ['title' => 'Afro Fest 2026'])->assertOk();
        $this->submit()->assertOk()->assertJsonPath('status', 'in_review');

        $this->getJson("/api/organizer/events/{$event->id}")->assertJsonPath('review.rejection', null);
    }

    public function test_only_administrators_and_support_decide(): void
    {
        $finance = $this->staff(PlatformRole::Finance, 'finance@myfiesta.test');
        $this->submit()->assertOk();

        foreach ([fn () => $this->reviews()->approve($this->event->fresh(), $finance),
            fn () => $this->reviews()->reject($this->event->fresh(), $finance, self::REASON)] as $attempt) {
            try {
                $attempt();
                $this->fail('Finance decided a review.');
            } catch (StaffActionRefused $refused) {
                $this->assertSame('Only administrators and support review events.', $refused->getMessage());
            }
        }

        // Nor an organizer, whatever their role there.
        try {
            $this->reviews()->approve($this->event->fresh(), $this->owner);
            $this->fail('An organizer approved their own event.');
        } catch (StaffActionRefused) {
            $this->assertSame('in_review', $this->event->fresh()->status);
        }

        $this->assertSame(0, AuditLog::whereIn('action', ['event.approved', 'event.rejected'])->count());
    }

    public function test_deciding_twice_decides_once(): void
    {
        $support = $this->staff(PlatformRole::Support, 'support@myfiesta.test');
        $this->submit()->assertOk();

        $this->reviews()->approve($this->event->fresh(), $support);
        $this->reviews()->approve($this->event->fresh(), $support);

        $this->assertSame(1, AuditLog::where('action', 'event.approved')->count());
        $this->assertSame(1, EventReview::where('action', 'approved')->count());
        Mail::assertQueued(EventApproved::class, 2); // owner and manager, once each

        // Sent back twice: one reason, one email each.
        $other = Event::create([...$this->event->only(['organization_id', 'title', 'description', 'currency', 'timezone', 'city', 'country']),
            'slug' => 'second-night', 'starts_at' => now()->addMonths(2), 'status' => 'draft']);
        TicketType::create(['event_id' => $other->id, 'name' => 'General', 'price_amount' => 1000, 'status' => 'on_sale']);
        $this->postJson("/api/organizer/events/{$other->id}/submit")->assertOk();

        $this->reviews()->reject($other->fresh(), $support, self::REASON);
        $this->reviews()->reject($other->fresh(), $support, self::REASON);

        $this->assertSame(1, AuditLog::where('action', 'event.rejected')->count());
        Mail::assertQueued(EventRejected::class, 2);

        // Taken back by the organizer meanwhile: nothing left to approve.
        $this->postJson("/api/organizer/events/{$other->id}/submit")->assertOk();
        $this->postJson("/api/organizer/events/{$other->id}/withdraw")->assertOk();

        try {
            $this->reviews()->approve($other->fresh(), $support);
            $this->fail('An event the organizer took back was approved.');
        } catch (StaffActionRefused $refused) {
            $this->assertStringContainsString('took it back', $refused->getMessage());
        }
    }

    public function test_an_event_whose_night_came_while_it_waited_is_not_approved(): void
    {
        $admin = $this->staff(PlatformRole::Admin, 'admin@myfiesta.test');
        $this->submit()->assertOk();

        $this->travel(32)->days();

        $this->expectException(StaffActionRefused::class);
        $this->reviews()->approve($this->event->fresh(), $admin);
    }

    // --- approvals that still stand ------------------------------------------------

    public function test_unchanged_since_it_was_approved_it_goes_straight_back_on_sale(): void
    {
        $this->publishThroughReview($this->event)->assertOk();
        $first = $this->event->fresh()->published_at;

        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'draft'])
            ->assertOk()
            ->assertJsonPath('message', 'Taken off sale. Nothing has changed since it was approved, so you can put it back on sale without another review.');

        // The console is told which will happen before it offers the button.
        $this->getJson("/api/organizer/events/{$this->event->id}")->assertJsonPath('review.on_submit', 'publish');

        Mail::fake();

        $this->submit()
            ->assertOk()
            ->assertJsonPath('status', 'published')
            ->assertJsonPath('outcome', 'published');

        $this->assertEquals($first, $this->event->fresh()->published_at);
        $this->assertSame(1, EventReview::where('action', 'submitted')->count());
        Mail::assertNotQueued(EventAwaitingReview::class);
        Mail::assertNotQueued(EventAnnouncedMail::class);
        $this->assertTrue(AuditLog::where('action', 'event.published')->sole()->metadata['unchanged_since_approval']);
    }

    public function test_anything_changed_since_it_was_approved_goes_back_through_review(): void
    {
        $this->publishThroughReview($this->event)->assertOk();
        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'draft'])->assertOk();

        $this->patchJson("/api/organizer/events/{$this->event->id}/ticket-types/{$this->type->id}", ['price_amount' => 7500])->assertOk();

        $this->getJson("/api/organizer/events/{$this->event->id}")->assertJsonPath('review.on_submit', 'review');

        $this->submit()->assertOk()->assertJsonPath('status', 'in_review');

        $this->assertSame(
            ['Ticket “General”: price amount $50.00 → $75.00.'],
            $this->reviews()->changesSinceApproval($this->event->fresh()),
        );
    }

    public function test_after_a_rejection_only_another_approval_puts_it_on_sale(): void
    {
        $id = $this->event->id;
        $this->publishThroughReview($this->event)->assertOk();

        $this->postJson("/api/organizer/events/{$id}/publish", ['status' => 'draft'])->assertOk();
        $this->patchJson("/api/organizer/events/{$id}", ['title' => 'Afro Fest by Somebody Else'])->assertOk();
        $this->submit()->assertOk()->assertJsonPath('status', 'in_review');
        $this->rejectEvent($this->event, 'This organizer has no rights to use the Afro Fest name. Do not sell this event.');

        // Put back exactly as it was last approved: still staff's decision,
        // however it is sent — and taking it back from the queue changes
        // nothing.
        $this->patchJson("/api/organizer/events/{$id}", ['title' => 'Afro Fest'])->assertOk();
        $this->getJson("/api/organizer/events/{$id}")
            ->assertJsonPath('review.on_submit', 'review')
            ->assertJsonPath('review.unchanged_since_approval', false);
        $this->submit()->assertOk()->assertJsonPath('status', 'in_review');
        $this->postJson("/api/organizer/events/{$id}/withdraw")->assertOk();
        $this->postJson("/api/organizer/events/{$id}/publish", ['status' => 'published'])->assertOk()->assertJsonPath('status', 'in_review');

        // Approved again, that approval stands like any other.
        $this->approveEvent($this->event);
        $this->postJson("/api/organizer/events/{$id}/publish", ['status' => 'draft'])
            ->assertOk()
            ->assertJsonPath('message', 'Taken off sale. Nothing has changed since it was approved, so you can put it back on sale without another review.');
        $this->getJson("/api/organizer/events/{$id}")->assertJsonPath('review.rejection', null);
        $this->submit()->assertOk()->assertJsonPath('status', 'published');
    }

    public function test_a_series_date_sent_back_is_not_put_on_sale_by_matching_its_source_again(): void
    {
        $this->event->update(['starts_at' => now()->addWeek()->setTime(21, 0)]);
        $this->publishThroughReview($this->event)->assertOk();
        $this->postJson("/api/organizer/events/{$this->event->id}/series", ['frequency' => 'weekly', 'count' => 2])->assertCreated();

        $next = Event::query()->where('series_id', $this->event->fresh()->series_id)->whereKeyNot($this->event->id)->sole();

        $this->patchJson("/api/organizer/events/{$next->id}", ['title' => 'Afro Fest: headliner to be confirmed'])->assertOk();
        $this->postJson("/api/organizer/events/{$next->id}/submit")->assertOk()->assertJsonPath('status', 'in_review');
        $this->rejectEvent($next);

        $this->patchJson("/api/organizer/events/{$next->id}", ['title' => 'Afro Fest'])->assertOk();
        $this->postJson("/api/organizer/events/{$next->id}/submit")->assertOk()->assertJsonPath('status', 'in_review');
    }

    public function test_an_approval_that_stands_is_not_held_again_to_what_it_approved(): void
    {
        // On sale before reviews began: one with no description, and a
        // presale sold only through a hidden ticket.
        $bare = $this->anotherEvent('no-description', ['description' => null, 'status' => 'published', 'published_at' => now()->subWeek()]);
        $presale = $this->anotherEvent('presale', ['status' => 'published', 'published_at' => now()->subWeek()], ticket: 'hidden');

        foreach ([$bare, $presale] as $event) {
            $this->reviews()->recordApproval($event, null, EventReviews::VIA_EXISTING);

            // Told it can go straight back, and it can.
            $this->postJson("/api/organizer/events/{$event->id}/publish", ['status' => 'draft'])
                ->assertOk()
                ->assertJsonPath('message', 'Taken off sale. Nothing has changed since it was approved, so you can put it back on sale without another review.');
            $this->getJson("/api/organizer/events/{$event->id}")
                ->assertJsonPath('review.on_submit', 'publish')
                ->assertJsonPath('review.not_ready', []);
            $this->postJson("/api/organizer/events/{$event->id}/submit")->assertOk()->assertJsonPath('status', 'published');
        }

        // The date is still asked about: an approval does not make a night
        // that has passed sellable, and nobody is told it would.
        $this->postJson("/api/organizer/events/{$bare->id}/publish", ['status' => 'draft'])->assertOk();
        $this->travel(3)->months();

        $this->getJson("/api/organizer/events/{$bare->id}")->assertJsonPath('review.not_ready', ['The start date has passed. Change the date.']);
        $this->postJson("/api/organizer/events/{$bare->id}/submit")->assertStatus(422);
        $this->postJson("/api/organizer/events/{$presale->id}/publish", ['status' => 'draft'])
            ->assertOk()
            ->assertJsonPath('message', 'Taken off sale.');
    }

    public function test_edits_while_on_sale_need_no_review_but_are_recorded_and_count_as_changes(): void
    {
        $this->publishThroughReview($this->event)->assertOk();

        $this->patchJson("/api/organizer/events/{$this->event->id}", ['title' => 'Afro Fest: Summer'])->assertOk();

        $this->assertSame('published', $this->event->fresh()->status);
        $entry = AuditLog::where('action', 'event.edited_on_sale')->sole();
        $this->assertSame($this->owner->id, $entry->actor_id);
        $this->assertSame(['title'], $entry->metadata['changed']);
        $this->assertSame('Afro Fest', $entry->metadata['before']['title']);
        $this->assertSame('Afro Fest: Summer', $entry->metadata['after']['title']);

        // Not what was approved, so off and on again is a review.
        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'draft'])->assertOk();
        $this->submit()->assertOk()->assertJsonPath('status', 'in_review');
    }

    public function test_what_a_buyer_can_choose_from_is_recorded_when_it_changes_on_sale(): void
    {
        $id = $this->event->id;
        $addOn = AddOn::create(['event_id' => $id, 'name' => 'Table', 'price_amount' => 20000, 'status' => 'on_sale']);
        $question = EventQuestion::create(['event_id' => $id, 'label' => 'Dietary needs', 'type' => 'text', 'required' => false, 'per_attendee' => false]);

        // Built as a draft: nothing on the record, the review looks at it all.
        $vip = $this->postJson("/api/organizer/events/{$id}/ticket-types", ['name' => 'VIP', 'price_amount' => 9000])->assertCreated()->json('id');
        $this->assertSame(0, AuditLog::where('action', 'ticket.added')->count());

        $this->publishThroughReview($this->event)->assertOk();

        $late = $this->postJson("/api/organizer/events/{$id}/ticket-types", ['name' => 'Late entry', 'price_amount' => 2000])->assertCreated()->json('id');
        $this->deleteJson("/api/organizer/events/{$id}/ticket-types/{$vip}")->assertOk();
        $this->deleteJson("/api/organizer/events/{$id}/add-ons/{$addOn->id}")->assertOk();
        $this->patchJson("/api/organizer/events/{$id}/questions/{$question->id}", ['label' => 'Allergies'])->assertOk();

        $added = AuditLog::where('action', 'ticket.added')->sole();
        $this->assertSame([$late, 'Late entry', 2000], [$added->metadata['ticket_type_id'], $added->metadata['ticket_type'], $added->metadata['price_amount']]);
        $this->assertSame($this->owner->id, $added->actor_id);
        $this->assertSame('VIP', AuditLog::where('action', 'ticket.removed')->sole()->metadata['ticket_type']);
        $this->assertSame('Table', AuditLog::where('action', 'add_on.removed')->sole()->metadata['add_on']);

        $reworded = AuditLog::where('action', 'question.updated')->sole();
        $this->assertSame(['label'], $reworded->metadata['changed']);
        $this->assertSame(['Dietary needs', 'Allergies'], [$reworded->metadata['before']['label'], $reworded->metadata['after']['label']]);

        // Still on sale: none of it needed another review.
        $this->assertSame('published', $this->event->fresh()->status);
    }

    public function test_the_next_date_of_an_approved_series_goes_on_sale_as_the_approved_night(): void
    {
        $this->event->update(['starts_at' => now()->addWeek()->setTime(21, 0)]);
        $this->publishThroughReview($this->event)->assertOk();

        $this->postJson("/api/organizer/events/{$this->event->id}/series", ['frequency' => 'weekly', 'count' => 3])->assertCreated();

        [$next, $after] = Event::query()->where('series_id', $this->event->fresh()->series_id)
            ->whereKeyNot($this->event->id)->orderBy('starts_at')->get()->all();

        $this->assertSame('draft', $next->status);

        $this->postJson("/api/organizer/events/{$next->id}/submit")
            ->assertOk()
            ->assertJsonPath('status', 'published');

        $review = EventReview::where('event_id', $next->id)->sole();
        $this->assertSame(['approved', 'series'], [$review->action, $review->via]);
        $this->assertNotNull($next->fresh()->approved_fingerprint);

        // A date that says something else is looked at.
        $this->patchJson("/api/organizer/events/{$after->id}", ['title' => 'Afro Fest: special guest'])->assertOk();
        $this->postJson("/api/organizer/events/{$after->id}/submit")->assertOk()->assertJsonPath('status', 'in_review');
    }

    public function test_dates_of_a_series_whose_source_was_never_approved_are_looked_at(): void
    {
        $this->event->update(['starts_at' => now()->addWeek()->setTime(21, 0)]);
        $this->postJson("/api/organizer/events/{$this->event->id}/series", ['frequency' => 'weekly', 'count' => 2])->assertCreated();

        $next = Event::query()->where('series_id', $this->event->fresh()->series_id)->whereKeyNot($this->event->id)->sole();

        $this->postJson("/api/organizer/events/{$next->id}/submit")->assertOk()->assertJsonPath('status', 'in_review');
    }

    public function test_a_poster_caption_buyers_never_see_does_not_send_the_next_date_to_review(): void
    {
        // A gallery picture promoted to the poster keeps its caption; the
        // copy for the next date does not carry one.
        Storage::fake('public');
        Storage::disk('public')->put('events/afro-fest/flyer.jpg', 'a picture');
        EventImage::create(['event_id' => $this->event->id, 'kind' => 'banner', 'path' => 'events/afro-fest/flyer.jpg', 'caption' => 'The flyer', 'position' => 0]);

        $this->event->update(['starts_at' => now()->addWeek()->setTime(21, 0)]);
        $this->publishThroughReview($this->event)->assertOk();
        $this->postJson("/api/organizer/events/{$this->event->id}/series", ['frequency' => 'weekly', 'count' => 2])->assertCreated();

        $next = Event::query()->where('series_id', $this->event->fresh()->series_id)->whereKeyNot($this->event->id)->sole();
        $this->assertNotNull($next->banner);

        $this->postJson("/api/organizer/events/{$next->id}/submit")->assertOk()->assertJsonPath('status', 'published');
    }

    public function test_lifting_a_takedown_counts_as_approval(): void
    {
        $admin = $this->staff(PlatformRole::Admin, 'admin@myfiesta.test');
        $this->publishThroughReview($this->event)->assertOk();
        $this->patchJson("/api/organizer/events/{$this->event->id}", ['title' => 'Afro Fest (fixed)'])->assertOk();

        $moderation = app(EventModeration::class);
        $moderation->takeDown($this->event->fresh(), $admin, 'The venue says it has not been booked.');
        $this->assertSame('published', $moderation->restore($this->event->fresh(), $admin));

        $this->assertSame('takedown_lifted', EventReview::where('action', 'approved')->orderByDesc('seq')->first()->via);

        // As it stood when staff put it back is what was approved.
        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'draft'])->assertOk();
        $this->submit()->assertOk()->assertJsonPath('status', 'published');
    }

    public function test_a_takedown_takes_an_event_out_of_the_queue(): void
    {
        $admin = $this->staff(PlatformRole::Admin, 'admin@myfiesta.test');
        $this->submit()->assertOk();

        app(EventModeration::class)->takeDown($this->event->fresh(), $admin, 'The venue says it has not been booked.');

        $event = $this->event->fresh();
        $this->assertSame('draft', $event->status);
        $this->assertNull($event->submitted_at);
        $this->assertSame('in_review', AuditLog::where('action', 'event.taken_down')->sole()->metadata['from']);

        // Lifted, it is a draft to send again, not on sale.
        $this->assertSame('draft', app(EventModeration::class)->restore($event, $admin));
    }

    public function test_approved_while_suspended_it_waits_and_goes_on_sale_when_lifted(): void
    {
        $admin = $this->staff(PlatformRole::Admin, 'admin@myfiesta.test');
        $follower = User::factory()->create(['email' => 'fan@example.com']);
        OrganizationFollow::create(['user_id' => $follower->id, 'organization_id' => $this->org->id]);

        $this->submit()->assertOk();
        app(Suspension::class)->suspend($this->org, $admin, 'Chargebacks on three events in a week.');

        // Still waiting: a suspension takes off sale what is on sale.
        $this->assertSame('in_review', $this->event->fresh()->status);

        $this->assertSame('draft', $this->reviews()->approve($this->event->fresh(), $admin));
        $this->assertNotNull($this->event->fresh()->unpublished_by_suspension_at);
        Mail::assertQueued(EventApproved::class, fn ($mail) => $mail->waitsForSuspension);
        Mail::assertNotQueued(EventAnnouncedMail::class);

        app(Suspension::class)->unsuspend($this->org, $admin);

        $this->assertSame('published', $this->event->fresh()->status);
        Mail::assertQueued(EventAnnouncedMail::class, fn ($mail) => $mail->hasTo('fan@example.com'));

        // What went on sale is what staff approved. Lifting the suspension
        // approved nothing of its own.
        $this->assertSame(['review'], EventReview::where('action', 'approved')->pluck('via')->all());
    }

    public function test_lifting_a_suspension_puts_back_only_what_it_took_as_it_was(): void
    {
        $admin = $this->staff(PlatformRole::Admin, 'admin@myfiesta.test');

        // On sale, then edited on sale: never approved as it reads now, but on
        // sale all the same.
        $edited = $this->anotherEvent('edited-on-sale');
        $this->publishThroughReview($edited)->assertOk();
        $this->patchJson("/api/organizer/events/{$edited->id}", ['title' => 'Afro Fest: late set added'])->assertOk();
        $approvedAs = $edited->fresh()->approved_fingerprint;

        // On sale, and changed while the suspension lasts.
        $changed = $this->anotherEvent('changed-while-suspended');
        $this->publishThroughReview($changed)->assertOk();

        // Waiting for review.
        $this->submit()->assertOk();

        app(Suspension::class)->suspend($this->org, $admin, 'Chargebacks on three events in a week.');

        // Approved while suspended, then changed while it waits.
        $this->assertSame('draft', $this->reviews()->approve($this->event->fresh(), $admin));
        $this->patchJson("/api/organizer/events/{$this->event->id}", ['title' => 'A Completely Different Night'])->assertOk();
        $this->patchJson("/api/organizer/events/{$this->event->id}/ticket-types/{$this->type->id}", ['price_amount' => 99900])->assertOk();

        $this->patchJson("/api/organizer/events/{$changed->id}", ['title' => 'Afro Fest: new headliner'])->assertOk();

        $approvals = EventReview::where('action', 'approved')->count();

        $done = app(Suspension::class)->unsuspend($this->org, $admin);

        // Back as it was when it came off, edits made on sale and all.
        $this->assertSame([$edited->id], $done['republished']);
        $this->assertSame('published', $edited->fresh()->status);
        $this->assertSame($approvedAs, $edited->fresh()->approved_fingerprint);

        // Changed meanwhile: nobody at myFiesta has seen them as they read now.
        $this->assertEquals([
            $this->event->id => 'changed since it came off sale, so it needs a review',
            $changed->id => 'changed since it came off sale, so it needs a review',
        ], $done['left']);
        $this->assertSame('draft', $this->event->fresh()->status);
        $this->assertSame('draft', $changed->fresh()->status);
        $this->assertSame(0, Event::whereNotNull('unpublished_by_suspension_fingerprint')->count());

        // Lifting it approved nothing.
        $this->assertSame($approvals, EventReview::where('action', 'approved')->count());

        // They go through review like any other change.
        $this->submit()->assertOk()->assertJsonPath('status', 'in_review');
        $this->postJson("/api/organizer/events/{$changed->id}/submit")->assertOk()->assertJsonPath('status', 'in_review');
    }

    public function test_lifting_a_suspension_does_not_announce_an_event_that_was_already_on_sale(): void
    {
        $admin = $this->staff(PlatformRole::Admin, 'admin@myfiesta.test');
        $follower = User::factory()->create(['email' => 'fan@example.com']);
        OrganizationFollow::create(['user_id' => $follower->id, 'organization_id' => $this->org->id]);

        // On sale for a month, from before announcements began: never
        // announced, and not news now.
        $old = $this->anotherEvent('on-sale-for-weeks', ['status' => 'published', 'published_at' => now()->subMonth()]);
        $this->reviews()->recordApproval($old, null, EventReviews::VIA_EXISTING);
        $this->assertNull($old->fresh()->announced_at);

        app(Suspension::class)->suspend($this->org, $admin, 'Chargebacks on three events in a week.');
        $this->assertSame([$old->id], app(Suspension::class)->unsuspend($this->org, $admin)['republished']);

        $this->assertSame('published', $old->fresh()->status);
        Mail::assertNotQueued(EventAnnouncedMail::class);
    }

    public function test_events_on_sale_when_reviews_began_are_approved_as_they_stand(): void
    {
        $onSale = Event::create([...$this->event->only(['organization_id', 'title', 'description', 'currency', 'timezone', 'city', 'country']),
            'slug' => 'already-on-sale', 'starts_at' => now()->addMonth(), 'status' => 'published', 'published_at' => now()->subWeek()]);
        TicketType::create(['event_id' => $onSale->id, 'name' => 'General', 'price_amount' => 1000, 'status' => 'on_sale']);

        $migration = require database_path('migrations/2026_09_27_051000_events_are_approved_before_they_go_on_sale.php');
        (fn () => $this->approveWhatIsOnSale())->call($migration);

        $onSale->refresh();
        $this->assertNotNull($onSale->approved_at);
        $this->assertSame(EventSnapshot::fingerprint(EventSnapshot::of($onSale)), $onSale->approved_fingerprint);
        $this->assertSame('existing', EventReview::where('event_id', $onSale->id)->sole()->via);

        // A draft is not.
        $this->assertNull($this->event->fresh()->approved_at);

        // And taking it off sale and back on needs no review.
        $this->postJson("/api/organizer/events/{$onSale->id}/publish", ['status' => 'draft'])->assertOk();
        $this->postJson("/api/organizer/events/{$onSale->id}/submit")->assertOk()->assertJsonPath('status', 'published');
    }

    // --- everything else keeps working -------------------------------------------

    public function test_nothing_waiting_for_review_is_public_or_sellable(): void
    {
        $this->seed(TaxRateSeeder::class);
        $this->submit()->assertOk();

        $this->getJson('/api/events/afro-fest')->assertNotFound();
        $this->getJson('/api/events')->assertOk()->assertJsonCount(0, 'data');
        $this->get('/api/sitemap.xml')->assertDontSee('afro-fest');

        foreach (['online', 'door'] as $channel) {
            try {
                app(CheckoutService::class)->reserve(
                    $this->event->fresh(),
                    [$this->type->id => 1],
                    'buyer@example.com',
                    'Ada Buyer',
                    channel: $channel,
                );
                $this->fail("An event in review sold a ticket ({$channel}).");
            } catch (CheckoutException $refused) {
                $this->assertSame('Tickets for this event are not on sale.', $refused->getMessage());
            }
        }
    }

    public function test_a_copy_starts_as_a_draft_with_no_approval(): void
    {
        $this->publishThroughReview($this->event)->assertOk();

        $this->postJson("/api/organizer/events/{$this->event->id}/duplicate", ['starts_at' => now()->addMonths(3)->toIso8601String()])
            ->assertCreated();

        $copy = Event::query()->whereKeyNot($this->event->id)->sole();
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->approved_fingerprint);
        $copy = $copy->id;
        $this->postJson("/api/organizer/events/{$copy}/submit")->assertOk()->assertJsonPath('status', 'in_review');
    }

    // --- the admin -----------------------------------------------------------------

    public function test_the_queue_is_oldest_first_and_counted_in_the_navigation(): void
    {
        $support = $this->staff(PlatformRole::Support, 'support@myfiesta.test');
        $this->submit()->assertOk();

        $this->travel(1)->hours();
        $later = Event::create([...$this->event->only(['organization_id', 'description', 'currency', 'timezone', 'city', 'country']),
            'title' => 'Highlife Night', 'slug' => 'highlife-night', 'starts_at' => now()->addMonth(), 'status' => 'draft']);
        TicketType::create(['event_id' => $later->id, 'name' => 'General', 'price_amount' => 1000, 'status' => 'on_sale']);
        $this->postJson("/api/organizer/events/{$later->id}/submit")->assertOk();

        $this->actingAs($support);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertSame('2', EventReviewQueue::getNavigationBadge());

        Livewire::test(EventReviewQueue::class)
            ->assertCanSeeTableRecords([$this->event->fresh(), $later->fresh()], inOrder: true)
            ->assertSee('First review');
    }

    public function test_the_review_page_shows_what_buyers_will_see_and_what_changed(): void
    {
        $support = $this->staff(PlatformRole::Support, 'support@myfiesta.test');
        AddOn::create(['event_id' => $this->event->id, 'name' => 'Reserved table', 'price_amount' => 25000, 'status' => 'on_sale']);
        EventQuestion::create(['event_id' => $this->event->id, 'label' => 'Dietary needs', 'type' => 'text', 'required' => true, 'per_attendee' => true]);

        $this->publishThroughReview($this->event)->assertOk();
        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'draft'])->assertOk();
        $this->patchJson("/api/organizer/events/{$this->event->id}", ['title' => 'Afro Fest Returns'])->assertOk();
        $this->submit()->assertOk()->assertJsonPath('status', 'in_review');

        $this->actingAs($support);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewEvent::class, ['record' => $this->event->getKey()])
            ->assertSuccessful()
            ->assertSee('Afro Fest Returns')
            ->assertSee('Afrobeats, highlife and amapiano until late.')
            ->assertSee('$50.00')
            ->assertSee('Reserved table')
            ->assertSee('$250.00')
            ->assertSee('Dietary needs')
            ->assertSee('Title: “Afro Fest” → “Afro Fest Returns”')
            ->assertSee('America/Toronto');
    }

    public function test_the_review_page_approves_and_rejects_with_confirmation_and_finance_only_reads(): void
    {
        $this->submit()->assertOk();

        $finance = $this->staff(PlatformRole::Finance, 'finance@myfiesta.test');
        $this->actingAs($finance);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ReviewEvent::class, ['record' => $this->event->getKey()])
            ->assertSuccessful()
            ->assertActionHidden('approveEvent')
            ->assertActionHidden('rejectEvent');

        $support = $this->staff(PlatformRole::Support, 'support@myfiesta.test');
        $this->actingAs($support);

        Livewire::test(ReviewEvent::class, ['record' => $this->event->getKey()])
            ->callAction('rejectEvent', data: ['reason' => 'Too short'])
            ->assertHasActionErrors(['reason' => 'min']);

        $this->assertSame('in_review', $this->event->fresh()->status);

        Livewire::test(ReviewEvent::class, ['record' => $this->event->getKey()])
            ->callAction('rejectEvent', data: ['reason' => self::REASON])
            ->assertHasNoActionErrors()
            ->assertNotified('Sent back to the organizer');

        $this->assertSame('draft', $this->event->fresh()->status);
        Mail::assertQueued(EventRejected::class, fn ($mail) => $mail->reason === self::REASON);

        $this->asOrganizer($this->owner);
        $this->submit()->assertOk();

        $this->actingAs($support);
        Livewire::test(ReviewEvent::class, ['record' => $this->event->getKey()])
            ->assertActionVisible('approveEvent')
            ->callAction('approveEvent')
            ->assertHasNoActionErrors()
            ->assertNotified('Approved');

        $this->assertSame('published', $this->event->fresh()->status);
        $this->assertSame($support->id, AuditLog::where('action', 'event.approved')->sole()->actor_id);
    }

    public function test_a_review_page_opened_before_the_event_changed_decides_nothing_until_it_is_reloaded(): void
    {
        $support = $this->staff(PlatformRole::Support, 'support@myfiesta.test');
        $this->submit()->assertOk();

        $this->actingAs($support);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $approving = Livewire::test(ReviewEvent::class, ['record' => $this->event->getKey()])->assertSee('$50.00');
        $rejecting = Livewire::test(ReviewEvent::class, ['record' => $this->event->getKey()]);

        // Meanwhile the organizer takes it back, changes it and sends it again.
        $this->asOrganizer($this->owner);
        $this->postJson("/api/organizer/events/{$this->event->id}/withdraw")->assertOk();
        $this->patchJson("/api/organizer/events/{$this->event->id}", ['title' => 'Swapped Night'])->assertOk();
        $this->patchJson("/api/organizer/events/{$this->event->id}/ticket-types/{$this->type->id}", ['price_amount' => 99900])->assertOk();
        $this->submit()->assertOk()->assertJsonPath('status', 'in_review');

        $this->actingAs($support);

        $approving->callAction('approveEvent')->assertNotified('Not done');
        $rejecting->callAction('rejectEvent', data: ['reason' => self::REASON])->assertNotified('Not done');

        $this->assertSame('in_review', $this->event->fresh()->status);
        $this->assertSame(0, AuditLog::whereIn('action', ['event.approved', 'event.rejected'])->count());
        Mail::assertNotQueued(EventRejected::class);

        // Reloaded, it shows what the event says now, and that is what is
        // approved.
        Livewire::test(ReviewEvent::class, ['record' => $this->event->getKey()])
            ->assertSee('Swapped Night')
            ->assertSee('$999.00')
            ->callAction('approveEvent')
            ->assertNotified('Approved');

        $this->assertSame('published', $this->event->fresh()->status);
        $this->assertSame('Swapped Night', $this->event->fresh()->title);
    }

    public function test_the_migration_rolls_back_with_an_event_waiting_for_review(): void
    {
        $this->submit()->assertOk();

        $migration = require database_path('migrations/2026_09_27_051000_events_are_approved_before_they_go_on_sale.php');
        $migration->down();

        // A draft again, for the organizer to publish under the old rules.
        $this->assertSame('draft', DB::table('events')->where('id', $this->event->id)->value('status'));

        $migration->up();

        $this->assertSame('draft', $this->event->fresh()->status);
        $this->assertNull($this->event->fresh()->submitted_at);
    }
}
