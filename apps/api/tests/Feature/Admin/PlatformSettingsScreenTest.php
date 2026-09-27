<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Filament\Pages\PlatformSettings;
use App\Filament\Resources\TaxRates\Pages\CreateTaxRate;
use App\Filament\Resources\TaxRates\Pages\EditTaxRate;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\TaxRate;
use App\Services\Settings\PlatformSettings as Settings;
use App\Services\StaffSupport\TaxRateChanges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The platform settings page, and the tax-rate screens that go with it.
 *
 * Administrators change settings; finance reads them; support does not see
 * them. A rate that has priced a sale is superseded from the list rather than
 * edited on its form.
 */
class PlatformSettingsScreenTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    public function test_an_administrator_changes_the_settings_and_the_trail_says_what_they_were(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));

        Livewire::test(PlatformSettings::class)
            ->assertSuccessful()
            ->assertFormSet([
                'service_charge_cad' => 8,
                'seller_of_record' => 'organizer',
                'tax_on_service_charge' => false,
                'qst_enabled' => false,
                'qst_rate' => '9.975',
            ])
            ->fillForm([
                'service_charge_cad' => '8.5',
                'seller_of_record' => 'platform',
                'qst_enabled' => true,
                'gst_hst_number' => '123456789 RT0001',
                'legal_name' => 'Fiesta Tickets Inc.',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved');

        $settings = app(Settings::class);
        $this->assertSame(850, $settings->serviceChargeBps('CAD'));
        $this->assertSame(800, $settings->serviceChargeBps('NGN'), 'Only the currency that was changed.');
        $this->assertSame('platform', $settings->sellerOfRecord()->value);
        $this->assertSame(99750, $settings->qstPpm());
        $this->assertSame('Fiesta Tickets Inc.', $settings->legalName());

        $entry = AuditLog::where('action', 'platform_settings.changed')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame(['from' => 800, 'to' => 850], $entry->metadata['changes']['service_charge_bps_cad']);
        $this->assertSame(['from' => 'organizer', 'to' => 'platform'], $entry->metadata['changes']['seller_of_record']);
        $this->assertSame(['from' => null, 'to' => '123456789 RT0001'], $entry->metadata['changes']['gst_hst_number']);
        $this->assertArrayNotHasKey('service_charge_bps_ngn', $entry->metadata['changes']);
    }

    public function test_a_rate_out_of_range_is_not_saved(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));

        Livewire::test(PlatformSettings::class)
            ->fillForm(['service_charge_cad' => '140'])
            ->call('save')
            ->assertHasFormErrors(['service_charge_cad']);

        $this->assertSame(0, PlatformSetting::count());
    }

    public function test_finance_reads_the_settings_and_cannot_save_them(): void
    {
        $this->actAs($this->staff(PlatformRole::Finance));

        $this->get(PlatformSettings::getUrl())->assertOk()->assertSee('Seller of record');

        Livewire::test(PlatformSettings::class)
            ->assertFormFieldIsDisabled('service_charge_cad')
            ->assertFormFieldIsDisabled('seller_of_record')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, PlatformSetting::count());
    }

    public function test_support_does_not_see_the_settings(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));

        $this->assertFalse(PlatformSettings::canAccess());
        $this->get(PlatformSettings::getUrl())->assertForbidden();
    }

    // --- tax rates ------------------------------------------------------------

    public function test_a_rate_in_force_offers_only_its_name_to_edit(): void
    {
        $this->actAs($this->staff(PlatformRole::Finance));
        $hst = $this->rate(['subdivision' => 'ON', 'name' => 'HST', 'rate_bps' => 1300, 'effective_from' => now()->subYear()]);

        Livewire::test(EditTaxRate::class, ['record' => $hst->getKey()])
            ->assertFormFieldIsEnabled('name')
            ->assertFormFieldIsDisabled('rate_bps')
            ->assertFormFieldIsDisabled('inclusive')
            ->assertFormFieldIsDisabled('country')
            ->assertFormFieldIsDisabled('subdivision')
            ->assertFormFieldIsDisabled('effective_from')
            ->assertFormFieldIsDisabled('effective_to')
            ->assertSee('Supersede')
            ->fillForm(['name' => 'HST (Ontario)'])
            ->call('save')
            ->assertHasNoFormErrors();

        $hst->refresh();
        $this->assertSame('HST (Ontario)', $hst->name);
        $this->assertSame(1300, $hst->rate_bps);
    }

    public function test_a_scheduled_rate_can_still_be_corrected_in_full(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));
        $coming = $this->rate(['subdivision' => 'NS', 'name' => 'HST', 'rate_bps' => 1400, 'effective_from' => now()->addMonth()]);

        Livewire::test(EditTaxRate::class, ['record' => $coming->getKey()])
            ->assertFormFieldIsEnabled('rate_bps')
            ->fillForm(['rate_bps' => '13.5'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1350, $coming->fresh()->rate_bps);
    }

    public function test_a_second_rate_for_a_place_with_one_in_force_is_refused(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));
        $this->rate(['subdivision' => 'ON', 'name' => 'HST', 'rate_bps' => 1300, 'effective_from' => now()->subYear()]);

        Livewire::test(CreateTaxRate::class)
            ->fillForm([
                'country' => 'CA',
                'subdivision' => 'on',
                'name' => 'HST',
                'rate_bps' => '15',
                'effective_from' => now()->addWeek()->toDateString(),
            ])
            ->call('create')
            ->assertNotified('That place already has a rate on those dates');

        $this->assertSame(1, TaxRate::count());

        // A place with none is fine.
        Livewire::test(CreateTaxRate::class)
            ->fillForm([
                'country' => 'CA',
                'subdivision' => 'QC',
                'name' => 'GST',
                'rate_bps' => '5',
                'effective_from' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, TaxRate::count());
    }

    public function test_a_new_rate_with_an_end_date_is_refused_on_days_another_covers(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));
        $hst = $this->rate(['subdivision' => 'ON', 'name' => 'HST', 'rate_bps' => 1300, 'effective_from' => now()->subYear()]);

        // An end date used to wave it through: only open-ended rates were
        // checked, and checkout would then have had two for Ontario.
        Livewire::test(CreateTaxRate::class)
            ->fillForm([
                'country' => 'CA',
                'subdivision' => 'ON',
                'name' => 'HST',
                'rate_bps' => '15',
                'effective_from' => now()->toDateString(),
                'effective_to' => now()->addYear()->toDateString(),
            ])
            ->call('create')
            ->assertNotified('That place already has a rate on those dates');

        $this->assertSame(1, TaxRate::count());
        $this->assertSame($hst->id, TaxRate::resolve('CA', 'ON')->id);

        // A place whose rate closes takes a new one from that day.
        $this->rate(['subdivision' => 'NS', 'name' => 'HST', 'rate_bps' => 1500,
            'effective_from' => now()->subYear(), 'effective_to' => now()->addMonth()]);

        Livewire::test(CreateTaxRate::class)
            ->fillForm([
                'country' => 'CA',
                'subdivision' => 'ns',
                'name' => 'HST',
                'rate_bps' => '14',
                'effective_from' => now()->addMonth()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1400, TaxRate::resolve('CA', 'NS', now()->addMonth()->toDateString())->rate_bps);
    }

    public function test_a_new_rate_does_not_start_in_the_past(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));

        Livewire::test(CreateTaxRate::class)
            ->fillForm([
                'country' => 'CA',
                'subdivision' => 'QC',
                'name' => 'GST',
                'rate_bps' => '5',
                'effective_from' => now()->subDay()->toDateString(),
            ])
            ->call('create')
            ->assertHasFormErrors(['effective_from']);

        $this->assertSame(0, TaxRate::count());
    }

    public function test_a_scheduled_replacement_cannot_be_moved_into_the_past(): void
    {
        $finance = $this->actAs($this->staff(PlatformRole::Finance));
        $hst = $this->rate(['subdivision' => 'ON', 'name' => 'HST', 'rate_bps' => 1300, 'effective_from' => now()->subYear()]);
        $next = app(TaxRateChanges::class)->supersede($hst, $finance, 1400, today()->addMonth());

        // Backdated past its own supersession, it would have shared two
        // months with the rate it replaces, and then neither could be undone.
        Livewire::test(EditTaxRate::class, ['record' => $next->getKey()])
            ->assertFormFieldIsEnabled('effective_from')
            ->fillForm(['effective_from' => today()->subMonths(2)->toDateString()])
            ->call('save')
            ->assertHasFormErrors(['effective_from']);

        $this->assertSame(today()->addMonth()->toDateString(), $next->fresh()->effective_from->toDateString());
        $this->assertSame(today()->addMonth()->toDateString(), $hst->fresh()->effective_to->toDateString());
        $this->assertSame($hst->id, TaxRate::resolve('CA', 'ON')->id);
    }

    public function test_moving_a_replacement_moves_the_close_of_the_rate_it_replaces(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));
        $this->rate(['name' => 'GST', 'rate_bps' => 500, 'effective_from' => now()->subYear()]);
        $hst = $this->rate(['subdivision' => 'ON', 'name' => 'HST', 'rate_bps' => 1300, 'effective_from' => now()->subYear()]);
        $next = app(TaxRateChanges::class)->supersede($hst, $admin, 1400, today()->addMonth());

        // Later: the old rate runs on until the new one starts. Left alone,
        // the month between would have fallen back to the federal 5%.
        Livewire::test(EditTaxRate::class, ['record' => $next->getKey()])
            ->assertFormFieldIsDisabled('country')
            ->assertFormFieldIsDisabled('subdivision')
            ->fillForm(['effective_from' => today()->addMonths(2)->toDateString()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(today()->addMonths(2)->toDateString(), $hst->fresh()->effective_to->toDateString());
        $this->assertSame(1300, TaxRate::resolve('CA', 'ON', today()->addMonth()->addWeek()->toDateString())->rate_bps);
        $this->assertSame(1400, TaxRate::resolve('CA', 'ON', today()->addMonths(2)->toDateString())->rate_bps);

        // Earlier: the old rate closes sooner, rather than the two overlapping.
        Livewire::test(EditTaxRate::class, ['record' => $next->getKey()])
            ->fillForm(['effective_from' => today()->addWeek()->toDateString()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(today()->addWeek()->toDateString(), $hst->fresh()->effective_to->toDateString());
        $this->assertSame(1300, TaxRate::resolve('CA', 'ON')->rate_bps);
        $this->assertSame(1400, TaxRate::resolve('CA', 'ON', today()->addWeek()->toDateString())->rate_bps);

        // Both halves of each move are on the trail.
        $closes = AuditLog::where('action', 'tax_rate.edited')->where('subject_id', $hst->id)->get()
            ->map(fn (AuditLog $entry) => array_map(
                fn (string $date) => substr($date, 0, 10),
                $entry->metadata['changes']['effective_to'],
            ));
        $this->assertCount(2, $closes);
        $this->assertContains(
            ['from' => today()->addMonths(2)->toDateString(), 'to' => today()->addWeek()->toDateString()],
            $closes->all(),
        );
        $this->assertSame(2, AuditLog::where('action', 'tax_rate.edited')->where('subject_id', $next->id)->count());

        // A replacement starts after the rate it replaces did.
        $later = app(TaxRateChanges::class)->supersede($next->fresh(), $admin, 1500, today()->addMonths(3));

        Livewire::test(EditTaxRate::class, ['record' => $next->getKey()])
            ->assertFormFieldIsDisabled('effective_to');

        Livewire::test(EditTaxRate::class, ['record' => $later->getKey()])
            ->fillForm(['effective_from' => today()->addWeek()->toDateString()])
            ->call('save')
            ->assertNotified('A replacement starts after the rate it replaces');

        $this->assertSame(today()->addMonths(3)->toDateString(), $later->fresh()->effective_from->toDateString());
    }

    public function test_a_scheduled_rate_is_not_moved_onto_days_another_rate_covers(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));
        $this->rate(['subdivision' => 'ON', 'name' => 'HST', 'rate_bps' => 1300, 'effective_from' => now()->subYear()]);
        $closing = $this->rate(['subdivision' => 'NS', 'name' => 'HST', 'rate_bps' => 1500,
            'effective_from' => now()->subYear(), 'effective_to' => now()->addMonth()]);
        $coming = $this->rate(['subdivision' => 'NS', 'name' => 'HST', 'rate_bps' => 1400, 'effective_from' => now()->addMonths(2)]);

        // Not a replacement (a month apart), so nothing moves with it; an
        // earlier start would share days with the rate still in force.
        Livewire::test(EditTaxRate::class, ['record' => $coming->getKey()])
            ->fillForm(['effective_from' => now()->addWeek()->toDateString()])
            ->call('save')
            ->assertNotified('That place already has a rate on those dates');

        // Nor into another province that has one.
        Livewire::test(EditTaxRate::class, ['record' => $coming->getKey()])
            ->fillForm(['subdivision' => 'ON'])
            ->call('save')
            ->assertNotified('That place already has a rate on those dates');

        $coming->refresh();
        $this->assertSame('NS', $coming->subdivision);
        $this->assertSame(now()->addMonths(2)->toDateString(), $coming->effective_from->toDateString());
        $this->assertSame(now()->addMonth()->toDateString(), $closing->fresh()->effective_to->toDateString());
    }

    private function rate(array $attributes): TaxRate
    {
        return TaxRate::create([
            'country' => 'CA',
            'default_currency' => 'CAD',
            'inclusive' => false,
            ...$attributes,
        ]);
    }
}
