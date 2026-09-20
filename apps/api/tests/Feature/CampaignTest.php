<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Mail\CampaignMail;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\EmailPreference;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationFollow;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Campaigns\Audiences;
use App\Services\Campaigns\CampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Campaigns: an organizer writing to people who are not holding a ticket.
 *
 * The rules being protected are about consent more than mechanics. Each list
 * is one the person put themselves on, with this organizer, recently enough
 * to still mean it. A blanket no is honoured. Nobody is asked to buy what they
 * have already bought, or written to by the same organizer twice in a week.
 * And the unsubscribe in the email stops campaigns without touching the
 * reminders for a ticket somebody holds.
 */
class CampaignTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $next;

    private Event $last;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->next = $this->event('afro-fest', now()->addMonth());
        $this->last = $this->event('last-time', now()->subMonths(3));

        $this->owner = $this->member(Role::Owner);
        $this->as($this->owner);
    }

    private function event(string $slug, $when, ?Organization $org = null): Event
    {
        $event = Event::create([
            'organization_id' => ($org ?? $this->org)->id,
            'slug' => $slug,
            'title' => Str::headline($slug),
            'currency' => 'CAD',
            'starts_at' => $when,
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        TicketType::create(['event_id' => $event->id, 'name' => 'General', 'price_amount' => 5000, 'status' => 'on_sale']);

        return $event;
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

    private function as(User $user): void
    {
        Sanctum::actingAs($user, [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);
    }

    private function follower(string $email): void
    {
        OrganizationFollow::create([
            'organization_id' => $this->org->id,
            'user_id' => User::factory()->create(['email' => $email])->id,
            'token' => Str::random(40),
        ]);
    }

    private function order(Event $event, string $email, string $status = 'paid', ?string $ref = null, int $net = 5000): Order
    {
        return Order::create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => $email,
            'buyer_name' => 'Somebody',
            'currency' => 'CAD',
            'subtotal_amount' => $net,
            'total_amount' => $net,
            'net_revenue_amount' => $net,
            'status' => $status,
            'ref_slug' => $ref,
        ]);
    }

    private function ticket(Event $event, string $email, string $status = 'valid', ?Order $order = null): Ticket
    {
        $order ??= $this->order($event, $email);

        return Ticket::create([
            'event_id' => $event->id,
            'ticket_type_id' => $event->ticketTypes()->value('id'),
            'order_id' => $order->id,
            'owner_email' => $email,
            'code' => strtoupper(Str::random(12)),
            'status' => $status,
        ]);
    }

    private function send(array $body = []): TestResponse
    {
        return $this->postJson('/api/organizer/campaigns', [
            'audience' => 'past_attendees',
            'event_id' => $this->next->id,
            'subject' => 'We are back',
            'body' => 'Same room, better sound.',
            'send' => 'now',
            ...$body,
        ]);
    }

    /** @return list<string> */
    private function writtenTo(): array
    {
        $to = [];
        Mail::assertQueued(CampaignMail::class, function (CampaignMail $mail) use (&$to) {
            $to[] = $mail->email;

            return true;
        });
        sort($to);

        return $to;
    }

    // --- who is on each list ------------------------------------------------------

    public function test_past_attendees_are_people_whose_ticket_stood_to_a_night_that_happened(): void
    {
        $this->ticket($this->last, 'came@example.com');
        $this->ticket($this->last, 'refunded@example.com', 'refunded');
        // Coming, not came: they will hear from the event itself.
        $this->ticket($this->event('soon', now()->addWeek()), 'soon@example.com');
        // Longer ago than a purchase still counts as consent.
        $this->ticket($this->event('long-ago', now()->subYears(3)), 'long-ago@example.com');
        // Somebody else's night.
        $other = Organization::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $this->ticket($this->event('theirs', now()->subMonth(), $other), 'theirs@example.com');

        $this->send()->assertCreated()->assertJsonPath('message', 'Sent to 1 person.');

        $this->assertSame(['came@example.com'], $this->writtenTo());
    }

    public function test_followers_are_people_who_follow_and_have_not_already_bought(): void
    {
        $this->follower('fan@example.com');
        $this->follower('bought@example.com');
        $this->ticket($this->next, 'bought@example.com');

        $this->send(['audience' => 'followers'])->assertCreated();

        $this->assertSame(['fan@example.com'], $this->writtenTo());
    }

    public function test_an_abandoned_basket_is_recent_and_never_finished(): void
    {
        $this->order($this->next, 'walked-away@example.com', 'cancelled');
        // Tried again and got there.
        $this->order($this->next, 'second-go@example.com', 'cancelled');
        $this->order($this->next, 'SECOND-GO@example.com', 'paid');
        // Too long ago to be a reminder.
        $this->travel(-(Audiences::ABANDONED_DAYS + 1))->days();
        $this->order($this->next, 'last-month@example.com', 'cancelled');
        $this->travelBack();

        $this->send(['audience' => 'abandoned'])->assertCreated();

        $this->assertSame(['walked-away@example.com'], $this->writtenTo());
    }

    public function test_an_abandoned_basket_needs_its_event(): void
    {
        $this->send(['audience' => 'abandoned', 'event_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_id');
    }

    public function test_a_blanket_no_is_honoured(): void
    {
        $this->ticket($this->last, 'no@example.com');
        $this->ticket($this->last, 'yes@example.com');
        EmailPreference::forEmail('no@example.com')->update(['marketing_opted_out_at' => now()]);

        $this->send()->assertCreated();

        $this->assertSame(['yes@example.com'], $this->writtenTo());
        $this->assertSame(1, Campaign::sole()->suppressed);
    }

    public function test_nobody_gets_two_campaigns_from_one_organizer_in_a_week(): void
    {
        $this->ticket($this->last, 'ada@example.com');
        $this->follower('ada@example.com');

        $this->send()->assertCreated();

        // A different list, the same person, two days later.
        $this->travel(2)->days();
        $this->send(['audience' => 'followers', 'subject' => 'Again'])
            ->assertStatus(422);

        $this->travel(Audiences::QUIET_DAYS)->days();
        $this->send(['audience' => 'followers', 'subject' => 'A week on'])->assertCreated();

        Mail::assertQueued(CampaignMail::class, 2);
    }

    public function test_an_empty_list_is_said_rather_than_sent_to_nobody(): void
    {
        $this->send()
            ->assertStatus(422)
            ->assertJsonPath('data.status', 'draft');

        Mail::assertNothingQueued();
    }

    public function test_the_audience_is_counted_before_sending(): void
    {
        $this->ticket($this->last, 'ada@example.com');
        $this->ticket($this->last, 'no@example.com');
        EmailPreference::forEmail('no@example.com')->update(['marketing_opted_out_at' => now()]);

        $this->postJson('/api/organizer/campaigns/audience', ['audience' => 'past_attendees', 'event_id' => $this->next->id])
            ->assertOk()
            ->assertExactJson(['all' => 2, 'reachable' => 1]);
    }

    // --- the email --------------------------------------------------------------

    public function test_the_email_links_with_its_ref_and_its_unsubscribe_stops_campaigns_only(): void
    {
        $this->ticket($this->last, 'ada@example.com');
        $this->send()->assertCreated();

        $campaign = Campaign::sole();
        $mail = null;
        Mail::assertQueued(CampaignMail::class, function (CampaignMail $m) use (&$mail) {
            $mail = $m;

            return true;
        });

        $this->assertStringContainsString('?ref='.$campaign->ref, $mail->content()->with['url']);
        $this->assertStringContainsString('went to a Lagos Nights event', $mail->content()->with['why']);

        preg_match('/^<(.+)>$/', $mail->headers()->text['List-Unsubscribe'], $m);
        $this->post($m[1])->assertOk();

        $preference = EmailPreference::forEmail('ada@example.com');
        $this->assertFalse($preference->wantsMarketing());
        $this->assertTrue($preference->wantsReminders());
    }

    public function test_what_it_sold_is_counted_from_its_link(): void
    {
        $this->ticket($this->last, 'ada@example.com');
        $this->send()->assertCreated();
        $ref = Campaign::sole()->ref;

        $order = $this->order($this->next, 'ada@example.com', 'paid', $ref, 10000);
        $this->ticket($this->next, 'ada@example.com', 'valid', $order);
        $this->ticket($this->next, 'friend@example.com', 'valid', $order);
        // Not through the link.
        $this->order($this->next, 'other@example.com');

        $this->getJson('/api/organizer/campaigns')
            ->assertOk()
            ->assertJsonPath('data.0.results.orders', 1)
            ->assertJsonPath('data.0.results.tickets', 2)
            ->assertJsonPath('data.0.results.revenue.amount', 10000)
            ->assertJsonPath('data.0.recipients', 1);
    }

    // --- scheduling -------------------------------------------------------------

    public function test_a_scheduled_campaign_goes_out_when_its_time_comes_to_whoever_is_on_the_list_then(): void
    {
        $this->ticket($this->last, 'ada@example.com');

        $this->send(['send' => 'later', 'scheduled_for' => now()->addDay()->toIso8601String()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled');

        Mail::assertNothingQueued();

        // Somebody who says no in between is not written to.
        $this->ticket($this->last, 'changed-mind@example.com');
        EmailPreference::forEmail('changed-mind@example.com')->update(['marketing_opted_out_at' => now()]);

        $this->travel(25)->hours();
        $this->artisan('campaigns:send')->assertSuccessful();

        $this->assertSame(['ada@example.com'], $this->writtenTo());
        $this->assertSame('sent', Campaign::sole()->status);
        $this->assertTrue(AuditLog::where('action', 'campaign.sent')->exists());
    }

    public function test_a_cancelled_campaign_never_goes(): void
    {
        $this->ticket($this->last, 'ada@example.com');
        $id = $this->send(['send' => 'later', 'scheduled_for' => now()->addDay()->toIso8601String()])->json('data.id');

        $this->postJson("/api/organizer/campaigns/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->travel(2)->days();
        $this->artisan('campaigns:send')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_a_campaign_for_a_night_that_was_taken_down_is_not_sent(): void
    {
        $this->ticket($this->last, 'ada@example.com');
        $this->send(['send' => 'later', 'scheduled_for' => now()->addDay()->toIso8601String()])->assertCreated();

        $this->next->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->travel(2)->days();
        $this->artisan('campaigns:send')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertSame('cancelled', Campaign::sole()->status);
    }

    public function test_a_send_that_died_partway_resumes_without_repeating_anybody(): void
    {
        $this->ticket($this->last, 'ada@example.com');
        $this->ticket($this->last, 'chidi@example.com');

        $id = $this->send(['send' => 'draft'])->json('data.id');
        $campaign = Campaign::find($id);

        // As if a worker had claimed it, written to Ada, and died.
        $campaign->update(['status' => 'sending', 'scheduled_for' => now()]);
        DB::table('campaign_deliveries')->insert([
            'campaign_id' => $id, 'organization_id' => $this->org->id,
            'email' => 'ada@example.com', 'created_at' => now(),
        ]);
        DB::table('campaigns')->where('id', $id)->update(['updated_at' => now()->subMinutes(CampaignSender::STALE_MINUTES + 1)]);

        $this->artisan('campaigns:send')->assertSuccessful();

        $this->assertSame(['chidi@example.com'], $this->writtenTo());
        $this->assertSame(2, $campaign->fresh()->recipients);
    }

    public function test_a_sent_campaign_cannot_be_changed(): void
    {
        $this->ticket($this->last, 'ada@example.com');
        $id = $this->send()->json('data.id');

        $this->putJson("/api/organizer/campaigns/{$id}", [
            'audience' => 'followers', 'subject' => 'x', 'body' => 'y', 'send' => 'draft',
        ])->assertStatus(422);

        $this->postJson("/api/organizer/campaigns/{$id}/cancel")->assertStatus(422);
    }

    // --- finding one among many ---------------------------------------------------

    /** Four campaigns across two lists, two events and four statuses. */
    private function spread(): void
    {
        $rows = [
            ['We are back on the 14th', 'draft', 'followers', $this->next->id],
            ['Last chance for Afro Fest', 'sent', 'followers', $this->next->id],
            ['Thank you for coming', 'sent', 'past_attendees', $this->last->id],
            ['Doors at nine', 'cancelled', 'past_attendees', null],
        ];

        foreach ($rows as [$subject, $status, $audience, $eventId]) {
            Campaign::create([
                'organization_id' => $this->org->id,
                'audience' => $audience,
                'event_id' => $eventId,
                'subject' => $subject,
                'body' => 'y',
                'status' => $status,
            ]);
        }
    }

    public function test_the_list_narrows_by_status_list_event_and_subject(): void
    {
        $this->spread();

        $subjects = fn (array $query) => collect($this->getJson('/api/organizer/campaigns?'.http_build_query($query))
            ->assertOk()
            ->json('data'))
            ->pluck('subject')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['We are back on the 14th'], $subjects(['status' => 'draft']));
        $this->assertSame(['Doors at nine', 'Thank you for coming'], $subjects(['audience' => 'past_attendees']));
        $this->assertSame(
            ['Last chance for Afro Fest', 'We are back on the 14th'],
            $subjects(['event_id' => $this->next->id]),
        );

        // The subject search is what somebody half-remembers, in any case.
        $this->assertSame(['We are back on the 14th'], $subjects(['q' => 'back on the']));
        $this->assertSame(['Last chance for Afro Fest'], $subjects(['q' => 'AFRO']));

        // And they narrow together rather than replacing each other.
        $this->assertSame(
            ['Last chance for Afro Fest'],
            $subjects(['status' => 'sent', 'event_id' => $this->next->id]),
        );
    }

    public function test_a_wildcard_typed_into_the_search_is_a_character_and_not_a_pattern(): void
    {
        $this->spread();

        // Without escaping, '%' matches everything and the filter silently
        // stops filtering — the failure nobody notices.
        $this->getJson('/api/organizer/campaigns?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/organizer/campaigns?q=_oors')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/organizer/campaigns?q=Doors')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_filter_is_offered_the_statuses_and_the_nights_that_exist(): void
    {
        $this->spread();

        $body = $this->getJson('/api/organizer/campaigns')->assertOk()->json();

        $this->assertSame(
            ['draft' => 1, 'scheduled' => 0, 'sending' => 0, 'sent' => 2, 'cancelled' => 1],
            collect($body['statuses'])->pluck('campaigns', 'value')->all(),
        );

        // A night that has already happened is still worth finding a campaign
        // about, so this list is not the one a new campaign may point at.
        $this->assertEqualsCanonicalizing(
            [$this->next->id, $this->last->id],
            collect($body['written_about'])->pluck('id')->all(),
        );
        $this->assertSame([$this->next->id], collect($body['events'])->pluck('id')->all());
    }

    public function test_another_organizations_campaigns_are_not_reachable_through_the_filter(): void
    {
        $other = Organization::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $theirEvent = $this->event('theirs', now()->addMonth(), $other);

        Campaign::create([
            'organization_id' => $other->id,
            'audience' => 'followers',
            'event_id' => $theirEvent->id,
            'subject' => 'Their secret night',
            'body' => 'y',
            'status' => 'sent',
        ]);

        $this->getJson('/api/organizer/campaigns?q=secret')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/organizer/campaigns?event_id={$theirEvent->id}")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_status_that_is_not_one_is_refused_rather_than_ignored(): void
    {
        $this->getJson('/api/organizer/campaigns?status=posted')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    // --- who may ------------------------------------------------------------------

    public function test_marketing_can_run_campaigns_and_finance_and_door_cannot(): void
    {
        $this->ticket($this->last, 'ada@example.com');

        foreach ([Role::Finance, Role::Door] as $role) {
            $this->as($this->member($role));
            $this->getJson('/api/organizer/campaigns')->assertForbidden();
            $this->send()->assertForbidden();
        }

        $this->as($this->member(Role::Marketing));
        $this->send()->assertCreated();
    }

    public function test_only_its_own_upcoming_events_can_be_promoted(): void
    {
        $other = Organization::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);

        foreach ([$this->event('theirs', now()->addMonth(), $other), $this->last] as $event) {
            $this->send(['event_id' => $event->id])->assertStatus(422)->assertJsonValidationErrors('event_id');
        }
    }

    public function test_another_organizations_campaign_is_not_found(): void
    {
        $other = Organization::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $theirs = Campaign::create([
            'organization_id' => $other->id,
            'audience' => 'followers',
            'subject' => 'x',
            'body' => 'y',
            'status' => 'draft',
        ]);

        $this->postJson("/api/organizer/campaigns/{$theirs->id}/cancel")->assertNotFound();
    }
}
