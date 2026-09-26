<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Mail\AttendeeMessage;
use App\Models\EmailPreference;
use App\Models\Event;
use App\Models\EventMessage;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Messaging\MessageSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An organizer writing to their ticket holders.
 *
 * Without this an organizer with news had no way to reach their own audience
 * except by exporting a guest list into a personal mail client — which puts
 * buyers' addresses somewhere no opt-out on this platform can reach.
 *
 * The distinction the tests care about is ordinary news versus something
 * somebody needs before they travel. A lineup announcement respects an opt-out;
 * a venue change does not, and says why it arrived anyway.
 */
class AttendeeMessageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);
    }

    private function holder(string $email, string $status = 'valid'): Ticket
    {
        return Ticket::create([
            'event_id' => $this->event->id,
            'ticket_type_id' => $this->type->id,
            'code' => strtoupper(Str::random(4)).'-'.strtoupper(Str::random(8)),
            'owner_email' => $email,
            'holder_name' => 'Someone',
            'status' => $status,
            'admits' => 1,
            'admitted_count' => 0,
        ]);
    }

    private function asRole(Role $role): void
    {
        $user = User::factory()->create();

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    private function send(array $body = [])
    {
        return $this->postJson("/api/organizer/events/{$this->event->id}/messages", array_merge([
            'subject' => 'Doors open at 9',
            'body' => 'We are opening an hour earlier than advertised.',
        ], $body));
    }

    // --- sending -------------------------------------------------------------

    public function test_a_message_reaches_everybody_holding_a_ticket(): void
    {
        $this->holder('ada@example.com');
        $this->holder('chidi@example.com');
        $this->asRole(Role::Manager);

        $this->send()->assertCreated()->assertJsonPath('message', 'Sent to 2 people.');

        Mail::assertQueued(AttendeeMessage::class, 2);
    }

    public function test_a_refunded_ticket_reaches_nobody(): void
    {
        $this->holder('ada@example.com', 'refunded');
        $this->holder('chidi@example.com');
        $this->asRole(Role::Manager);

        $this->send()->assertCreated();

        // Being emailed about a night you were refunded for is the worst
        // message this could send.
        Mail::assertQueued(AttendeeMessage::class, 1);
        Mail::assertNotQueued(AttendeeMessage::class, fn ($m) => $m->hasTo('ada@example.com'));
    }

    public function test_two_tickets_on_one_address_is_one_email(): void
    {
        $this->holder('ada@example.com');
        $this->holder('ada@example.com');
        $this->asRole(Role::Manager);

        $this->send()->assertCreated()->assertJsonPath('message', 'Sent to 1 person.');

        Mail::assertQueued(AttendeeMessage::class, 1);
    }

    public function test_sending_to_an_empty_room_is_refused_rather_than_queued(): void
    {
        $this->asRole(Role::Manager);

        // An organizer who thinks a message went out and finds later that it
        // did not is worse off than one told immediately.
        $this->send()
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nobody holds a ticket to this event yet.');

        $this->assertSame(0, EventMessage::count());
    }

    public function test_a_message_is_kept_after_it_is_sent(): void
    {
        $this->holder('ada@example.com');
        $this->asRole(Role::Manager);

        $this->send(['subject' => 'Venue moved'])->assertCreated();

        // "Did the venue change email go out?" has to have an answer that is
        // not somebody's memory.
        $message = EventMessage::firstOrFail();
        $this->assertSame('Venue moved', $message->subject);
        $this->assertSame('sent', $message->status);
        $this->assertSame(1, $message->recipients);
        $this->assertNotNull($message->sent_at);
    }

    // --- opting out ----------------------------------------------------------

    public function test_ordinary_news_respects_an_opt_out(): void
    {
        $this->holder('ada@example.com');
        $this->holder('chidi@example.com');

        EmailPreference::forEmail('ada@example.com')
            ->update(['reminders_opted_out_at' => now()]);

        $this->asRole(Role::Manager);
        $this->send()->assertCreated();

        // Somebody who said stop emailing me about events I hold tickets to
        // meant a lineup announcement.
        Mail::assertQueued(AttendeeMessage::class, 1);
        Mail::assertNotQueued(AttendeeMessage::class, fn ($m) => $m->hasTo('ada@example.com'));
    }

    public function test_something_they_need_before_travelling_reaches_everyone(): void
    {
        $this->holder('ada@example.com');
        $this->holder('chidi@example.com');

        EmailPreference::forEmail('ada@example.com')
            ->update(['reminders_opted_out_at' => now()]);

        $this->asRole(Role::Manager);
        $this->send(['important' => true])->assertCreated();

        // Not knowing the venue moved costs somebody a wasted journey. The
        // email says why it arrived despite the opt-out.
        Mail::assertQueued(AttendeeMessage::class, 2);
    }

    public function test_an_important_message_offers_no_one_click_way_out(): void
    {
        $this->holder('ada@example.com');
        $this->asRole(Role::Manager);
        $this->send(['important' => true])->assertCreated();

        $message = EventMessage::firstOrFail();
        $headers = (new AttendeeMessage($message, EmailPreference::forEmail('ada@example.com')))
            ->headers();

        // Offering a one-click way out of a venue change is offering to let
        // somebody turn up at the wrong building.
        $this->assertArrayNotHasKey('List-Unsubscribe', $headers->text);
    }

    public function test_ordinary_news_carries_the_headers_an_inbox_reads(): void
    {
        $this->holder('ada@example.com');
        $this->asRole(Role::Manager);
        $this->send()->assertCreated();

        $headers = (new AttendeeMessage(
            EventMessage::firstOrFail(),
            EmailPreference::forEmail('ada@example.com'),
        ))->headers();

        $this->assertSame('List-Unsubscribe=One-Click', $headers->text['List-Unsubscribe-Post']);
    }

    /**
     * A reply is not handed to an inbox nobody chose for it.
     *
     * The organization has no address it picked for replies. The contact
     * address an import leaves behind may be the owner's own sign-in address,
     * and nobody in the console can see it — so it is not used, and nor is the
     * address of whoever pressed send.
     */
    public function test_a_reply_goes_to_no_personal_inbox(): void
    {
        $this->org->update(['contact_email' => 'ada.personal@example.com']);
        $this->holder('chidi@example.com');
        $this->asRole(Role::Manager);

        $this->send()->assertCreated();

        Mail::assertQueued(AttendeeMessage::class, fn (AttendeeMessage $mail) => $mail->envelope()->replyTo === []);
    }

    public function test_the_gap_between_holders_and_reachable_is_reported(): void
    {
        $this->holder('ada@example.com');
        $this->holder('chidi@example.com');

        EmailPreference::forEmail('ada@example.com')
            ->update(['reminders_opted_out_at' => now()]);

        $this->asRole(Role::Manager);

        // Seeing both numbers together is what stops the support email asking
        // why the count was lower than the guest list.
        $this->getJson("/api/organizer/events/{$this->event->id}/messages")
            ->assertOk()
            ->assertJsonPath('audience.holders', 2)
            ->assertJsonPath('audience.reachable', 1);
    }

    public function test_nobody_is_written_to_twice_if_a_send_is_resumed(): void
    {
        $this->holder('ada@example.com');
        $this->holder('chidi@example.com');
        $this->asRole(Role::Manager);
        $this->send()->assertCreated();

        $message = EventMessage::firstOrFail();

        // What a crashed worker leaves behind: the message claimed, one person
        // already written to.
        DB::table('event_message_deliveries')->where('message_id', $message->id)
            ->where('email', 'chidi@example.com')->delete();
        $message->update(['status' => 'queued']);

        app(MessageSender::class)->send($message);

        // Ada is not written to a second time.
        Mail::assertQueued(AttendeeMessage::class, 3);
        $this->assertSame(
            1,
            Mail::queued(AttendeeMessage::class, fn ($m) => $m->hasTo('ada@example.com'))->count(),
        );
    }

    // --- who may -------------------------------------------------------------

    public function test_marketing_may_write_to_attendees(): void
    {
        $this->holder('ada@example.com');
        $this->asRole(Role::Marketing);

        // This is the one thing the marketing role exists for.
        $this->send()->assertCreated();
    }

    public function test_door_staff_may_not(): void
    {
        $this->holder('ada@example.com');
        $this->asRole(Role::Door);

        $this->send()->assertForbidden();
        $this->assertSame(0, EventMessage::count());
    }

    public function test_another_organizations_audience_is_unreachable(): void
    {
        $this->holder('ada@example.com');

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

        // Otherwise a competitor's entire ticket-buying audience is one POST
        // away from being marketed to.
        $this->send()->assertForbidden();
        Mail::assertNothingQueued();
    }
}
