<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Filament\Resources\DataRequests\DataRequestResource;
use App\Filament\Resources\DataRequests\Pages\ListDataRequests;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\OrganizationIdentityDocuments\OrganizationIdentityDocumentResource;
use App\Filament\Resources\OrganizationIdentityDocuments\Pages\ListOrganizationIdentityDocuments;
use App\Filament\Resources\PayoutDetails\Pages\ListPayoutDetails;
use App\Filament\Resources\PayoutDetails\PayoutDetailResource;
use App\Filament\Resources\PayoutRequests\Pages\ListPayoutRequests;
use App\Filament\Resources\PayoutRequests\PayoutRequestResource;
use App\Filament\Resources\Settlements\Pages\ListSettlements;
use App\Filament\Resources\Settlements\SettlementResource;
use App\Filament\Resources\TaxRates\Pages\EditTaxRate;
use App\Filament\Resources\TaxRates\Pages\ListTaxRates;
use App\Filament\Resources\TaxRates\TaxRateResource;
use App\Models\AuditLog;
use App\Models\DataRequest;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationIdentityDocument;
use App\Models\OrganizationPayoutDetail;
use App\Models\PayoutRequest;
use App\Models\Settlement;
use App\Models\TaxRate;
use App\Services\StaffSupport\StaffActionRefused;
use App\Services\StaffSupport\TaxRateChanges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The money and compliance lists brought to the same standard as the support
 * screens: search, sortable columns, filters that mean something for each,
 * pages of a sensible size, money per currency — and the role that may open
 * each one, and nobody else.
 */
class MoneyAndComplianceListsTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    // --- who may open what ----------------------------------------------------

    public function test_each_list_opens_for_the_roles_it_is_for(): void
    {
        $expected = [
            DataRequestResource::class => [PlatformRole::Admin, PlatformRole::Finance, PlatformRole::Support],
            DisputeResource::class => [PlatformRole::Admin, PlatformRole::Finance],
            OrganizationIdentityDocumentResource::class => [PlatformRole::Admin, PlatformRole::Support],
            PayoutDetailResource::class => [PlatformRole::Admin, PlatformRole::Finance],
            PayoutRequestResource::class => [PlatformRole::Admin, PlatformRole::Finance],
            SettlementResource::class => [PlatformRole::Admin, PlatformRole::Finance],
        ];

        foreach (PlatformRole::cases() as $role) {
            $this->actAs($this->staff($role));

            foreach ($expected as $resource => $roles) {
                $this->assertSame(
                    in_array($role, $roles, true),
                    $resource::canViewAny(),
                    class_basename($resource).' for '.$role->value,
                );
            }
        }
    }

    // --- privacy requests -----------------------------------------------------

    public function test_privacy_requests_are_searched_filtered_and_late_ones_found(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));

        $late = DataRequest::create(['kind' => 'erasure', 'email' => 'late@example.com', 'status' => 'verified',
            'verified_at' => now()->subDays(40), 'due_at' => now()->subDays(10)]);
        $done = DataRequest::create(['kind' => 'export', 'email' => 'done@example.com', 'status' => 'completed',
            'verified_at' => now()->subDay(), 'due_at' => now()->addDays(29), 'completed_at' => now()->subDay()]);
        $old = DataRequest::create(['kind' => 'export', 'email' => 'old@example.com', 'status' => 'expired']);
        $old->forceFill(['created_at' => now()->subMonths(6)])->saveQuietly();

        Livewire::test(ListDataRequests::class)
            ->assertCanSeeTableRecords([$late, $done, $old])
            ->searchTable('late@')
            ->assertCanSeeTableRecords([$late])
            ->assertCanNotSeeTableRecords([$done, $old])
            ->searchTable('')
            ->filterTable('kind', 'erasure')
            ->assertCanSeeTableRecords([$late])
            ->assertCanNotSeeTableRecords([$done])
            ->resetTableFilters()
            ->filterTable('status', ['completed'])
            ->assertCanSeeTableRecords([$done])
            ->assertCanNotSeeTableRecords([$late, $old])
            ->resetTableFilters()
            ->filterTable('late', true)
            ->assertCanSeeTableRecords([$late])
            ->assertCanNotSeeTableRecords([$done, $old])
            ->resetTableFilters()
            ->filterTable('asked', ['from' => now()->subYear()->toDateString(), 'until' => now()->subMonths(3)->toDateString()])
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$late, $done])
            ->resetTableFilters()
            ->sortTable('due_at', 'desc')
            ->assertSuccessful()
            ->sortTable('email')
            ->assertSuccessful();
    }

    // --- chargebacks ----------------------------------------------------------

    public function test_chargebacks_are_searched_filtered_and_totalled_per_currency(): void
    {
        $this->actAs($this->staff(PlatformRole::Finance));

        $toronto = $this->organization('Toronto Sound');
        $lagos = $this->organization('Eko Live');
        $cadEvent = $this->event($toronto);
        $ngnEvent = $this->event($lagos, ['currency' => 'NGN']);
        $cadOrder = $this->paidOrder($cadEvent, $this->ticketType($cadEvent), 1);
        $ngnOrder = $this->paidOrder($ngnEvent, $this->ticketType($ngnEvent, ['price_amount' => 500000]), 1,
            ['buyer_email' => 'tunde@example.com', 'buyer_name' => 'Tunde Bello']);

        $urgent = $this->dispute($cadOrder, ['amount' => 5650, 'evidence_due_at' => now()->addDays(2)]);
        $later = $this->dispute($ngnOrder, ['amount' => 565000, 'evidence_due_at' => now()->addDays(20)]);
        $lost = $this->dispute($cadOrder, ['amount' => 1000, 'status' => 'lost', 'closed_at' => now(),
            'opened_at' => now()->subMonths(4), 'gateway_reference' => 'dp_old']);

        Livewire::test(ListDisputes::class)
            ->assertCanSeeTableRecords([$urgent, $later, $lost])
            ->assertSee('CA$66.50 · ₦5,650.00')
            ->searchTable($ngnOrder->reference)
            ->assertCanSeeTableRecords([$later])
            ->assertCanNotSeeTableRecords([$urgent, $lost])
            ->searchTable('tunde@example.com')
            ->assertCanSeeTableRecords([$later])
            ->assertCanNotSeeTableRecords([$urgent])
            ->searchTable('Toronto Sound')
            ->assertCanSeeTableRecords([$urgent, $lost])
            ->assertCanNotSeeTableRecords([$later])
            ->searchTable('')
            ->filterTable('status', ['open'])
            ->assertCanSeeTableRecords([$urgent, $later])
            ->assertCanNotSeeTableRecords([$lost])
            ->resetTableFilters()
            ->filterTable('currency', 'NGN')
            ->assertCanSeeTableRecords([$later])
            ->assertCanNotSeeTableRecords([$urgent, $lost])
            ->resetTableFilters()
            ->filterTable('organization', $toronto->id)
            ->assertCanSeeTableRecords([$urgent, $lost])
            ->assertCanNotSeeTableRecords([$later])
            ->resetTableFilters()
            ->filterTable('due_soon', true)
            ->assertCanSeeTableRecords([$urgent])
            ->assertCanNotSeeTableRecords([$later, $lost])
            ->resetTableFilters()
            ->filterTable('raised', ['from' => now()->subMonths(5)->toDateString(), 'until' => now()->subMonths(3)->toDateString()])
            ->assertCanSeeTableRecords([$lost])
            ->assertCanNotSeeTableRecords([$urgent, $later])
            ->resetTableFilters()
            ->sortTable('amount', 'desc')
            ->assertSuccessful()
            ->assertTableActionHasUrl('openOrder', OrderResource::getUrl('view', ['record' => $cadOrder->id]), $urgent);
    }

    // --- identity review ------------------------------------------------------

    public function test_the_identity_queue_shows_pending_first_and_never_what_is_encrypted(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));

        $pending = $this->identityDocument($this->organization('Toronto Sound'), ['legal_last_name' => 'Okonkwo-Secret']);
        $approved = $this->identityDocument($this->organization('Eko Live'), ['review_status' => 'approved',
            'reviewed_at' => now(), 'document_type' => 'national_id']);

        Livewire::test(ListOrganizationIdentityDocuments::class)
            // The queue is what waits.
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$approved])
            ->assertDontSee('Okonkwo-Secret')
            ->assertDontSee('A1234567')
            ->resetTableFilters()
            ->filterTable('review_status', null)
            ->assertCanSeeTableRecords([$pending, $approved])
            ->filterTable('document_type', 'national_id')
            ->assertCanSeeTableRecords([$approved])
            ->assertCanNotSeeTableRecords([$pending])
            ->resetTableFilters()
            ->filterTable('review_status', null)
            ->searchTable('Eko')
            ->assertCanSeeTableRecords([$approved])
            ->assertCanNotSeeTableRecords([$pending])
            ->searchTable('')
            ->sortTable('created_at', 'desc')
            ->assertSuccessful();
    }

    // --- payout destinations --------------------------------------------------

    public function test_payout_destinations_show_the_last_four_and_filter_by_currency_and_rail(): void
    {
        $this->actAs($this->staff(PlatformRole::Finance));

        $bank = OrganizationPayoutDetail::create([
            'organization_id' => $this->organization('Toronto Sound')->id,
            'rail' => 'bank_transfer',
            'currency' => 'CAD',
            'bank_name' => 'Royal Bank',
            'account_name' => 'Toronto Sound Inc',
            'account_number' => '0012345678',
            'transit_number' => '00012',
            'institution_number' => '003',
        ]);
        $naira = OrganizationPayoutDetail::create([
            'organization_id' => $this->organization('Eko Live')->id,
            'rail' => 'bank_transfer',
            'currency' => 'NGN',
            'bank_name' => 'GTBank',
            'account_name' => 'Eko Live Ltd',
            'account_number' => '0123456789',
            'bank_code' => '058',
        ]);
        $verified = OrganizationPayoutDetail::create([
            'organization_id' => $this->organization('Owambe')->id,
            'rail' => 'interac',
            'currency' => 'CAD',
            'interac_email' => 'money@owambe.test',
            'verified_at' => now(),
            'verification_method' => 'interac_test_transfer',
        ]);

        Livewire::test(ListPayoutDetails::class)
            // Unverified by default: that is the queue.
            ->assertCanSeeTableRecords([$bank, $naira])
            ->assertCanNotSeeTableRecords([$verified])
            ->assertSee('Bank account ending 5678')
            ->assertDontSee('0012345678')
            ->assertDontSee('Toronto Sound Inc')
            ->filterTable('currency', 'NGN')
            ->assertCanSeeTableRecords([$naira])
            ->assertCanNotSeeTableRecords([$bank])
            ->resetTableFilters()
            ->filterTable('verified', null)
            ->filterTable('rail', 'interac')
            ->assertCanSeeTableRecords([$verified])
            ->assertCanNotSeeTableRecords([$bank, $naira])
            ->resetTableFilters()
            ->searchTable('Eko')
            ->assertCanSeeTableRecords([$naira])
            ->assertCanNotSeeTableRecords([$bank])
            ->searchTable('')
            ->sortTable('updated_at')
            ->assertSuccessful();

        // Listing and filtering decrypted nothing, so none of it was logged as a reveal.
        $this->assertSame(0, AuditLog::where('action', 'payout_details.revealed')->count());
    }

    // --- payout requests ------------------------------------------------------

    public function test_payout_requests_are_searched_filtered_and_totalled_per_currency(): void
    {
        $finance = $this->actAs($this->staff(PlatformRole::Finance));

        $toronto = $this->organization('Toronto Sound');
        $lagos = $this->organization('Eko Live');
        OrganizationPayoutDetail::create([
            'organization_id' => $toronto->id,
            'rail' => 'interac',
            'currency' => 'CAD',
            'interac_email' => 'money@toronto.test',
            'verified_at' => now(),
            'verification_method' => 'interac_test_transfer',
        ]);

        $cad = $this->payoutRequest($toronto, 'CAD', 12500, ['note' => 'For the Afro Fest weekend']);
        $ngn = $this->payoutRequest($lagos, 'NGN', 5000000);
        $rejected = $this->payoutRequest($lagos, 'CAD', 900, ['status' => 'rejected',
            'decision_note' => 'Details did not match.', 'decided_by' => $finance->id, 'decided_at' => now()]);

        Livewire::test(ListPayoutRequests::class)
            // Waiting by default.
            ->assertCanSeeTableRecords([$cad, $ngn])
            ->assertCanNotSeeTableRecords([$rejected])
            ->assertSee('CA$125.00 · ₦50,000.00')
            ->searchTable('afro fest')
            ->assertCanSeeTableRecords([$cad])
            ->assertCanNotSeeTableRecords([$ngn])
            ->searchTable('Eko')
            ->assertCanSeeTableRecords([$ngn])
            ->assertCanNotSeeTableRecords([$cad])
            ->searchTable('')
            ->filterTable('currency', 'NGN')
            ->assertCanSeeTableRecords([$ngn])
            ->assertCanNotSeeTableRecords([$cad])
            ->resetTableFilters()
            ->filterTable('destination_verified', true)
            ->assertCanSeeTableRecords([$cad])
            ->assertCanNotSeeTableRecords([$ngn])
            ->resetTableFilters()
            ->filterTable('status', 'rejected')
            ->assertCanSeeTableRecords([$rejected])
            ->assertCanNotSeeTableRecords([$cad, $ngn])
            ->assertTableActionHidden('pay', $rejected)
            ->resetTableFilters()
            ->filterTable('organization', $lagos->id)
            ->assertCanSeeTableRecords([$ngn])
            ->assertCanNotSeeTableRecords([$cad])
            ->resetTableFilters()
            ->sortTable('amount', 'desc')
            ->assertSuccessful()
            ->assertTableActionVisible('pay', $cad);
    }

    // --- settlements ----------------------------------------------------------

    public function test_settlements_are_searched_filtered_and_totalled_per_currency(): void
    {
        $finance = $this->actAs($this->staff(PlatformRole::Finance, ['name' => 'Funmi Finance']));

        $toronto = $this->organization('Toronto Sound');
        $lagos = $this->organization('Eko Live');

        $cad = $this->settlement($toronto, 15000, 'CAD', ['settled_by' => $finance->id, 'note' => 'Weekend payout']);
        $ngn = $this->settlement($lagos, 2500000, 'NGN', ['rail' => 'bank_transfer', 'type' => 'full']);
        $old = $this->settlement($toronto, 5000, 'CAD', ['created_at' => now()->subMonths(5)]);

        Livewire::test(ListSettlements::class)
            ->assertCanSeeTableRecords([$cad, $ngn, $old])
            ->assertSee('CA$200.00 · ₦25,000.00')
            ->assertSee('CA$150.00')
            ->searchTable('Eko')
            ->assertCanSeeTableRecords([$ngn])
            ->assertCanNotSeeTableRecords([$cad, $old])
            ->searchTable('Funmi')
            ->assertCanSeeTableRecords([$cad])
            ->assertCanNotSeeTableRecords([$ngn, $old])
            ->searchTable('')
            ->filterTable('currency', 'NGN')
            ->assertCanSeeTableRecords([$ngn])
            ->assertCanNotSeeTableRecords([$cad])
            ->resetTableFilters()
            ->filterTable('type', ['full'])
            ->assertCanSeeTableRecords([$ngn])
            ->assertCanNotSeeTableRecords([$cad])
            ->resetTableFilters()
            ->filterTable('rail', 'bank_transfer')
            ->assertCanSeeTableRecords([$ngn])
            ->assertCanNotSeeTableRecords([$cad])
            ->resetTableFilters()
            ->filterTable('organization', $toronto->id)
            ->assertCanSeeTableRecords([$cad, $old])
            ->assertCanNotSeeTableRecords([$ngn])
            ->resetTableFilters()
            ->filterTable('recorded', ['from' => now()->subMonths(6)->toDateString(), 'until' => now()->subMonths(4)->toDateString()])
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$cad, $ngn])
            ->resetTableFilters()
            ->sortTable('amount', 'desc')
            ->assertSuccessful();
    }

    // --- tax rates ------------------------------------------------------------

    public function test_the_tax_rate_list_shows_what_is_in_force_by_default(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));

        $hst = $this->taxRate(['subdivision' => 'ON', 'name' => 'HST', 'rate_bps' => 1300, 'effective_from' => now()->subYear()]);
        $gst = $this->taxRate(['name' => 'GST', 'rate_bps' => 500, 'effective_from' => now()->subYear()]);
        $retired = $this->taxRate(['subdivision' => 'NS', 'name' => 'HST', 'rate_bps' => 1500,
            'effective_from' => now()->subYears(3), 'effective_to' => now()->subYear()]);
        $coming = $this->taxRate(['subdivision' => 'NS', 'name' => 'HST', 'rate_bps' => 1400,
            'effective_from' => now()->addMonth()]);

        Livewire::test(ListTaxRates::class)
            ->assertCanSeeTableRecords([$hst, $gst])
            ->assertCanNotSeeTableRecords([$retired, $coming])
            ->filterTable('state', 'superseded')
            ->assertCanSeeTableRecords([$retired])
            ->assertCanNotSeeTableRecords([$hst, $coming])
            ->filterTable('state', 'scheduled')
            ->assertCanSeeTableRecords([$coming])
            ->assertCanNotSeeTableRecords([$hst, $retired])
            ->filterTable('state', null)
            ->filterTable('subdivision', 'country')
            ->assertCanSeeTableRecords([$gst])
            ->assertCanNotSeeTableRecords([$hst])
            ->resetTableFilters()
            ->searchTable('GST')
            ->assertCanSeeTableRecords([$gst])
            ->assertCanNotSeeTableRecords([$hst])
            ->searchTable('')
            ->sortTable('rate_bps', 'desc')
            ->assertSuccessful();
    }

    public function test_finance_supersedes_a_rate_and_the_trail_says_so(): void
    {
        $finance = $this->actAs($this->staff(PlatformRole::Finance));
        $hst = $this->taxRate(['subdivision' => 'ON', 'name' => 'HST', 'rate_bps' => 1300, 'effective_from' => now()->subYear()]);
        $from = now()->addWeek()->toDateString();

        Livewire::test(ListTaxRates::class)
            ->callTableAction('supersede', $hst, data: ['rate_bps' => '13.5', 'effective_from' => $from])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Rate superseded');

        $this->assertSame($from, $hst->fresh()->effective_to->toDateString());

        $replacement = TaxRate::where('rate_bps', 1350)->sole();
        $this->assertSame('ON', $replacement->subdivision);
        $this->assertSame($from, $replacement->effective_from->toDateString());
        $this->assertNull($replacement->effective_to);

        $entry = AuditLog::where('action', 'tax_rate.superseded')->sole();
        $this->assertSame($finance->id, $entry->actor_id);
        $this->assertSame($replacement->id, $entry->subject_id);
        $this->assertSame(1300, $entry->metadata['from_bps']);
        $this->assertSame(1350, $entry->metadata['to_bps']);
    }

    public function test_support_sees_rates_but_cannot_change_them(): void
    {
        $support = $this->actAs($this->staff(PlatformRole::Support));
        $hst = $this->taxRate(['subdivision' => 'ON', 'name' => 'HST', 'rate_bps' => 1300, 'effective_from' => now()->subYear()]);

        Livewire::test(ListTaxRates::class)
            ->assertCanSeeTableRecords([$hst])
            ->assertActionHidden('create')
            ->assertTableActionHidden('supersede', $hst)
            ->assertTableActionHidden('edit', $hst);

        $this->assertFalse(TaxRateResource::canEdit($hst));

        $this->expectException(StaffActionRefused::class);
        app(TaxRateChanges::class)->supersede($hst, $support, 1500, now()->addDay());
    }

    public function test_a_rate_is_never_superseded_into_the_past(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));
        $hst = $this->taxRate(['subdivision' => 'ON', 'name' => 'HST', 'rate_bps' => 1300, 'effective_from' => now()->subYear()]);

        try {
            app(TaxRateChanges::class)->supersede($hst, $admin, 1500, now()->subDay());
            $this->fail('A rate was superseded from yesterday.');
        } catch (StaffActionRefused) {
            $this->assertNull($hst->fresh()->effective_to);
        }

        $this->assertSame(1, TaxRate::count());
        $this->assertSame(0, AuditLog::where('action', 'tax_rate.superseded')->count());
    }

    public function test_editing_a_rate_is_recorded_with_what_changed(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));
        $rate = $this->taxRate(['name' => 'VAT', 'country' => 'NG', 'default_currency' => 'NGN', 'rate_bps' => 750, 'effective_from' => now()->subYear()]);

        Livewire::test(ListTaxRates::class)
            ->assertActionVisible('create')
            ->assertTableActionVisible('edit', $rate);

        Livewire::test(EditTaxRate::class, ['record' => $rate->getKey()])
            // A rate orders point at is superseded, never deleted.
            ->assertActionDoesNotExist('delete')
            ->fillForm(['name' => 'Value Added Tax'])
            ->call('save')
            ->assertHasNoFormErrors();

        $entry = AuditLog::where('action', 'tax_rate.edited')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame(['from' => 'VAT', 'to' => 'Value Added Tax'], $entry->metadata['changes']['name']);
    }

    // --- fixtures -------------------------------------------------------------

    private function dispute(Order $order, array $attributes = []): Dispute
    {
        return Dispute::create([
            'order_id' => $order->id,
            'organization_id' => $order->organization_id,
            'event_id' => $order->event_id,
            'gateway' => $order->gateway ?? 'stripe',
            'gateway_reference' => 'dp_'.substr(md5((string) microtime(true).random_int(0, PHP_INT_MAX)), 0, 10),
            'amount' => $order->total_amount,
            'currency' => $order->currency,
            'status' => 'open',
            'opened_at' => now(),
            ...$attributes,
        ]);
    }

    private function identityDocument(Organization $organization, array $attributes = []): OrganizationIdentityDocument
    {
        return OrganizationIdentityDocument::create([
            'organization_id' => $organization->id,
            'document_type' => 'passport',
            'legal_first_name' => 'Adaeze',
            'legal_last_name' => 'Okafor',
            'date_of_birth' => '1990-04-01',
            'document_number' => 'A1234567',
            'review_status' => 'pending',
            ...$attributes,
        ]);
    }

    private function payoutRequest(Organization $organization, string $currency, int $amount, array $attributes = []): PayoutRequest
    {
        return PayoutRequest::create([
            'organization_id' => $organization->id,
            'currency' => $currency,
            'amount' => $amount,
            'balance_at_request' => $amount,
            'status' => 'pending',
            ...$attributes,
        ]);
    }

    private function settlement(Organization $organization, int $amount, string $currency, array $attributes = []): Settlement
    {
        return Settlement::create([
            'organization_id' => $organization->id,
            'amount' => $amount,
            'currency' => $currency,
            'rail' => 'interac',
            'type' => 'partial',
            'status' => 'success',
            'settled_at' => now(),
            ...$attributes,
        ]);
    }

    private function taxRate(array $attributes): TaxRate
    {
        return TaxRate::create([
            'country' => 'CA',
            'default_currency' => 'CAD',
            'inclusive' => false,
            ...$attributes,
        ]);
    }
}
