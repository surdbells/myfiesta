<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Models\SensitiveDataAccess;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The statement, and where the money is sent.
 *
 * The two things worth being strict about here are that money is a permission
 * — this is the one screen where lacking it means refusal rather than a
 * quieter version — and that an account number, once written, never comes back
 * out of this API.
 */
class PayoutTest extends TestCase
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

    private function signedInAs(Role $role): User
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

        return $user;
    }

    private function entry(string $type, int $amount, ?string $eventId = null): void
    {
        LedgerEntry::create([
            'organization_id' => $this->org->id,
            'event_id' => $eventId ?? $this->event->id,
            'type' => $type,
            'amount' => $amount,
            'currency' => 'CAD',
            'occurred_at' => now(),
        ]);
    }

    // --- the statement -------------------------------------------------------

    public function test_the_balance_is_the_ledger_and_the_breakdown_adds_up_to_it(): void
    {
        $this->signedInAs(Role::Owner);

        $this->entry('sale', 50_000);
        $this->entry('tax', -6_500);
        $this->entry('settlement', -20_000);

        $body = $this->getJson('/api/organizer/payouts')->assertOk()->json();

        $this->assertSame(23_500, $body['balance']['amount']);
        $this->assertSame(20_000, $body['settled']['amount']);

        // A breakdown that does not sum to the figure above it is worse than
        // no breakdown, so this is asserted rather than assumed.
        $this->assertSame(
            $body['balance']['amount'],
            array_sum(array_column(array_column($body['events'], 'balance'), 'amount')),
        );
    }

    public function test_entries_with_no_event_are_kept_and_named(): void
    {
        $this->signedInAs(Role::Owner);

        $this->entry('sale', 10_000);

        LedgerEntry::create([
            'organization_id' => $this->org->id,
            'event_id' => null,
            'type' => 'adjustment',
            'amount' => -1_500,
            'currency' => 'CAD',
            // The schema insists an adjustment says why, which is correct: an
            // unexplained change to somebody's balance is the one entry that
            // always gets queried.
            'reason' => 'Chargeback fee',
            'occurred_at' => now(),
        ]);

        $body = $this->getJson('/api/organizer/payouts')->assertOk()->json();

        $titles = array_column($body['events'], 'title');

        $this->assertContains('Not tied to an event', $titles);
        $this->assertSame(8_500, $body['balance']['amount']);
    }

    public function test_a_partial_settlement_says_so(): void
    {
        $this->signedInAs(Role::Owner);

        Settlement::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'amount' => 15_000,
            'currency' => 'CAD',
            'rail' => 'interac',
            'type' => 'partial',
            'status' => 'success',
            'settled_at' => now(),
        ]);

        $body = $this->getJson('/api/organizer/payouts')->assertOk()->json();

        // Not flattened to "paid". A partial settlement is the reason a
        // balance did not reach zero, and hiding that produces a support
        // ticket rather than an understanding.
        $this->assertSame('partial', $body['settlements'][0]['type']);
        $this->assertSame('Afro Fest', $body['settlements'][0]['event']['title']);
    }

    public function test_money_is_a_permission_and_this_screen_is_refused_without_it(): void
    {
        $this->signedInAs(Role::Marketing);

        // Everywhere else a member without money sees a quieter version of the
        // screen. Here the screen *is* the money.
        $this->getJson('/api/organizer/payouts')->assertForbidden();
    }

    // --- where it goes -------------------------------------------------------

    public function test_bank_details_go_in_and_only_the_last_four_come_back(): void
    {
        $this->signedInAs(Role::Owner);

        $this->putJson('/api/organizer/payout-details', [
            'rail' => 'bank_transfer',
            'account_name' => 'Lagos Nights Inc',
            'bank_name' => 'Royal Bank',
            'account_number' => '1234567',
            'transit_number' => '00012',
            'institution_number' => '003',
        ])->assertOk()->assertJsonPath('account_last_four', '4567');

        $body = $this->getJson('/api/organizer/payouts')->assertOk()->json();

        // The whole response, checked as a string. An account number that
        // leaks through some field I did not think to assert on individually
        // is exactly the failure this is guarding.
        $this->assertStringNotContainsString('1234567', json_encode($body));
        $this->assertSame('4567', $body['destination']['account_last_four']);
        $this->assertSame('Royal Bank', $body['destination']['bank_name']);
    }

    public function test_the_number_is_encrypted_at_rest(): void
    {
        $this->signedInAs(Role::Owner);

        $this->putJson('/api/organizer/payout-details', [
            'rail' => 'bank_transfer',
            'account_name' => 'Lagos Nights Inc',
            'bank_name' => 'Royal Bank',
            'account_number' => '1234567',
        ])->assertOk();

        $raw = \DB::table('organization_payout_details')
            ->where('organization_id', $this->org->id)
            ->value('account_number');

        $this->assertNotSame('1234567', $raw);
        $this->assertStringNotContainsString('1234567', (string) $raw);
    }

    public function test_changing_the_account_clears_a_verification(): void
    {
        $this->signedInAs(Role::Owner);

        OrganizationPayoutDetail::create([
            'organization_id' => $this->org->id,
            'rail' => 'interac',
            'currency' => 'CAD',
            'interac_email' => 'money@lagosnights.test',
            'verified_at' => now(),
            'verification_method' => 'interac_test_transfer',
        ]);

        $this->putJson('/api/organizer/payout-details', [
            'rail' => 'interac',
            'interac_email' => 'somewhere-else@example.test',
        ])->assertOk()->assertJsonPath('verified_at', null);

        // A verification that survives being pointed at a different account
        // verifies nothing.
        $this->assertNull(
            OrganizationPayoutDetail::where('organization_id', $this->org->id)->value('verified_at')
        );
    }

    public function test_writing_them_is_logged(): void
    {
        $user = $this->signedInAs(Role::Owner);

        $this->putJson('/api/organizer/payout-details', [
            'rail' => 'interac',
            'interac_email' => 'money@lagosnights.test',
        ])->assertOk();

        // Encryption defends against a leaked dump. It does nothing about an
        // authorised person changing where the money goes, which is what this
        // log is for.
        $this->assertSame(1, SensitiveDataAccess::where('user_id', $user->id)
            ->where('action', 'write')
            ->count());
    }

    public function test_interac_needs_an_address_and_a_transfer_needs_an_account(): void
    {
        $this->signedInAs(Role::Owner);

        $this->putJson('/api/organizer/payout-details', ['rail' => 'interac'])
            ->assertJsonValidationErrors('interac_email');

        $this->putJson('/api/organizer/payout-details', ['rail' => 'bank_transfer'])
            ->assertJsonValidationErrors(['account_name', 'bank_name', 'account_number']);
    }

    public function test_marketing_cannot_change_where_the_money_goes(): void
    {
        $this->signedInAs(Role::Marketing);

        $this->putJson('/api/organizer/payout-details', [
            'rail' => 'interac',
            'interac_email' => 'attacker@example.test',
        ])->assertForbidden();

        $this->assertSame(0, OrganizationPayoutDetail::count());
    }

    // --- who may change where it goes ----------------------------------------

    private function verifiedInterac(): OrganizationPayoutDetail
    {
        return OrganizationPayoutDetail::create([
            'organization_id' => $this->org->id,
            'rail' => 'interac',
            'currency' => 'CAD',
            'interac_email' => 'money@lagosnights.test',
            'verified_at' => now(),
            'verification_method' => 'interac_test_transfer',
        ]);
    }

    public function test_an_owner_can_change_where_the_money_goes(): void
    {
        $this->verifiedInterac();
        $this->signedInAs(Role::Owner);

        $this->getJson('/api/organizer/payouts')
            ->assertOk()
            ->assertJsonPath('can_change_destination', true);

        $this->putJson('/api/organizer/payout-details', [
            'rail' => 'bank_transfer',
            'account_name' => 'Lagos Nights Inc',
            'bank_name' => 'Royal Bank',
            'account_number' => '1234567',
        ])->assertOk()->assertJsonPath('account_last_four', '4567');

        $this->assertSame('bank_transfer', OrganizationPayoutDetail::sole()->rail);
    }

    /**
     * Seeing the money is not choosing where it goes.
     *
     * Managers and finance both see the balance, and until now that was all it
     * took to point every future payout at an account of their own. Refused
     * even for a well-formed request, and the details on file are untouched —
     * including the verification, which a refused write must not clear.
     */
    public function test_managers_and_finance_see_the_destination_but_cannot_change_it(): void
    {
        $detail = $this->verifiedInterac();

        foreach ([Role::Manager, Role::Finance] as $role) {
            $this->signedInAs($role);

            $this->getJson('/api/organizer/payouts')
                ->assertOk()
                ->assertJsonPath('can_change_destination', false)
                // Still shown: checking the details is not the same as changing them.
                ->assertJsonPath('destination.interac_email', 'money@lagosnights.test');

            $this->putJson('/api/organizer/payout-details', [
                'rail' => 'interac',
                'interac_email' => 'attacker@example.test',
            ])
                ->assertForbidden()
                ->assertJsonPath('message', "Only the organization's owner can change where payouts go.");

            $this->putJson('/api/organizer/payout-details', [
                'rail' => 'bank_transfer',
                'account_name' => 'Somebody Else',
                'bank_name' => 'Another Bank',
                'account_number' => '99998888',
            ])->assertForbidden();
        }

        $fresh = $detail->fresh();

        $this->assertSame(1, OrganizationPayoutDetail::count());
        $this->assertSame('interac', $fresh->rail);
        $this->assertSame('money@lagosnights.test', $fresh->interac_email);
        $this->assertNull($fresh->account_last_four);
        $this->assertNotNull($fresh->verified_at);

        // Nothing written, so nothing to record as written.
        $this->assertSame(0, AuditLog::where('action', 'payout_details.updated')->count());
        $this->assertSame(0, SensitiveDataAccess::where('action', 'write')->count());
    }

    public function test_changing_where_the_money_goes_is_recorded_with_where_it_went_before(): void
    {
        $this->verifiedInterac();
        $owner = $this->signedInAs(Role::Owner);

        $this->putJson('/api/organizer/payout-details', [
            'rail' => 'bank_transfer',
            'account_name' => 'Lagos Nights Inc',
            'bank_name' => 'Royal Bank',
            'account_number' => '1234567',
        ])->assertOk();

        $entry = AuditLog::where('action', 'payout_details.updated')->sole();

        $this->assertSame($owner->id, $entry->actor_id);
        $this->assertSame($this->org->id, $entry->organization_id);
        $this->assertSame('bank_transfer', $entry->metadata['rail']);
        $this->assertSame('4567', $entry->metadata['last_four']);
        $this->assertSame(['rail' => 'interac', 'last_four' => null], $entry->metadata['from']);
        $this->assertTrue($entry->metadata['destination_changed']);
        $this->assertTrue($entry->metadata['verification_cleared']);

        // Masked in the record as everywhere else: the full number is not
        // evidence of anything the last four does not already show.
        $this->assertStringNotContainsString('1234567', json_encode($entry->metadata));
    }

    /**
     * One Interac address swapped for another is still a change on record.
     *
     * Interac has no last four, so before and after both read "interac" and
     * nothing else. Without saying so outright, pointing an unverified address
     * somewhere new wrote the same entry as opening the form and saving the
     * address already there — and after a payout went astray, the record could
     * not say when the address changed. The address itself stays out of it.
     */
    public function test_moving_to_another_interac_address_is_recorded_as_a_move(): void
    {
        $this->signedInAs(Role::Owner);

        // Each save, and the one entry it wrote. Picked out by what was new
        // rather than by time, since three saves can share a timestamp.
        $seen = [];

        $save = function (string $email) use (&$seen): array {
            $this->putJson('/api/organizer/payout-details', [
                'rail' => 'interac',
                'interac_email' => $email,
            ])->assertOk();

            $entry = AuditLog::where('action', 'payout_details.updated')
                ->whereNotIn('id', $seen)
                ->sole();

            $seen[] = $entry->id;

            return $entry->metadata;
        };

        $entries = [
            $save('money@lagosnights.test'),
            $save('money@lagosnights.test'),
            $save('attacker@example.test'),
        ];

        // The first time: nowhere before, somewhere now.
        $this->assertNull($entries[0]['from']);
        $this->assertTrue($entries[0]['destination_changed']);

        // The same address saved again goes nowhere new.
        $this->assertSame(['rail' => 'interac', 'last_four' => null], $entries[1]['from']);
        $this->assertFalse($entries[1]['destination_changed']);

        // A different address does, though nothing was verified to clear.
        $this->assertSame(['rail' => 'interac', 'last_four' => null], $entries[2]['from']);
        $this->assertTrue($entries[2]['destination_changed']);
        $this->assertFalse($entries[2]['verification_cleared']);

        foreach ($entries as $metadata) {
            $this->assertStringNotContainsString('lagosnights.test', json_encode($metadata));
            $this->assertStringNotContainsString('example.test', json_encode($metadata));
        }
    }
}
