<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Filament\Pages\PlatformSettings;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Services\Settings\PlatformSettings as Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * When "Almost sold out" shows, and when a count is named — set in the admin
 * like every other platform setting, by an administrator, on the record.
 */
class AvailabilitySettingsTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    public function test_it_starts_at_the_configured_defaults(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));

        Livewire::test(PlatformSettings::class)
            ->assertSuccessful()
            ->assertSee('Almost sold out')
            ->assertFormSet([
                'almost_sold_out_percent' => 10,
                'almost_sold_out_floor' => 5,
                'only_left_under' => 10,
            ]);

        $this->assertSame(['percent' => 10, 'floor' => 5, 'exact_under' => 10], app(Settings::class)->scarcity());
    }

    public function test_an_administrator_changes_it_and_the_trail_says_what_it_was(): void
    {
        $admin = $this->actAs($this->staff(PlatformRole::Admin));

        Livewire::test(PlatformSettings::class)
            ->fillForm([
                'almost_sold_out_percent' => '15',
                'only_left_under' => '0',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved');

        $this->assertSame(['percent' => 15, 'floor' => 5, 'exact_under' => 0], app(Settings::class)->scarcity());

        $entry = AuditLog::where('action', 'platform_settings.changed')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame(['from' => 10, 'to' => 15], $entry->metadata['changes']['almost_sold_out_percent']);
        $this->assertSame(['from' => 10, 'to' => 0], $entry->metadata['changes']['only_left_under']);
        $this->assertArrayNotHasKey('almost_sold_out_floor', $entry->metadata['changes']);
    }

    public function test_a_share_over_a_hundred_is_not_saved(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));

        Livewire::test(PlatformSettings::class)
            ->fillForm(['almost_sold_out_percent' => '140'])
            ->call('save')
            ->assertHasFormErrors(['almost_sold_out_percent']);

        $this->assertSame(0, PlatformSetting::count());
    }

    public function test_finance_reads_it_and_cannot_change_it(): void
    {
        $this->actAs($this->staff(PlatformRole::Finance));

        Livewire::test(PlatformSettings::class)
            ->assertFormFieldIsDisabled('almost_sold_out_percent')
            ->assertFormFieldIsDisabled('only_left_under');
    }
}
