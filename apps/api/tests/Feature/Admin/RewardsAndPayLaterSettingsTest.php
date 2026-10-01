<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Filament\Pages\PlatformSettings;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Services\Settings\PlatformSettings as Settings;
use App\Services\StaffSupport\StaffActionRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What a night earns in points, the largest friend discount, and whether
 * Klarna and Affirm are offered — set in the admin like every other platform
 * setting, by an administrator, on the record.
 */
class RewardsAndPayLaterSettingsTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    public function test_they_start_at_the_configured_defaults(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));

        Livewire::test(PlatformSettings::class)
            ->assertSuccessful()
            ->assertSee('Fiesta Points')
            ->assertSee('Friend discounts')
            ->assertSee('Pay later')
            ->assertFormSet([
                'points_per_event' => 100,
                'points_daily_cap' => 2,
                'share_max' => 20,
                'bnpl_enabled' => false,
                'bnpl_max_days_before_event' => 110,
            ]);

        $settings = app(Settings::class);
        $this->assertSame(100, $settings->pointsPerEvent());
        $this->assertSame(2, $settings->pointsDailyCap());
        $this->assertSame(2000, $settings->shareMaxBps());
        $this->assertFalse($settings->payLaterEnabled());
        $this->assertSame(110, $settings->payLaterMaxDaysBeforeEvent());
    }

    public function test_an_administrator_changes_them_and_the_trail_says_what_they_were(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));

        Livewire::test(PlatformSettings::class)
            ->fillForm([
                'points_per_event' => '150',
                'share_max' => '15',
                'bnpl_enabled' => true,
                'bnpl_max_days_before_event' => '90',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved');

        $settings = app(Settings::class);
        $this->assertSame(150, $settings->pointsPerEvent());
        $this->assertSame(2, $settings->pointsDailyCap(), 'Only what was changed.');
        $this->assertSame(1500, $settings->shareMaxBps());
        $this->assertTrue($settings->payLaterEnabled());
        $this->assertSame(90, $settings->payLaterMaxDaysBeforeEvent());

        $entry = AuditLog::where('action', 'platform_settings.changed')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame(['from' => 100, 'to' => 150], $entry->metadata['changes']['points_per_event']);
        $this->assertSame(['from' => 2000, 'to' => 1500], $entry->metadata['changes']['share_max_bps']);
        $this->assertSame(['from' => false, 'to' => true], $entry->metadata['changes']['bnpl_enabled']);
        $this->assertArrayNotHasKey('points_daily_cap', $entry->metadata['changes']);
    }

    public function test_values_out_of_range_are_not_saved(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));

        Livewire::test(PlatformSettings::class)
            ->fillForm(['share_max' => '60', 'bnpl_max_days_before_event' => '200', 'points_daily_cap' => '-1'])
            ->call('save')
            ->assertHasFormErrors(['share_max', 'bnpl_max_days_before_event', 'points_daily_cap']);

        $this->assertSame(0, PlatformSetting::count());
    }

    /**
     * Refused by the service as well as the form: past 120 days a night
     * could be cancelled after Affirm stops taking the refund back.
     */
    public function test_pay_later_is_never_offered_further_out_than_a_refund_can_follow(): void
    {
        $admin = $this->staff(PlatformRole::Admin);

        $this->expectException(StaffActionRefused::class);
        $this->expectExceptionMessage('Affirm takes a refund back for 120 days');

        app(Settings::class)->update(['bnpl_max_days_before_event' => 121], $admin);
    }

    /**
     * Nor by a deploy: what the environment says is held to the same range
     * as the admin, not honoured until somebody saves the page.
     */
    public function test_a_default_from_the_environment_is_held_to_the_same_range(): void
    {
        config([
            'payments.pay_later.max_days_before_event' => 180,
            'rewards.share.max_bps' => 9000,
            'rewards.points.daily_cap' => -3,
        ]);

        $settings = app(Settings::class);
        $this->assertSame(120, $settings->payLaterMaxDaysBeforeEvent());
        $this->assertSame(5000, $settings->shareMaxBps());
        $this->assertSame(0, $settings->pointsDailyCap());
    }

    public function test_finance_reads_them_and_cannot_change_them(): void
    {
        $this->actAs($this->staff(PlatformRole::Finance));

        Livewire::test(PlatformSettings::class)
            ->assertFormFieldIsDisabled('points_per_event')
            ->assertFormFieldIsDisabled('share_max')
            ->assertFormFieldIsDisabled('bnpl_enabled');
    }
}
