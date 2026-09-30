<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Mail\TeamInvitationMail;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An organization's team: inviting, joining, roles and removal.
 */
class TeamTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->owner = $this->member(Role::Owner, 'ada@lagosnights.test');
    }

    private function member(Role $role, ?string $email = null): User
    {
        $user = User::factory()->create($email ? ['email' => $email] : []);
        $this->org->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role->value, 'accepted_at' => now()]);

        return $user->fresh()->load('organizations');
    }

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user->fresh()->load('organizations'), [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);
    }

    /** Invite, and capture the token the email carried. */
    private function invite(string $email = 'finance@example.com', string $role = 'finance'): string
    {
        $this->actAs($this->owner);
        $this->postJson('/api/organizer/team/invitations', ['email' => $email, 'role' => $role])->assertCreated();

        $token = null;
        Mail::assertSent(TeamInvitationMail::class, function (TeamInvitationMail $mail) use ($email, &$token) {
            if ($mail->hasTo($email)) {
                $token = $mail->token;
            }

            return $mail->hasTo($email);
        });

        return $token;
    }

    public function test_an_owner_invites_and_the_invitee_joins_in_that_role(): void
    {
        $token = $this->invite();

        $this->getJson("/api/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('organization', 'Lagos Nights')
            ->assertJsonPath('role_label', 'Finance')
            ->assertJsonPath('state', 'open')
            ->assertJsonPath('has_account', false);

        $invitee = User::factory()->create(['email' => 'finance@example.com']);
        Sanctum::actingAs($invitee, [TokenAbility::Attendee->value]);

        $session = $this->postJson("/api/invitations/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('organizations.0.role', 'finance')
            ->json();

        // A fresh token carrying the organizer ability they did not have before.
        $this->assertContains('organizer', $session['abilities']);
        $this->assertSame(Role::Finance, $invitee->fresh()->load('organizations')->roleIn($this->org));

        // Used once.
        $this->postJson("/api/invitations/{$token}/accept")->assertStatus(422);
    }

    public function test_the_database_keeps_only_a_hash_of_the_link(): void
    {
        $token = $this->invite();

        $this->assertSame(0, OrganizationInvitation::where('token_hash', $token)->count());
        $this->assertSame(1, OrganizationInvitation::where('token_hash', hash('sha256', $token))->count());
    }

    public function test_a_forwarded_invitation_does_not_work_for_somebody_else(): void
    {
        $token = $this->invite();

        Sanctum::actingAs(User::factory()->create(['email' => 'someone.else@example.com']), [TokenAbility::Attendee->value]);

        $this->postJson("/api/invitations/{$token}/accept")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'sent to finance@example.com'));
    }

    public function test_a_new_person_creates_an_account_straight_into_the_team(): void
    {
        $token = $this->invite('new.hire@example.com', 'marketing');

        $this->postJson('/api/auth/register', [
            'name' => 'New Hire',
            'email' => 'new.hire@example.com',
            'password' => 'correct horse 42',
            'password_confirmation' => 'correct horse 42',
            'invitation' => $token,
            'accept_terms' => true,
        ])->assertCreated()
            ->assertJsonCount(1, 'organizations')
            ->assertJsonPath('organizations.0.name', 'Lagos Nights')
            ->assertJsonPath('organizations.0.role', 'marketing');

        // No stray organization of their own.
        $this->assertSame(1, Organization::count());
    }

    public function test_registering_with_an_invitation_under_another_address_is_refused(): void
    {
        $token = $this->invite('new.hire@example.com', 'marketing');

        $this->postJson('/api/auth/register', [
            'name' => 'Imposter', 'email' => 'imposter@example.com',
            'password' => 'correct horse 42', 'password_confirmation' => 'correct horse 42',
            'invitation' => $token,
            'accept_terms' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame(0, User::where('email', 'imposter@example.com')->count());
    }

    public function test_inviting_again_replaces_the_old_link(): void
    {
        $first = $this->invite();
        Mail::fake();
        $second = $this->invite();

        $this->getJson("/api/invitations/{$first}")->assertJsonPath('state', 'revoked');
        $this->getJson("/api/invitations/{$second}")->assertJsonPath('state', 'open');
    }

    public function test_an_expired_or_withdrawn_invitation_says_so(): void
    {
        $token = $this->invite();
        $this->travel(8)->days();
        $this->getJson("/api/invitations/{$token}")->assertJsonPath('state', 'expired');

        $this->travelBack();
        $token = $this->invite('door@example.com', 'door');
        $invitation = OrganizationInvitation::where('email', 'door@example.com')->whereNull('revoked_at')->sole();

        $this->deleteJson("/api/organizer/team/invitations/{$invitation->id}")->assertOk();
        $this->getJson("/api/invitations/{$token}")->assertJsonPath('state', 'revoked');
    }

    public function test_the_team_list_shows_members_and_open_invitations(): void
    {
        $this->member(Role::Door, 'door.one@example.com');
        $this->invite('pending@example.com', 'manager');

        $this->getJson('/api/organizer/team')
            ->assertOk()
            ->assertJsonPath('members.0.role', 'owner')
            ->assertJsonPath('members.0.is_you', true)
            ->assertJsonPath('members.1.email', 'door.one@example.com')
            ->assertJsonPath('invitations.0.email', 'pending@example.com')
            ->assertJsonCount(5, 'roles');
    }

    public function test_only_owners_manage_the_team(): void
    {
        $this->actAs($this->member(Role::Manager));

        $this->getJson('/api/organizer/team')->assertForbidden();
        $this->postJson('/api/organizer/team/invitations', ['email' => 'x@example.com', 'role' => 'owner'])->assertForbidden();
    }

    public function test_roles_change_and_members_are_removed_but_the_last_owner_stays(): void
    {
        $door = $this->member(Role::Door);
        $this->actAs($this->owner);

        $this->patchJson("/api/organizer/team/members/{$door->id}", ['role' => 'manager'])->assertOk();
        $this->assertSame(Role::Manager, $door->fresh()->load('organizations')->roleIn($this->org));

        // The only owner cannot step down or be removed — including by themselves.
        $this->patchJson("/api/organizer/team/members/{$this->owner->id}", ['role' => 'manager'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'needs an owner'));
        $this->deleteJson("/api/organizer/team/members/{$this->owner->id}")->assertStatus(422);

        $this->deleteJson("/api/organizer/team/members/{$door->id}")->assertOk();
        $this->assertNull($door->fresh()->load('organizations')->roleIn($this->org));

        // With a second owner, the first can step down.
        $second = $this->member(Role::Owner);
        $this->patchJson("/api/organizer/team/members/{$this->owner->id}", ['role' => 'manager'])->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'team.removed')->count());
        $this->assertSame(2, AuditLog::where('action', 'team.role_changed')->count());
    }

    public function test_a_removed_member_loses_access_at_once(): void
    {
        $marketing = $this->member(Role::Marketing);
        $this->actAs($this->owner);
        $this->deleteJson("/api/organizer/team/members/{$marketing->id}")->assertOk();

        $this->actAs($marketing);

        $this->getJson('/api/organizer/events', ['X-Organization' => $this->org->id])->assertForbidden();
    }

    public function test_the_invitation_email_says_what_the_role_can_do(): void
    {
        $token = $this->invite('finance@example.com', 'finance');
        $invitation = OrganizationInvitation::whereNull('revoked_at')->sole();

        $html = (new TeamInvitationMail($invitation->load(['organization', 'inviter']), $token))->render();

        $this->assertStringContainsString('see what events made, and process refunds', $html);
        $this->assertStringContainsString("/join/{$token}", $html);
    }

    /**
     * Every description finishes "you can …", in the email, on the join page
     * and after "Can " in the console's role menus — so each starts with what
     * the person does. Marketing's and the owner's had no verb: "Can the guest
     * list, promoter codes and messages to ticket holders".
     */
    public function test_every_role_is_described_as_something_somebody_can_do(): void
    {
        $verbs = ['do', 'run', 'see', 'manage', 'scan'];

        foreach (Role::cases() as $role) {
            $described = TeamInvitationMail::describe($role->value);

            $this->assertContains(strtok($described, ' '), $verbs, "As {$role->label()} you can {$described}.");
        }

        $this->assertSame('manage the guest list, promoter codes and messages to ticket holders', TeamInvitationMail::describe('marketing'));

        $token = $this->invite('marketing@example.com', 'marketing');
        $invitation = OrganizationInvitation::whereNull('revoked_at')->sole();
        $html = (new TeamInvitationMail($invitation->load(['organization', 'inviter']), $token))->render();

        $this->assertStringContainsString('As Marketing you can manage the guest list, promoter codes and messages to ticket holders.', $html);
    }
}
