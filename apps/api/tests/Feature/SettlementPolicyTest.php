<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who sees what has been paid, asked two ways.
 *
 * The payouts statement lists settlements to anybody who holds money.view.
 * SettlementPolicy answered the same question with its own list of roles, and
 * the two had parted: a manager was shown every settlement on the statement
 * and refused them by the policy. Nothing calls the policy yet, which is why
 * nobody saw it — and why the first thing to call it would have been wrong.
 *
 * So these ask both, for every role, and expect the same answer. The expected
 * answers are written out rather than derived, so changing who sees the money
 * means changing this list on purpose.
 */
class SettlementPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);
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

    private function signedInAs(Role $role): User
    {
        $user = $this->member($role);

        Sanctum::actingAs($user, [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        return $user;
    }

    private function settlement(Organization $organization, ?Event $event = null): Settlement
    {
        return Settlement::create([
            'organization_id' => $organization->id,
            'event_id' => $event?->id,
            'amount' => 15_000,
            'currency' => 'CAD',
            'rail' => 'interac',
            'type' => 'partial',
            'status' => 'success',
            'settled_at' => now(),
        ]);
    }

    /** @return array<string, array{Role, bool}> */
    public static function roles(): array
    {
        return [
            'an owner' => [Role::Owner, true],
            // The one they disagreed about. A manager has seen the statement,
            // settlements and all, since money.view was theirs.
            'a manager' => [Role::Manager, true],
            'finance' => [Role::Finance, true],
            'marketing' => [Role::Marketing, false],
            'door staff' => [Role::Door, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_the_policy_and_the_statement_give_the_same_answer(Role $role, bool $sees): void
    {
        $user = $this->signedInAs($role);
        $settlement = $this->settlement($this->org, $this->event);

        // Asked through the gate, the way a controller would ask it.
        $this->assertSame($sees, $user->can('viewAny', [Settlement::class, $this->org->id]));
        $this->assertSame($sees, $user->can('view', $settlement));

        $statement = $this->getJson('/api/organizer/payouts');

        if ($sees) {
            $statement->assertOk()->assertJsonPath('settlements.0.id', $settlement->id);
        } else {
            $statement->assertForbidden();
        }
    }

    public function test_seeing_your_own_payouts_is_not_seeing_anybody_elses(): void
    {
        $elsewhere = Organization::create(['name' => 'Danforth Sessions', 'slug' => 'danforth-sessions']);
        $theirs = $this->settlement($elsewhere);

        $owner = $this->member(Role::Owner);

        $this->assertFalse($owner->can('viewAny', [Settlement::class, $elsewhere->id]));
        $this->assertFalse($owner->can('view', $theirs));
    }

    /**
     * Nobody in an organization records a payout or edits one — owners
     * included.
     *
     * A settlement says money reached a real bank account. One an organizer
     * could write would be an organizer marking themselves as paid.
     */
    public function test_nobody_in_an_organization_records_or_changes_a_settlement(): void
    {
        $settlement = $this->settlement($this->org);

        foreach (Role::cases() as $role) {
            $user = $this->member($role);

            $this->assertFalse($user->can('create', Settlement::class), "{$role->value} could record a settlement");
            $this->assertFalse($user->can('update', $settlement), "{$role->value} could change a settlement");
            // Corrected by reversal, never removed: there is no delete to allow.
            $this->assertFalse($user->can('delete', $settlement), "{$role->value} could delete a settlement");
        }
    }
}
