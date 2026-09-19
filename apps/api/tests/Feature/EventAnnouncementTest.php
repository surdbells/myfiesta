<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Mail\EventAnnouncedMail;
use App\Models\EmailPreference;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrganizationFollow;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Telling followers that an organizer has announced a night.
 *
 * The payoff of following somebody. What matters is that it happens once, that
 * it reaches only the people who asked for it, and that leaving is possible
 * from inside the email without an account — most people who follow an
 * organizer bought their last ticket as a guest.
 */
class EventAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->event = Event::factory()->create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'status' => 'draft',
            'starts_at' => now()->addWeeks(3),
        ]);
        TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 3000,
            'quantity_available' => 100,
            'status' => 'on_sale',
        ]);

        $this->owner = User::factory()->create();
        $this->org->members()->attach($this->owner->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);
    }

    private function follower(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        OrganizationFollow::create(['user_id' => $user->id, 'organization_id' => $this->org->id]);

        return $user;
    }

    private function publish()
    {
        Sanctum::actingAs($this->owner->fresh()->load('organizations'), [TokenAbility::Organizer->value]);

        return $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'published']);
    }

    public function test_publishing_tells_the_people_who_follow_the_organizer(): void
    {
        $ada = $this->follower('ada@example.com');

        $this->publish()->assertOk();

        Mail::assertQueued(EventAnnouncedMail::class, fn ($mail) => $mail->hasTo($ada->email));
    }

    public function test_it_says_it_once_however_often_the_event_is_republished(): void
    {
        $this->follower('ada@example.com');

        $this->publish()->assertOk();

        // Unpublishing to fix a typo and publishing again is a normal
        // afternoon. It is not a second announcement.
        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'draft'])->assertOk();
        $this->publish()->assertOk();

        Mail::assertQueuedCount(1);
    }

    public function test_somebody_who_follows_nobody_hears_nothing(): void
    {
        User::factory()->create(['email' => 'stranger@example.com']);

        $this->publish()->assertOk();

        Mail::assertNothingQueued();
    }

    public function test_a_blanket_no_to_this_kind_of_mail_is_honoured(): void
    {
        $this->follower('ada@example.com');
        EmailPreference::forEmail('ada@example.com')->update(['marketing_opted_out_at' => now()]);

        $this->publish()->assertOk();

        Mail::assertNothingQueued();
    }

    public function test_the_unsubscribe_button_in_an_announcement_stops_announcements(): void
    {
        $this->follower('ada@example.com');
        $this->publish()->assertOk();

        $mail = null;
        Mail::assertQueued(EventAnnouncedMail::class, function (EventAnnouncedMail $m) use (&$mail) {
            $mail = $m;

            return true;
        });

        // What Gmail posts when somebody presses its unsubscribe button: the
        // address from the header, nothing else. This used to switch off
        // reminders and leave announcements running.
        preg_match('/^<(.+)>$/', $mail->headers()->text['List-Unsubscribe'], $m);
        $this->post($m[1])->assertOk();

        $preference = EmailPreference::forEmail('ada@example.com');
        $this->assertFalse($preference->wantsMarketing());
        // And nothing they did not ask to stop.
        $this->assertTrue($preference->wantsReminders());
    }

    public function test_the_marketing_page_says_what_it_stops_and_changes_nothing_on_its_own(): void
    {
        $preference = EmailPreference::forEmail('ada@example.com');

        $this->get(route('unsubscribe', [$preference->token, 'kind' => 'marketing']))
            ->assertOk()
            ->assertSee('Stop news from organizers?', false);

        $this->assertTrue($preference->fresh()->wantsMarketing());

        $this->post(route('unsubscribe.confirm', [$preference->token, 'kind' => 'marketing']))->assertOk();
        $this->assertFalse($preference->fresh()->wantsMarketing());

        $this->post(route('unsubscribe.resubscribe', [$preference->token, 'kind' => 'marketing']))->assertOk();
        $this->assertTrue($preference->fresh()->wantsMarketing());
    }

    public function test_an_invitation_event_is_never_announced(): void
    {
        $this->follower('ada@example.com');
        $this->event->update(['kind' => 'invitation']);

        $this->publish();

        // Announcing a wedding to a following is the platform handing out an
        // invitation nobody offered.
        Mail::assertNothingQueued();
    }

    public function test_the_email_carries_a_way_out_that_needs_no_account(): void
    {
        $user = $this->follower('ada@example.com');
        $follow = OrganizationFollow::where('user_id', $user->id)->first();

        $this->publish()->assertOk();

        $this->get(route('follows.leave', $follow->token))
            ->assertOk()
            ->assertSee('Stop following Lagos Nights?', false);

        // A GET changes nothing: mail clients and scanners follow links.
        $this->assertDatabaseHas('organization_follows', ['id' => $follow->id]);

        $this->post(route('follows.leave.confirm', $follow->token))->assertOk()->assertSee('no longer following');

        $this->assertDatabaseMissing('organization_follows', ['id' => $follow->id]);
    }

    public function test_a_used_leave_link_says_so_rather_than_failing(): void
    {
        $this->get(route('follows.leave', 'nothing-like-a-real-token'))->assertNotFound()->assertSee('already been used');
    }

    public function test_publishing_does_not_tell_the_organizer_who_follows_them(): void
    {
        $this->follower('ada@example.com');
        $this->follower('bola@example.com');

        $body = $this->publish()->assertOk()->json();

        // The count is theirs to know. The names and addresses are not.
        $this->assertStringNotContainsString('ada@example.com', json_encode($body));
        $this->assertStringNotContainsString('bola@example.com', json_encode($body));

        $this->assertDatabaseHas('audit_logs', ['action' => 'event.published']);
    }
}
