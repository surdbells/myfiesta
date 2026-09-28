<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Http\Controllers\Api\DataRequestController;
use App\Mail\DataRequestDone;
use App\Mail\DataRequestVerify;
use App\Models\AuditLog;
use App\Models\DataRequest;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationFollow;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Impersonation\WhileImpersonating;
use App\Services\Staff\StaffAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Delete my account", from inside the phone app or the console.
 *
 * What is being protected: that it is the privacy page's erasure and not a
 * second, looser one — the same request, the same record, the same email
 * saying what was kept; that a phone left unlocked is not enough to do it;
 * that an address the account never proved cannot be used to erase whoever
 * does own it; that the only owner of an organization, or anybody with staff
 * access, is told before anything starts; and that an erasure either finishes
 * or leaves the account exactly as it was, never a nameless account whose
 * tokens still work.
 */
class AccountErasureTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

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

        TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 5000, 'status' => 'on_sale']);
    }

    private function person(bool $verified = true): User
    {
        return User::factory()->create([
            'name' => 'Ada Okafor',
            'email' => 'ada@example.com',
            'password' => 'correct horse 7',
            'email_verified_at' => $verified ? now() : null,
        ]);
    }

    /** A real token, so erasing the account can be seen to end it. */
    private function tokenFor(User $user): string
    {
        return $user->createToken('mobile', $user->tokenAbilities())->plainTextToken;
    }

    private function join(User $user, Organization $org, Role $role): void
    {
        $org->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role->value, 'accepted_at' => now()]);
    }

    private function bought(string $email = 'ada@example.com'): Order
    {
        $order = Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => $email,
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => 5000,
            'total_amount' => 5000,
            'net_revenue_amount' => 5000,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        Ticket::create([
            'event_id' => $this->event->id,
            'ticket_type_id' => $this->event->ticketTypes()->value('id'),
            'order_id' => $order->id,
            'owner_email' => $email,
            'holder_name' => 'Ada Okafor',
            'code' => strtoupper(Str::random(12)),
            'status' => 'valid',
        ]);

        LedgerEntry::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'order_id' => $order->id,
            'type' => 'sale',
            'amount' => 5000,
            'currency' => 'CAD',
            'occurred_at' => now(),
            'reason' => "Order {$order->reference}",
        ]);

        return $order;
    }

    private function erase(string $token, string $password = 'correct horse 7')
    {
        return $this->withToken($token)->postJson('/api/auth/erasure', ['current_password' => $password]);
    }

    // --- before it is asked for ----------------------------------------------------

    public function test_the_preview_names_the_organizations_and_which_of_them_stop_it(): void
    {
        $ada = $this->person();
        $this->join($ada, $this->org, Role::Owner);

        $other = Organization::create(['name' => 'Toronto Afters', 'slug' => 'toronto-afters']);
        $this->join($ada, $other, Role::Manager);

        $this->withToken($this->tokenFor($ada))->getJson('/api/auth/erasure')
            ->assertOk()
            ->assertJsonPath('email', 'ada@example.com')
            ->assertJsonPath('email_verified', true)
            ->assertJsonPath('kept_for_years', 7)
            ->assertJsonPath('refused', fn (?string $refused) => str_contains((string) $refused, 'Lagos Nights'))
            ->assertJsonCount(2, 'organizations')
            ->assertJson(['organizations' => [
                ['name' => 'Lagos Nights', 'role' => 'owner', 'only_owner' => true],
                ['name' => 'Toronto Afters', 'role' => 'manager', 'only_owner' => false],
            ]]);

        // Asking changes nothing.
        $this->assertSame(0, DataRequest::count());
    }

    public function test_the_preview_of_somebody_who_only_buys_tickets_is_unblocked(): void
    {
        $ada = $this->person();

        $this->withToken($this->tokenFor($ada))->getJson('/api/auth/erasure')
            ->assertOk()
            ->assertJsonPath('refused', null)
            ->assertJsonPath('organizations', []);
    }

    // --- doing it ------------------------------------------------------------------

    public function test_a_proved_account_is_erased_on_its_password_through_the_same_request(): void
    {
        $ada = $this->person();
        $order = $this->bought();
        $token = $this->tokenFor($ada);

        DB::table('saved_events')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $ada->id, 'event_id' => $this->event->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        OrganizationFollow::create(['user_id' => $ada->id, 'organization_id' => $this->org->id]);

        $this->erase($token)
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'ada@example.com'));

        // The privacy page's request, recorded the same way.
        $request = DataRequest::sole();
        $this->assertSame('erasure', $request->kind);
        $this->assertSame('completed', $request->status);
        $this->assertSame($ada->id, $request->user_id);
        $this->assertSame('ada@example.com', $request->email);
        $this->assertSame('closed', $request->outcome['erased']['account']['action']);
        $this->assertTrue(AuditLog::where('action', 'privacy.erasure')->exists());

        // No link to prove an address the account already proved; the email
        // saying what was kept goes as it does from the page.
        Mail::assertNotQueued(DataRequestVerify::class);
        Mail::assertQueued(DataRequestDone::class, fn ($mail) => $mail->hasTo('ada@example.com'));

        // The account is closed and the person gone from it.
        $ada = User::withTrashed()->find($ada->id);
        $this->assertNotNull($ada->deleted_at);
        $this->assertStringNotContainsString('ada@example.com', $ada->email);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        // Their own lists go with them.
        $this->assertSame(0, DB::table('saved_events')->count());
        $this->assertSame(0, OrganizationFollow::count());

        // The money stays, with nobody's name on it.
        $order->refresh();
        $this->assertSame(5000, (int) $order->total_amount);
        $this->assertSame('Erased', $order->buyer_name);
        $this->assertSame(1, LedgerEntry::count());
        $this->assertNull(Ticket::sole()->owner_email);

        // And the phone that asked is signed out with everything else.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_a_phone_left_unlocked_is_not_enough(): void
    {
        $ada = $this->person();
        $token = $this->tokenFor($ada);

        $this->erase($token, 'a guess 12345')
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->withToken($token)->postJson('/api/auth/erasure', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertSame(0, DataRequest::count());
        $this->assertNull($ada->fresh()->deleted_at);
    }

    public function test_guessing_the_password_is_limited_per_account(): void
    {
        $ada = $this->person();
        $token = $this->tokenFor($ada);

        for ($i = 0; $i < 5; $i++) {
            $this->erase($token, "guess number {$i}")->assertStatus(422);
        }

        // The right one now is refused too: otherwise this is a way to guess
        // it from a session somebody left open.
        $this->erase($token)->assertStatus(429);
        $this->assertNull($ada->fresh()->deleted_at);
    }

    public function test_an_unproved_address_is_sent_the_link_first(): void
    {
        $ada = $this->person(verified: false);
        $this->bought();

        $this->erase($this->tokenFor($ada))
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'ada@example.com'));

        // Nothing yet: the address has to show it is theirs before the
        // tickets found by it are erased.
        $request = DataRequest::sole();
        $this->assertSame('pending', $request->status);
        $this->assertSame($ada->id, $request->user_id);
        $this->assertNull($ada->fresh()->deleted_at);
        $this->assertSame('ada@example.com', Order::sole()->buyer_email);
        Mail::assertQueued(DataRequestVerify::class, fn ($mail) => $mail->hasTo('ada@example.com'));

        // The link is the privacy page's own, and finishes it.
        $this->post(route('privacy.confirm', $request->token))->assertOk()->assertSee('Done', false);

        $this->assertSame('completed', $request->fresh()->status);
        $this->assertNotNull(User::withTrashed()->find($ada->id)->deleted_at);
    }

    public function test_the_only_owner_is_told_before_anything_starts(): void
    {
        $ada = $this->person();
        $this->join($ada, $this->org, Role::Owner);

        $this->erase($this->tokenFor($ada))
            ->assertStatus(409)
            ->assertJsonPath('status', 'refused')
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Lagos Nights'));

        $this->assertSame(0, DataRequest::count());
        $this->assertNull($ada->fresh()->deleted_at);
        Mail::assertNothingQueued();

        // With a second owner the organization is not left behind, and the
        // same request goes through: Ada leaves it, and it keeps everything.
        $this->join(User::factory()->create(), $this->org, Role::Owner);
        $this->app['auth']->forgetGuards();

        $this->erase($this->tokenFor($ada->fresh()))->assertOk()->assertJsonPath('status', 'completed');

        $this->assertFalse($this->org->members()->where('users.id', $ada->id)->exists());
        $this->assertSame('published', $this->event->fresh()->status);
    }

    // --- somebody who has worked here ----------------------------------------------

    public function test_a_team_member_is_erased_and_what_they_did_stays_in_the_history(): void
    {
        // Nearly everybody the console serves: on a team, with something in
        // its history. The audit trail is append-only, and an erasure that
        // tried to blank their name in it was refused by the database halfway.
        $ada = $this->person();
        $this->join($ada, $this->org, Role::Manager);
        $entry = app(Auditor::class)->record('event.published', $this->event, $ada);
        $token = $this->tokenFor($ada);

        $this->withToken($token)->getJson('/api/auth/erasure')
            ->assertOk()
            ->assertJsonPath('refused', null)
            ->assertJsonPath('history_kept', true);

        $this->erase($token)->assertOk()->assertJsonPath('status', 'completed');

        // The entry is exactly as it was written: nobody edits that trail.
        $kept = AuditLog::find($entry->id);
        $this->assertSame($ada->id, $kept->actor_id);
        $this->assertSame('Ada Okafor', $kept->actor_label);

        // And the person is told so, rather than promised a clean slate.
        $request = DataRequest::sole();
        $this->assertSame('completed', $request->status);
        $this->assertSame('kept', $request->outcome['erased']['audit_logs']['action']);
        Mail::assertQueued(DataRequestDone::class, fn ($mail) => str_contains($mail->render(), 'under the name you had then'));

        // Everything else is done, all of it.
        $ada = User::withTrashed()->find($ada->id);
        $this->assertNotNull($ada->deleted_at);
        $this->assertSame('Erased', $ada->name);
        $this->assertFalse($this->org->members()->where('users.id', $ada->id)->exists());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertTrue(AuditLog::where('action', 'privacy.erasure')->exists());
    }

    public function test_somebody_with_no_history_is_not_told_they_have_one(): void
    {
        $this->withToken($this->tokenFor($this->person()))->getJson('/api/auth/erasure')
            ->assertOk()
            ->assertJsonPath('history_kept', false);
    }

    public function test_an_erasure_the_database_refuses_partway_leaves_nothing_half_done(): void
    {
        // The map as it was: blanking the name on the audit trail, which the
        // trigger refuses after the account row and the team place have
        // already gone. Whatever stops an erasure, it must not leave a
        // nameless account whose password and tokens still work.
        config(['personal_data.by_user.audit_logs' => [
            'strategy' => 'anonymise', 'key' => 'actor_id', 'columns' => ['actor_label', 'ip_address'],
        ]]);

        $ada = $this->person();
        $this->join($ada, $this->org, Role::Manager);
        app(Auditor::class)->record('event.published', $this->event, $ada);
        $token = $this->tokenFor($ada);

        $this->erase($token)->assertServerError();

        $ada = User::withTrashed()->find($ada->id);
        $this->assertSame('ada@example.com', $ada->email);
        $this->assertSame('Ada Okafor', $ada->name);
        $this->assertNull($ada->deleted_at);
        $this->assertTrue($this->org->members()->where('users.id', $ada->id)->exists());

        // Still pending, so the same request can finish once it is fixed —
        // not stuck at "verified" with nothing to retry it.
        $this->assertSame('pending', DataRequest::sole()->status);
        $this->assertFalse(AuditLog::where('action', 'privacy.erasure')->exists());
        Mail::assertNotQueued(DataRequestDone::class);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }

    // --- who cannot ----------------------------------------------------------------

    public function test_the_last_administrator_cannot_be_erased_on_a_password(): void
    {
        // The admin's sign-in codes go to this address. A password alone —
        // the thing most likely to have leaked — must not be able to shut it,
        // and with it the last way into the admin.
        $admin = $this->person();
        $admin->forceFill(['platform_role' => PlatformRole::Admin])->save();
        app(Auditor::class)->record('staff.signed_in', $admin, $admin);
        $token = $this->tokenFor($admin);

        $this->withToken($token)->getJson('/api/auth/erasure')
            ->assertOk()
            ->assertJsonPath('organizations', [])
            ->assertJsonPath('refused', fn (?string $refused) => str_contains((string) $refused, 'staff access'));

        $this->erase($token)
            ->assertStatus(409)
            ->assertJsonPath('status', 'refused')
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'another administrator'));

        $admin = $admin->fresh();
        $this->assertSame('ada@example.com', $admin->email);
        $this->assertSame(PlatformRole::Admin, $admin->platform_role);
        $this->assertNull($admin->deleted_at);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertSame(0, DataRequest::count());
        Mail::assertNothingQueued();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }

    /**
     * Staff, and the only owner of an organization.
     *
     * Only the staff access was named. The app then offered to open the
     * organization's team with no sentence saying why, and taking the staff
     * access away would only have led to a second refusal.
     */
    public function test_staff_who_are_also_the_only_owner_are_told_both(): void
    {
        $ada = $this->person();
        $ada->forceFill(['platform_role' => PlatformRole::Support])->save();
        $this->join($ada, $this->org, Role::Owner);

        $refused = $this->withToken($this->tokenFor($ada))->getJson('/api/auth/erasure')
            ->assertOk()
            ->assertJsonPath('organizations.0.only_owner', true)
            ->json('refused');

        $this->assertStringContainsString('staff access', $refused);
        $this->assertStringContainsString('You are also the only owner of Lagos Nights.', $refused);
        $this->assertStringEndsWith('Make somebody else an owner, or close the organization, and then ask again.', $refused);
        $this->assertSame(1, substr_count($refused, 'ask again'));
    }

    public function test_the_privacy_pages_link_refuses_a_staff_account_too(): void
    {
        $support = $this->person();
        $support->forceFill(['platform_role' => PlatformRole::Support])->save();

        $this->postJson('/api/privacy/requests', ['email' => 'ada@example.com', 'kind' => 'erasure'])->assertStatus(202);
        $request = DataRequest::sole();

        $this->post(route('privacy.confirm', $request->token))->assertOk()->assertSee('Not yet', false);

        $this->assertSame('refused', $request->fresh()->status);
        $this->assertSame(PlatformRole::Support, $support->fresh()->platform_role);
        $this->assertNull($support->fresh()->deleted_at);
    }

    public function test_once_another_administrator_removes_the_access_it_is_an_account_like_any_other(): void
    {
        $ada = $this->person();
        $ada->forceFill(['platform_role' => PlatformRole::Finance])->save();
        app(Auditor::class)->record('staff.signed_in', $ada, $ada);

        $other = User::factory()->create(['platform_role' => PlatformRole::Admin, 'email_verified_at' => now()]);
        app(StaffAccess::class)->revoke($ada, $other);

        $this->erase($this->tokenFor($ada->fresh()))->assertOk()->assertJsonPath('status', 'completed');

        $this->assertNotNull(User::withTrashed()->find($ada->id)->deleted_at);
        $this->assertTrue(AuditLog::where('action', 'staff.signed_in')->where('actor_id', $ada->id)->exists());
    }

    public function test_a_door_pass_cannot_reach_it(): void
    {
        $ada = $this->person();
        $door = $ada->createToken('door', ["door:{$this->event->id}"])->plainTextToken;

        $this->withToken($door)->getJson('/api/auth/erasure')->assertForbidden();
        $this->erase($door)->assertForbidden();

        $this->assertNull($ada->fresh()->deleted_at);
    }

    public function test_staff_acting_as_an_organization_cannot_reach_it(): void
    {
        foreach (['preview', 'erase'] as $action) {
            $this->assertSame(
                WhileImpersonating::OWN_ACCOUNT,
                WhileImpersonating::refusalFor(DataRequestController::class."@{$action}"),
            );
        }
    }
}
