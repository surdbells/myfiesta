<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\ImpersonationSession;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Impersonation\Impersonation;
use App\Services\Impersonation\ImpersonationRefused;
use App\Services\Impersonation\WhileImpersonating;
use App\Services\StaffSupport\AccountActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * myFiesta staff acting as an organization.
 *
 * The tests that matter are the edges: what the token cannot reach, how long
 * it lives, whether the trail names the person who acted, and that nobody
 * else's sign-in moves an inch.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $support;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->owner = $this->member($this->org, Role::Owner);
        $this->support = $this->staff(PlatformRole::Support, 'Sade Support');
        $this->event = $this->eventFor($this->org, 'afro-fest');
    }

    // --- fixtures ---------------------------------------------------------------

    private function staff(?PlatformRole $role, string $name = 'Staff Member'): User
    {
        return User::factory()->create([
            'name' => $name,
            'platform_role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    private function member(Organization $org, Role $role, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        $org->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role->value, 'accepted_at' => now()]);

        return $user;
    }

    private function eventFor(Organization $org, string $slug): Event
    {
        return Event::create([
            'organization_id' => $org->id,
            'slug' => $slug,
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(6),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    /** @return array{session: ImpersonationSession, code: string, url: string} */
    private function start(?User $staff = null, string $reason = 'Ticket #4411: tiers look wrong'): array
    {
        return app(Impersonation::class)->start($this->org, $staff ?? $this->support, $reason);
    }

    /** Opens the console link in a tab with no credential of its own. */
    private function exchange(string $code): TestResponse
    {
        $this->forgetAuth();

        return $this->postJson('/api/impersonation/exchange', ['code' => $code]);
    }

    private function tokenFor(?User $staff = null): string
    {
        return $this->exchange($this->start($staff)['code'])->assertOk()->json('token');
    }

    private function forgetAuth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }

    private function as(string $token, ?Organization $org = null): static
    {
        $this->forgetAuth();

        return $this->withToken($token)->withHeader('X-Organization', ($org ?? $this->org)->id);
    }

    // --- getting in -------------------------------------------------------------

    public function test_support_opens_the_console_and_works_in_the_organization(): void
    {
        ['session' => $session, 'code' => $code, 'url' => $url] = $this->start();

        // The code travels in the fragment, and the token never in the URL.
        $this->assertStringContainsString('/impersonate#code='.$code, $url);

        $body = $this->exchange($code)
            ->assertOk()
            ->assertJsonPath('user.name', 'Sade Support')
            ->assertJsonPath('organizations.0.id', $this->org->id)
            ->assertJsonPath('organizations.0.role', 'owner')
            ->assertJsonPath('impersonation.id', $session->id)
            ->assertJsonPath('impersonation.staff.name', 'Sade Support')
            ->assertJsonPath('impersonation.organization.name', 'Lagos Nights')
            ->json();

        $this->assertCount(1, $body['organizations']);
        $this->assertSame(WhileImpersonating::permissionNames(), $body['organizations'][0]['permissions']);

        foreach (WhileImpersonating::WITHHELD as $withheld) {
            $this->assertNotContains($withheld->value, $body['organizations'][0]['permissions']);
        }

        $this->as($body['token'])->getJson('/api/organizer/overview')
            ->assertOk()
            ->assertJsonPath('organization.id', $this->org->id);

        $this->as($body['token'])->getJson('/api/organizer/events')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->event->id]);

        // Reading what was sent, and who is waiting, stays open: only the
        // sends are refused.
        $this->as($body['token'])->getJson("/api/organizer/events/{$this->event->id}/messages")->assertOk();
        $this->as($body['token'])->getJson("/api/organizer/events/{$this->event->id}/waitlist")->assertOk();
        $this->as($body['token'])->getJson('/api/organizer/brand')->assertOk();

        // The session as the server resolves it for this token — the same
        // permissions the exchange promised, reached through the same method
        // every policy asks.
        $this->as($body['token'])->getJson('/api/impersonation')
            ->assertOk()
            ->assertJsonPath('organizations.0.permissions', $body['organizations'][0]['permissions'])
            ->assertJsonPath('impersonation.reason', 'Ticket #4411: tiers look wrong');
    }

    public function test_what_staff_change_is_recorded_as_the_staff_member_acting_for_the_organization(): void
    {
        $first = TicketType::create(['event_id' => $this->event->id, 'name' => 'Early', 'price_amount' => 2000, 'status' => 'on_sale']);
        $second = TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 3000, 'status' => 'on_sale']);

        ['session' => $session, 'code' => $code] = $this->start();
        $token = $this->exchange($code)->json('token');

        $this->as($token)
            ->postJson("/api/organizer/events/{$this->event->id}/ticket-types/order", ['ids' => [$second->id, $first->id]])
            ->assertOk();

        $entry = AuditLog::where('action', 'ticket_types.reordered')->sole();

        $this->assertSame($this->support->id, $entry->actor_id);
        $this->assertSame('Sade Support', $entry->actor_label);
        $this->assertSame($this->org->id, $entry->organization_id);
        $this->assertSame($session->id, $entry->metadata['impersonating']['session']);
        $this->assertSame($this->org->id, $entry->metadata['impersonating']['organization']);
        $this->assertSame('support', $entry->metadata['impersonating']['staff_role']);

        // Start and opening are on the trail too, under the same person.
        $started = AuditLog::where('action', 'impersonation.started')->sole();
        $this->assertSame($this->support->id, $started->actor_id);
        $this->assertSame(Organization::class, $started->subject_type);
        $this->assertSame($this->org->id, $started->subject_id);
        $this->assertSame('Ticket #4411: tiers look wrong', $started->metadata['reason']);
        $this->assertSame(1, AuditLog::where('action', 'impersonation.opened')->where('actor_id', $this->support->id)->count());
    }

    public function test_an_owners_own_actions_carry_no_impersonation_mark(): void
    {
        $type = TicketType::create(['event_id' => $this->event->id, 'name' => 'Early', 'price_amount' => 2000, 'status' => 'on_sale']);

        $token = $this->owner->createToken('web', ['attendee', 'organizer'])->plainTextToken;

        $this->as($token)
            ->postJson("/api/organizer/events/{$this->event->id}/ticket-types/order", ['ids' => [$type->id]])
            ->assertOk();

        $this->assertArrayNotHasKey('impersonating', AuditLog::where('action', 'ticket_types.reordered')->sole()->metadata ?? []);
        $this->assertSame(0, AuditLog::where('action', 'impersonation.request')->count());
    }

    public function test_every_change_staff_make_is_on_the_trail_even_where_the_endpoint_records_nothing(): void
    {
        ['session' => $session, 'code' => $code] = $this->start();
        $token = $this->exchange($code)->json('token');

        // Neither endpoint writes an audit entry of its own.
        $this->as($token)
            ->patchJson("/api/organizer/events/{$this->event->id}", ['title' => 'Afro Fest: Summer'])
            ->assertOk();
        $this->as($token)
            ->postJson("/api/organizer/events/{$this->event->id}/codes", ['code' => 'STAFF10', 'discount_type' => 'percentage', 'discount_value' => 1000])
            ->assertCreated();

        // Reading changes nothing, and leaves nothing.
        $this->as($token)->getJson("/api/organizer/events/{$this->event->id}")->assertOk();

        $entries = AuditLog::where('action', 'impersonation.request')->get();
        $this->assertCount(2, $entries);

        $edit = $entries->firstWhere('metadata.method', 'PATCH');
        $this->assertSame($this->support->id, $edit->actor_id);
        $this->assertSame('Sade Support', $edit->actor_label);
        $this->assertSame($this->org->id, $edit->organization_id);
        $this->assertSame(Organization::class, $edit->subject_type);
        $this->assertSame($this->org->id, $edit->subject_id);
        $this->assertStringEndsWith('EventController@update', $edit->metadata['action']);
        $this->assertSame(['event' => $this->event->id], $edit->metadata['parameters']);
        $this->assertSame(200, $edit->metadata['status']);
        $this->assertSame($session->id, $edit->metadata['impersonating']['session']);
        $this->assertSame('support', $edit->metadata['impersonating']['staff_role']);

        $created = $entries->firstWhere('metadata.method', 'POST');
        $this->assertSame($this->support->id, $created->actor_id);
        $this->assertStringEndsWith('CodeController@store', $created->metadata['action']);
        $this->assertSame(201, $created->metadata['status']);
        $this->assertSame($session->id, $created->metadata['impersonating']['session']);

        // What was sent is not kept — only what it was sent to.
        $this->assertStringNotContainsString('Afro Fest: Summer', json_encode($edit->metadata));
        $this->assertSame('Afro Fest: Summer', $this->event->fresh()->title);
    }

    public function test_refusals_are_on_the_trail_too_without_the_credentials_in_their_paths(): void
    {
        ['session' => $session, 'code' => $code] = $this->start();
        $token = $this->exchange($code)->json('token');
        $invitation = Str::random(40);

        $this->as($token)->getJson('/api/organizer/orders/export')->assertForbidden();
        $this->as($token)->postJson("/api/invitations/{$invitation}/accept")->assertForbidden();

        $refused = AuditLog::where('action', 'impersonation.request')->get();
        $this->assertCount(2, $refused);

        foreach ($refused as $entry) {
            $this->assertSame($this->support->id, $entry->actor_id);
            $this->assertTrue($entry->metadata['refused']);
            $this->assertSame(403, $entry->metadata['status']);
            $this->assertSame($session->id, $entry->metadata['impersonating']['session']);
        }

        $accept = $refused->firstWhere('metadata.method', 'POST');
        $this->assertSame(['token' => '[not recorded]'], $accept->metadata['parameters']);
        $this->assertStringNotContainsString($invitation, json_encode($accept->metadata));
    }

    // --- what stays out of reach --------------------------------------------------

    public function test_refused_actions_answer_403_and_say_why(): void
    {
        $token = $this->tokenFor();
        $order = $this->order();

        $refused = [
            ['PUT', '/api/organizer/payout-details', 'Where payouts are sent'],
            ['POST', '/api/organizer/payouts/requests', 'Payouts are asked for'],
            ['GET', '/api/organizer/team', 'Who is on the team'],
            ['POST', '/api/organizer/team/invitations', 'Who is on the team'],
            ['GET', '/api/organizer/integrations', 'Webhooks and API keys'],
            ['POST', '/api/organizer/integrations/keys', 'Webhooks and API keys'],
            ['PATCH', '/api/organizer/brand', 'name and public identity'],
            ['POST', "/api/organizer/events/{$this->event->id}/cancel", 'cannot be walked back'],
            ['DELETE', "/api/organizer/events/{$this->event->id}/series", 'cannot be walked back'],
            ['POST', "/api/organizer/events/{$this->event->id}/orders/{$order->id}/refunds", 'Refunds are made from the admin panel'],
            ['POST', "/api/events/{$this->event->id}/scan", 'The door is for the people at the door'],
            ['POST', "/api/organizer/events/{$this->event->id}/door-passes", 'The door is for the people at the door'],
            ['GET', '/api/organizer/orders/export', 'Exports are not available'],
            ['GET', "/api/organizer/events/{$this->event->id}/guests/export", 'Exports are not available'],
            ['POST', "/api/organizer/events/{$this->event->id}/messages", 'cannot be called back'],
            ['POST', '/api/organizer/campaigns', 'cannot be called back'],
            ['POST', "/api/organizer/events/{$this->event->id}/waitlist/notify", 'cannot be called back'],
            ['GET', '/api/auth/me', 'Your own account'],
            ['PATCH', '/api/auth/profile', 'Your own account'],
            ['POST', '/api/auth/password', 'Your own account'],
            ['POST', '/api/auth/email', 'Your own account'],
            ['POST', '/api/auth/email/verification', 'Your own account'],
        ];

        foreach ($refused as [$method, $uri, $says]) {
            $response = $this->as($token)->json($method, $uri, [
                'email' => 'someone@example.com',
                'role' => 'owner',
                'name' => 'Renamed',
                'label' => 'Front gate',
            ]);

            $this->assertSame(403, $response->status(), "{$method} {$uri}");
            $this->assertStringContainsString($says, (string) $response->json('message'), "{$method} {$uri}");
        }

        // And none of it happened.
        $this->assertSame(0, OrganizationInvitation::count());
        $this->assertSame('Lagos Nights', $this->org->fresh()->name);
        $this->assertSame('published', $this->event->fresh()->status);
        $this->assertSame('Sade Support', $this->support->fresh()->name);
    }

    public function test_the_token_is_not_an_attendee_token(): void
    {
        $this->as($this->tokenFor())->getJson('/api/me/tickets')->assertForbidden();
    }

    public function test_support_does_not_reach_the_payouts_statement_the_admin_panel_keeps_from_it(): void
    {
        // Support: orders and sales, as in the admin panel; not where the
        // money goes, what was sent, or what was asked for.
        $body = $this->exchange($this->start()['code'])->assertOk()->assertJsonPath('impersonation.payouts', false)->json();

        $this->as($body['token'])->getJson('/api/organizer/payouts')
            ->assertForbidden()
            ->assertJsonPath('message', WhileImpersonating::PAYOUTS);
        $this->as($body['token'])->getJson('/api/organizer/orders')->assertOk();
        $this->as($body['token'])->getJson('/api/organizer/overview')->assertOk();

        // An administrator reads it here as they can in the admin panel.
        $admin = $this->staff(PlatformRole::Admin, 'Ade Admin');
        $adminBody = $this->exchange($this->start($admin)['code'])->assertOk()->assertJsonPath('impersonation.payouts', true)->json();

        $this->as($adminBody['token'])->getJson('/api/organizer/payouts')
            ->assertOk()
            ->assertJsonStructure(['balance', 'settlements', 'destination', 'requests']);
    }

    public function test_publishing_for_the_first_time_is_left_to_the_organization_because_it_emails_followers(): void
    {
        Mail::fake();

        $draft = $this->eventFor($this->org, 'new-night');
        $draft->update(['status' => 'draft', 'published_at' => null]);
        TicketType::create(['event_id' => $draft->id, 'name' => 'General', 'price_amount' => 3000, 'status' => 'on_sale']);
        TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 3000, 'status' => 'on_sale']);

        $token = $this->tokenFor();

        $this->as($token)->postJson("/api/organizer/events/{$draft->id}/publish", ['status' => 'published'])
            ->assertForbidden()
            ->assertJsonPath('message', WhileImpersonating::ANNOUNCES);

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->announced_at);

        // Taking one down, and putting back up one already announced, send
        // nothing: support fixing a typo can finish what it started.
        $this->event->forceFill(['announced_at' => now()->subDay()])->save();

        $this->as($token)->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'draft'])->assertOk();
        $this->as($token)->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'published'])->assertOk();

        $this->assertSame('published', $this->event->fresh()->status);
        Mail::assertNothingOutgoing();
    }

    public function test_tickets_can_be_issued_but_not_emailed(): void
    {
        Mail::fake();

        $type = TicketType::create(['event_id' => $this->event->id, 'name' => 'Guest list', 'price_amount' => 0, 'status' => 'on_sale']);
        $token = $this->tokenFor();
        $comp = ['ticket_type_id' => $type->id, 'name' => 'Tobi Guest', 'email' => 'tobi@example.com'];

        $this->as($token)->postJson("/api/organizer/events/{$this->event->id}/tickets", $comp + ['send_email' => true])
            ->assertForbidden()
            ->assertJsonPath('message', WhileImpersonating::SENDS);
        $this->assertSame(0, $type->tickets()->count());

        $this->as($token)->postJson("/api/organizer/events/{$this->event->id}/tickets", $comp)->assertCreated();
        $this->assertSame(1, $type->tickets()->count());

        Mail::assertNothingOutgoing();
    }

    public function test_the_permission_layer_refuses_without_the_route_list(): void
    {
        $token = $this->tokenFor();
        $staff = PersonalAccessToken::findToken($token)->tokenable->withAccessToken(PersonalAccessToken::findToken($token));

        // What policies ask. The route list in WhileImpersonating is the clear
        // message; this is the authority behind it.
        foreach (WhileImpersonating::WITHHELD as $withheld) {
            $this->assertFalse($staff->hasPermissionIn($this->org, $withheld), $withheld->value);
        }

        $this->assertTrue($staff->hasPermissionIn($this->org, Permission::EventsEdit));
        $this->assertTrue($staff->hasPermissionIn($this->org, Permission::MoneyView));

        $this->assertTrue(Gate::forUser($staff)->allows('update', $this->event));
        $this->assertFalse(Gate::forUser($staff)->allows('cancel', $this->event));
        $this->assertFalse(Gate::forUser($staff)->allows('refund', $this->event));
        $this->assertFalse(Gate::forUser($staff)->allows('scan', $this->event));

        // Without the token the same account is nobody's member at all.
        $this->assertFalse($this->support->fresh()->hasPermissionIn($this->org, Permission::EventsView));
    }

    public function test_the_token_reaches_no_other_organization(): void
    {
        $other = Organization::create(['name' => 'Toronto Beats', 'slug' => 'toronto-beats']);
        $theirs = $this->eventFor($other, 'their-night');
        // A draft: a published event is anybody's to read, organizer or not
        // (EventPolicy::view), so only a draft shows whether the session's
        // membership reaches past its own organization.
        $theirs->update(['status' => 'draft', 'published_at' => null]);
        $token = $this->tokenFor();

        $this->as($token, $other)->getJson('/api/organizer/overview')->assertForbidden();
        $this->as($token)->getJson("/api/organizer/events/{$theirs->id}")->assertForbidden();
        $this->as($token)->patchJson("/api/organizer/events/{$theirs->id}", ['title' => 'Mine now'])->assertForbidden();

        $this->assertSame('Afro Fest', $theirs->fresh()->title);
    }

    public function test_staff_who_organize_elsewhere_keep_that_out_of_the_session_and_their_own_sign_in_untouched(): void
    {
        $own = Organization::create(['name' => 'Sade’s Side Project', 'slug' => 'sade-side']);
        $this->member($own, Role::Owner, $this->support);

        $ownToken = $this->support->createToken('web', ['attendee', 'organizer'])->plainTextToken;
        $staffToken = $this->tokenFor();

        // The session sees one organization: the one it was opened for.
        $this->as($staffToken, $own)->getJson('/api/organizer/overview')->assertForbidden();

        // Their own console sign-in carries on as it was, and is not a
        // staff session.
        $this->as($ownToken, $own)->getJson('/api/organizer/overview')
            ->assertOk()
            ->assertJsonPath('organization.id', $own->id);
        $this->as($ownToken, $this->org)->getJson('/api/organizer/overview')->assertForbidden();

        // Ending the session leaves the other token alone.
        $this->as($staffToken)->deleteJson('/api/impersonation')->assertOk();
        $this->as($ownToken, $own)->getJson('/api/organizer/overview')->assertOk();
    }

    public function test_the_organizations_own_members_are_unaffected(): void
    {
        $ownerToken = $this->owner->createToken('web', ['attendee', 'organizer'])->plainTextToken;
        $staffToken = $this->tokenFor();

        $this->as($staffToken)->deleteJson('/api/impersonation')->assertOk();

        $this->as($ownerToken)->getJson('/api/organizer/team')->assertOk();
        $this->assertSame(1, $this->org->members()->count(), 'Staff were added to the team.');
    }

    // --- the handoff --------------------------------------------------------------

    public function test_the_handoff_code_works_once(): void
    {
        $code = $this->start()['code'];

        $this->exchange($code)->assertOk();
        $this->exchange($code)->assertStatus(410)->assertJsonPath('message', 'This staff link has already been used. Start a new session from the admin panel.');

        $this->assertSame(1, PersonalAccessToken::count());
    }

    /*
     * One session per test. A second start() by the same person supersedes
     * the first, and a superseded code answers "this staff session has ended"
     * before its minute is ever looked at — which is how an earlier version of
     * this test passed with the expiry check deleted.
     */

    public function test_the_handoff_code_expires_after_a_minute(): void
    {
        ['session' => $session, 'code' => $code] = $this->start();

        $this->travel(Impersonation::HANDOFF_SECONDS + 1)->seconds();

        $this->exchange($code)
            ->assertStatus(410)
            ->assertJsonPath('message', 'This staff link has expired — it works for a minute. Start a new session from the admin panel.');

        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertNull($session->fresh()->exchanged_at);
    }

    public function test_the_handoff_code_works_inside_its_minute(): void
    {
        $code = $this->start()['code'];

        $this->travel(Impersonation::HANDOFF_SECONDS - 1)->seconds();

        $this->exchange($code)->assertOk();
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_a_code_that_was_never_issued_is_not_found(): void
    {
        $this->exchange(Str::random(48))->assertNotFound();
        $this->exchange('')->assertUnprocessable();
    }

    // --- the end ------------------------------------------------------------------

    public function test_ending_revokes_the_token_and_is_recorded(): void
    {
        ['session' => $session, 'code' => $code] = $this->start();
        $token = $this->exchange($code)->json('token');

        $this->as($token)->deleteJson('/api/impersonation')->assertOk();

        $this->as($token)->getJson('/api/organizer/overview')->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::count());

        $session->refresh();
        $this->assertSame('ended', $session->ended_how);
        $this->assertSame($this->support->id, $session->ended_by);

        $ended = AuditLog::where('action', 'impersonation.ended')->sole();
        $this->assertSame($this->support->id, $ended->actor_id);
        $this->assertSame($this->org->id, $ended->organization_id);
        $this->assertSame('ended', $ended->metadata['how']);
    }

    public function test_signing_out_ends_the_session_too(): void
    {
        ['session' => $session, 'code' => $code] = $this->start();
        $token = $this->exchange($code)->json('token');

        $this->as($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertSame('signed_out', $session->fresh()->ended_how);
        $this->assertSame('signed_out', AuditLog::where('action', 'impersonation.ended')->sole()->metadata['how']);
        $this->as($token)->getJson('/api/organizer/overview')->assertUnauthorized();
    }

    public function test_an_expired_session_is_refused_and_closed_on_the_record(): void
    {
        ['session' => $session, 'code' => $code] = $this->start();
        $token = $this->exchange($code)->json('token');

        $this->travel(Impersonation::MINUTES + 1)->minutes();

        $this->as($token)->getJson('/api/organizer/overview')->assertUnauthorized();
        $this->as($token)->getJson('/api/impersonation')->assertUnauthorized();

        $this->artisan('impersonation:close-lapsed')->assertSuccessful();

        $this->assertSame('expired', $session->fresh()->ended_how);
        $this->assertSame('expired', $session->fresh()->state());
        $this->assertSame('expired', AuditLog::where('action', 'impersonation.ended')->sole()->metadata['how']);
    }

    public function test_a_link_never_opened_is_closed_as_unused(): void
    {
        $session = $this->start()['session'];

        $this->travel(2)->minutes();
        app(Impersonation::class)->closeLapsed();

        $this->assertSame('unused', $session->fresh()->ended_how);
    }

    public function test_losing_the_staff_role_ends_the_session_at_the_next_request(): void
    {
        ['session' => $session, 'code' => $code] = $this->start();
        $token = $this->exchange($code)->json('token');

        $this->support->forceFill(['platform_role' => null])->save();

        $this->as($token)->getJson('/api/organizer/overview')->assertUnauthorized();
        $this->assertSame('staff_role_removed', $session->fresh()->ended_how);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_signing_a_staff_member_out_everywhere_ends_their_sessions_and_unopened_links(): void
    {
        $admin = $this->staff(PlatformRole::Admin, 'Ade Admin');

        // A link not yet opened: it would otherwise still trade for a token.
        ['session' => $waiting, 'code' => $code] = $this->start();

        app(AccountActions::class)->signOutEverywhere($this->support, $admin);

        $this->exchange($code)->assertStatus(410);
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame('revoked', $waiting->fresh()->ended_how);
        $this->assertSame($admin->id, $waiting->fresh()->ended_by);

        $ended = AuditLog::where('action', 'impersonation.ended')->sole();
        $this->assertSame($admin->id, $ended->actor_id);
        $this->assertSame('revoked', $ended->metadata['how']);

        // And one already open ends at once, on the record, rather than
        // reading as an hour that ran out.
        ['session' => $open, 'code' => $code] = $this->start(reason: 'Back on the same ticket');
        $token = $this->exchange($code)->json('token');

        app(AccountActions::class)->signOutEverywhere($this->support, $admin);

        $this->as($token)->getJson('/api/organizer/overview')->assertUnauthorized();
        $this->assertSame('revoked', $open->fresh()->ended_how);
        $this->assertTrue($open->fresh()->ended_at->lt($open->fresh()->expires_at));
    }

    public function test_a_new_password_ends_staff_sessions_too(): void
    {
        ['session' => $waiting, 'code' => $code] = $this->start();

        $reset = Password::broker()->createToken($this->support);
        $this->forgetAuth();
        $this->postJson('/api/auth/reset-password', [
            'token' => $reset,
            'email' => $this->support->email,
            'password' => 'a-new-passphrase-2026',
            'password_confirmation' => 'a-new-passphrase-2026',
        ])->assertOk();

        $this->exchange($code)->assertStatus(410);
        $this->assertSame('revoked', $waiting->fresh()->ended_how);
        $this->assertSame($this->support->id, $waiting->fresh()->ended_by);

        // Changed from their own console sign-in: every other sign-in ends,
        // the staff session with it.
        ['session' => $open, 'code' => $code] = $this->start(reason: 'Back on the same ticket');
        $staffToken = $this->exchange($code)->json('token');
        $ownToken = $this->support->createToken('web', ['attendee', 'organizer'])->plainTextToken;

        $this->as($ownToken)->postJson('/api/auth/password', [
            'current_password' => 'a-new-passphrase-2026',
            'password' => 'another-passphrase-2027',
            'password_confirmation' => 'another-passphrase-2027',
        ])->assertOk();

        $this->assertSame('revoked', $open->fresh()->ended_how);
        $this->as($staffToken)->getJson('/api/organizer/overview')->assertUnauthorized();
    }

    public function test_starting_again_ends_what_the_same_person_still_holds(): void
    {
        ['session' => $first, 'code' => $code] = $this->start();
        $token = $this->exchange($code)->json('token');

        $this->start(reason: 'Following up on the same ticket');

        $this->assertSame('superseded', $first->fresh()->ended_how);
        $this->as($token)->getJson('/api/organizer/overview')->assertUnauthorized();
    }

    // --- who may start one ----------------------------------------------------------

    public function test_only_administrators_and_support_can_start_a_session(): void
    {
        $this->assertInstanceOf(ImpersonationSession::class, $this->start($this->staff(PlatformRole::Admin))['session']);

        foreach ([
            'an ordinary account' => $this->staff(null),
            'finance' => $this->staff(PlatformRole::Finance),
            'the organization’s own owner' => $this->owner,
            'support with an unverified address' => User::factory()->create([
                'platform_role' => PlatformRole::Support,
                'email_verified_at' => null,
            ]),
        ] as $who => $user) {
            try {
                $this->start($user);
                $this->fail("{$who} started a staff session.");
            } catch (ImpersonationRefused $refused) {
                $this->assertSame(403, $refused->status, $who);
            }
        }
    }

    public function test_a_reason_is_required(): void
    {
        foreach (['', '   ', 'because'] as $reason) {
            try {
                $this->start(reason: $reason);
                $this->fail("Started with reason '{$reason}'.");
            } catch (ImpersonationRefused $refused) {
                $this->assertSame(422, $refused->status);
            }
        }

        $this->assertSame(0, ImpersonationSession::count());
        $this->assertSame(0, AuditLog::where('action', 'impersonation.started')->count());
    }

    public function test_a_removed_organization_cannot_be_opened(): void
    {
        $code = $this->start()['code'];

        $this->org->delete();

        // A link made before the removal stops working with it.
        $this->exchange($code)->assertStatus(410);
        $this->assertSame(0, PersonalAccessToken::count());

        try {
            $this->start(reason: 'Following up after the removal');
            $this->fail('Started a session for a removed organization.');
        } catch (ImpersonationRefused $refused) {
            $this->assertSame(422, $refused->status);
        }
    }

    public function test_an_organization_token_cannot_read_or_end_a_staff_session(): void
    {
        $ownerToken = $this->owner->createToken('web', ['attendee', 'organizer'])->plainTextToken;

        $this->as($ownerToken)->getJson('/api/impersonation')->assertForbidden();
        $this->as($ownerToken)->deleteJson('/api/impersonation')->assertForbidden();
    }

    // --- keeping the list honest -----------------------------------------------------

    public function test_every_refused_action_names_a_real_route(): void
    {
        $actions = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->getActionName());

        foreach ([...array_keys(WhileImpersonating::REFUSED), ...WhileImpersonating::REFUSED_WHEN] as $action) {
            $this->assertTrue($actions->contains($action), "{$action} is refused but no route reaches it — was it renamed?");
        }
    }

    private function order(): Order
    {
        return Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => 5000,
            'tax_amount' => 650,
            'net_revenue_amount' => 5000,
            'service_charge_amount' => 300,
            'total_amount' => 5950,
            'gateway' => 'stripe',
            'gateway_reference' => 'pi_test',
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }
}
