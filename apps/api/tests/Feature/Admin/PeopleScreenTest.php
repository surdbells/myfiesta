<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Users\RelationManagers\TicketsRelationManager;
use App\Filament\Resources\Users\UserResource;
use App\Mail\TicketsResent;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Checkout\TicketIssuer;
use App\Services\StaffSupport\AccountActions;
use App\Services\StaffSupport\StaffActionRefused;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The People screen: finding somebody, seeing everything about them, and the
 * few things support may do to an account — each gated by role and written to
 * the audit trail with the member of staff as the actor.
 */
class PeopleScreenTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    public function test_every_staff_role_can_open_it_and_nobody_else_can(): void
    {
        foreach (PlatformRole::cases() as $role) {
            $this->actAs($this->staff($role));
            $this->assertTrue(UserResource::canViewAny(), $role->value.' could not open People.');
        }

        $this->actAs(User::factory()->create());
        $this->assertFalse(UserResource::canViewAny(), 'A ticket buyer reached People.');

        $this->actAs($this->staff(PlatformRole::Admin));
        $this->assertFalse(UserResource::canCreate());
        $this->assertFalse(UserResource::canDeleteAny());
    }

    public function test_people_are_found_by_name_email_or_phone(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));

        $ada = User::factory()->create(['name' => 'Ada Okafor', 'email' => 'ada@example.com', 'phone' => '+14165550101']);
        $tunde = User::factory()->create(['name' => 'Tunde Bello', 'email' => 'tunde@example.com', 'phone' => '+2348030000000']);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$ada, $tunde])
            ->searchTable('okafor')
            ->assertCanSeeTableRecords([$ada])
            ->assertCanNotSeeTableRecords([$tunde])
            ->searchTable('tunde@example')
            ->assertCanSeeTableRecords([$tunde])
            ->assertCanNotSeeTableRecords([$ada])
            ->searchTable('2348030')
            ->assertCanSeeTableRecords([$tunde])
            ->assertCanNotSeeTableRecords([$ada]);
    }

    public function test_the_filters_narrow_to_what_they_say(): void
    {
        $support = $this->actAs($this->staff(PlatformRole::Support));

        $organizer = $this->member($this->organization(), Role::Owner, ['created_at' => now()->subYear()]);
        $buyer = User::factory()->unverified()->create(['password' => null]);
        $gone = User::factory()->create();
        $gone->delete();

        Livewire::test(ListUsers::class)
            ->filterTable('has_organizations', true)
            ->assertCanSeeTableRecords([$organizer])
            ->assertCanNotSeeTableRecords([$buyer, $support])
            ->resetTableFilters()
            ->filterTable('staff', true)
            ->assertCanSeeTableRecords([$support])
            ->assertCanNotSeeTableRecords([$organizer, $buyer])
            ->resetTableFilters()
            ->filterTable('platform_role', [PlatformRole::Support->value])
            ->assertCanSeeTableRecords([$support])
            ->resetTableFilters()
            ->filterTable('verified', false)
            ->assertCanSeeTableRecords([$buyer])
            ->assertCanNotSeeTableRecords([$organizer])
            ->resetTableFilters()
            ->filterTable('claimed', false)
            ->assertCanSeeTableRecords([$buyer])
            ->assertCanNotSeeTableRecords([$organizer])
            ->resetTableFilters()
            ->filterTable('created', ['from' => now()->subYears(2)->toDateString(), 'until' => now()->subMonths(6)->toDateString()])
            ->assertCanSeeTableRecords([$organizer])
            ->assertCanNotSeeTableRecords([$buyer, $support])
            ->resetTableFilters()
            // Active accounts by default; deactivated ones when asked for.
            ->assertCanNotSeeTableRecords([$gone])
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$gone])
            ->assertCanNotSeeTableRecords([$buyer]);
    }

    public function test_the_columns_sort_and_count(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));

        $organization = $this->organization();
        $owner = $this->member($organization, Role::Owner, ['name' => 'Zed Owner']);
        $event = $this->event($organization);
        $this->paidOrder($event, $this->ticketType($event), 1, ['user_id' => $owner->id]);

        Livewire::test(ListUsers::class)
            ->sortTable('name')
            ->assertSuccessful()
            ->sortTable('orders_count', 'desc')
            ->assertSuccessful()
            ->sortTable('last_seen', 'desc')
            ->assertSuccessful()
            ->assertTableColumnStateSet('orders_count', 1, $owner)
            ->assertTableColumnStateSet('organizations_count', 1, $owner);
    }

    public function test_the_page_shows_organizations_orders_tickets_and_history_without_ticket_codes(): void
    {
        $support = $this->actAs($this->staff(PlatformRole::Support));

        $organization = $this->organization('Eko Live');
        $person = $this->member($organization, Role::Manager, ['name' => 'Ngozi Eze']);
        $event = $this->event($organization);
        $order = $this->paidOrder($event, $this->ticketType($event), 1, ['user_id' => $person->id, 'buyer_email' => $person->email]);
        $ticket = $order->tickets()->first();
        $ticket->update(['owner_user_id' => $person->id]);

        app(AccountActions::class)->signOutEverywhere($person, $this->staff(PlatformRole::Admin));

        Livewire::test(ViewUser::class, ['record' => $person->getKey()])
            ->assertSuccessful()
            ->assertSee('Ngozi Eze')
            ->assertSee('Eko Live')
            ->assertSee('Manager')
            ->assertSee('user.signed_out_everywhere')
            ->assertDontSee($ticket->code);

        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $person, 'pageClass' => ViewUser::class])
            ->assertCanSeeTableRecords([$order]);

        Livewire::test(TicketsRelationManager::class, ['ownerRecord' => $person, 'pageClass' => ViewUser::class])
            ->assertCanSeeTableRecords([$ticket])
            ->assertDontSee($ticket->code);
    }

    public function test_support_resends_an_orders_tickets_from_the_persons_page(): void
    {
        Mail::fake();
        $support = $this->actAs($this->staff(PlatformRole::Support));

        $person = User::factory()->create(['email' => 'ngozi@example.com']);
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event), 1, ['user_id' => $person->id, 'buyer_email' => $person->email]);

        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $person, 'pageClass' => ViewUser::class])
            ->callTableAction('resendTickets', $order)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Tickets email resent');

        Mail::assertQueued(TicketsResent::class, fn (TicketsResent $mail) => $mail->hasTo('ngozi@example.com'));

        $entry = AuditLog::where('action', 'order.tickets_resent')->sole();
        $this->assertSame($support->id, $entry->actor_id);
        $this->assertSame($order->id, $entry->subject_id);
    }

    public function test_the_page_leads_to_guest_orders_under_the_same_address(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));

        $person = User::factory()->create(['email' => 'ngozi@example.com']);
        $event = $this->event($this->organization());
        $type = $this->ticketType($event);
        // Bought as a guest before the account existed: not the account's own.
        $guest = $this->paidOrder($event, $type, 1, ['buyer_email' => 'ngozi@example.com', 'buyer_name' => 'Ngozi Eze']);
        $other = $this->paidOrder($event, $type, 1);

        $url = OrderResource::getUrl('index', ['search' => 'ngozi@example.com']);

        Livewire::test(ViewUser::class, ['record' => $person->getKey()])
            ->assertActionVisible('ordersByAddress')
            ->assertActionHasUrl('ordersByAddress', $url);

        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $person, 'pageClass' => ViewUser::class])
            ->assertCanNotSeeTableRecords([$guest]);

        Livewire::withQueryParams(['search' => 'ngozi@example.com'])
            ->test(ListOrders::class)
            ->assertCanSeeTableRecords([$guest])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_support_sends_a_reset_link_and_the_trail_names_them(): void
    {
        Notification::fake();
        $support = $this->actAs($this->staff(PlatformRole::Support));
        $buyer = User::factory()->create();

        Livewire::test(ListUsers::class)
            ->callTableAction('sendPasswordReset', $buyer)
            ->assertHasNoTableActionErrors();

        Notification::assertSentTo($buyer, ResetPassword::class);

        $entry = AuditLog::where('action', 'user.password_reset_sent')->sole();
        $this->assertSame($support->id, $entry->actor_id);
        $this->assertSame($buyer->id, $entry->subject_id);
    }

    public function test_support_resends_the_verification_email(): void
    {
        Mail::fake();
        $support = $this->actAs($this->staff(PlatformRole::Support));
        $unverified = User::factory()->unverified()->create();
        $verified = User::factory()->create();

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('resendVerification', $verified)
            ->callTableAction('resendVerification', $unverified)
            ->assertHasNoTableActionErrors();

        $this->assertSame($support->id, AuditLog::where('action', 'user.verification_resent')->sole()->actor_id);
    }

    public function test_nobody_acts_on_their_own_account_and_only_admins_on_staff(): void
    {
        $support = $this->actAs($this->staff(PlatformRole::Support));
        $finance = $this->staff(PlatformRole::Finance);

        Livewire::test(ListUsers::class)
            ->filterTable('staff', true)
            ->assertTableActionHidden('signOutEverywhere', $support)
            ->assertTableActionHidden('signOutEverywhere', $finance)
            ->assertTableActionHidden('sendPasswordReset', $finance);

        // And the service refuses the same cases if a hidden button is pressed.
        foreach ([[$support, $support], [$finance, $support]] as [$target, $actor]) {
            try {
                app(AccountActions::class)->signOutEverywhere($target, $actor);
                $this->fail('Support signed out '.($target->is($actor) ? 'themselves' : 'a colleague').'.');
            } catch (StaffActionRefused) {
                $this->addToAssertionCount(1);
            }
        }

        $admin = $this->actAs($this->staff(PlatformRole::Admin));

        Livewire::test(ListUsers::class)
            ->filterTable('staff', true)
            ->assertTableActionVisible('signOutEverywhere', $finance)
            ->assertTableActionHidden('signOutEverywhere', $admin);
    }

    public function test_signing_out_everywhere_ends_tokens_sessions_and_remember_me(): void
    {
        $support = $this->actAs($this->staff(PlatformRole::Support));
        $buyer = User::factory()->create();
        $buyer->createToken('phone');
        $buyer->createToken('laptop');
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $buyer->id, 'payload' => '', 'last_activity' => time()]);
        $remember = $buyer->remember_token;

        Livewire::test(ListUsers::class)
            ->callTableAction('signOutEverywhere', $buyer)
            ->assertHasNoTableActionErrors();

        $this->assertSame(0, $buyer->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $buyer->id)->count());
        $this->assertNotSame($remember, $buyer->fresh()->remember_token);

        $entry = AuditLog::where('action', 'user.signed_out_everywhere')->sole();
        $this->assertSame($support->id, $entry->actor_id);
        $this->assertSame(2, $entry->metadata['tokens']);
    }

    public function test_only_an_administrator_deactivates_and_it_can_be_undone(): void
    {
        $buyer = User::factory()->create();
        $buyer->createToken('phone');

        foreach ([PlatformRole::Support, PlatformRole::Finance] as $role) {
            $this->actAs($this->staff($role));

            Livewire::test(ListUsers::class)
                ->assertTableActionHidden('deactivate', $buyer);
        }

        $admin = $this->actAs($this->staff(PlatformRole::Admin));

        Livewire::test(ListUsers::class)
            ->callTableAction('deactivate', $buyer, data: ['reason' => 'Chargeback fraud across three organizers.'])
            ->assertHasNoTableActionErrors();

        $this->assertSoftDeleted($buyer);
        $this->assertSame(0, $buyer->tokens()->count());
        $this->assertSame('Chargeback fraud across three organizers.', AuditLog::where('action', 'user.deactivated')->sole()->metadata['reason']);

        Livewire::test(ViewUser::class, ['record' => $buyer->getKey()])
            ->assertSee('Deactivated')
            ->callAction('reactivate', data: ['note' => 'Cleared by the bank.'])
            ->assertHasNoActionErrors();

        $this->assertNotSoftDeleted($buyer);
        $this->assertSame($admin->id, AuditLog::where('action', 'user.reactivated')->sole()->actor_id);
    }

    public function test_a_deactivated_address_can_still_be_sold_to_given_a_comp_and_sent_a_ticket(): void
    {
        $admin = $this->staff(PlatformRole::Admin);
        $gone = User::factory()->create(['email' => 'gone@example.com']);
        app(AccountActions::class)->deactivate($gone, $admin, 'Chargeback fraud across three organizers.');

        $event = $this->event($this->organization());
        $type = $this->ticketType($event);
        $issuer = app(TicketIssuer::class);

        // A guest checkout under the same address, fulfilled.
        $order = $this->paidOrder($event, $type, 1, ['buyer_email' => 'gone@example.com', 'buyer_name' => 'Gone Person']);
        $issued = $issuer->issueFor($order);
        $this->assertCount(1, $issued);
        $this->assertSame($gone->id, $issued[0]->owner_user_id);

        // A comp from a guest list.
        $this->assertSame($gone->id, $issuer->issueComp($event->id, $type->id, 'Gone@Example.com', 'Gone Person')->owner_user_id);

        // Somebody else's ticket, sent on to that address.
        $holder = User::factory()->create();
        $ticket = $this->paidOrder($event, $type, 1, ['user_id' => $holder->id, 'buyer_email' => $holder->email])->tickets()->sole();

        Sanctum::actingAs($holder, [TokenAbility::Attendee->value]);
        $this->postJson("/api/tickets/{$ticket->id}/transfer", ['email' => 'gone@example.com', 'name' => 'Gone Person'])
            ->assertOk();
        $this->assertSame($gone->id, $ticket->fresh()->owner_user_id);

        // Still one account for the address, still closed.
        $this->assertSame(1, User::withTrashed()->where('email', 'gone@example.com')->count());
        $this->assertSoftDeleted($gone);
    }

    public function test_support_see_what_was_done_to_a_colleague_but_not_what_the_colleague_did(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));
        $admin = $this->staff(PlatformRole::Admin);
        $finance = $this->staff(PlatformRole::Finance);
        $organization = $this->organization();

        $auditor = app(Auditor::class);
        $auditor->record('payout_details.revealed', $organization, $finance, $organization->id, ['last_four' => '6789']);
        $auditor->record('user.password_reset_sent', $finance, $admin);

        // The platform-wide trail is not support's to read, including one
        // colleague's slice of it.
        Livewire::test(ViewUser::class, ['record' => $finance->getKey()])
            ->assertSuccessful()
            ->assertSee('user.password_reset_sent')
            ->assertDontSee('payout_details.revealed');

        $this->actAs($admin);

        Livewire::test(ViewUser::class, ['record' => $finance->getKey()])
            ->assertSee('user.password_reset_sent')
            ->assertSee('payout_details.revealed');
    }

    public function test_an_order_and_ticket_by_a_deactivated_account_still_lead_to_it(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));

        $person = User::factory()->create(['email' => 'ngozi@example.com']);
        $event = $this->event($this->organization());
        $order = $this->paidOrder($event, $this->ticketType($event), 1, ['user_id' => $person->id, 'buyer_email' => $person->email]);
        $ticket = $order->tickets()->sole();

        app(AccountActions::class)->deactivate($person, $this->staff(PlatformRole::Admin), 'Chargeback fraud across three organizers.');

        $url = UserResource::getUrl('view', ['record' => $person->id]);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSee('ngozi@example.com (deactivated)')
            ->assertSeeHtml($url)
            ->assertDontSee('Guest checkout');

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->assertSee('ngozi@example.com (deactivated)')
            ->assertSeeHtml($url)
            ->assertDontSee('No account');
    }

    public function test_an_administrator_cannot_deactivate_themselves(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));

        Livewire::test(ListUsers::class)
            ->filterTable('staff', true)
            ->assertTableActionHidden('deactivate', $admin);

        $this->expectException(StaffActionRefused::class);
        app(AccountActions::class)->deactivate($admin, $admin, 'Testing what happens here.');
    }

    public function test_an_erased_account_stays_closed(): void
    {
        $admin = $this->staff(PlatformRole::Admin);
        $erased = User::factory()->create(['email' => 'erased-abc123@erased.invalid']);
        $erased->delete();

        $this->expectException(StaffActionRefused::class);
        app(AccountActions::class)->reactivate($erased, $admin);
    }
}
