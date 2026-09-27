<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EventPerformance;
use App\Filament\Pages\OrganizerPerformance;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\PayoutRequests\Pages\ListPayoutRequests;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PayoutRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource as FilamentResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Money on the admin reads the way it reads everywhere else.
 *
 * "$1,250.00" and "₦5,000" — App\Support\Money — on every screen staff read,
 * because the organizer who rings about a payout is looking at their console,
 * which writes it that way, and "CAD 1,250.00" on our side of the call reads
 * as a different number. Every list, record and report page is opened as an
 * administrator over a week of trade in both currencies, and the HTML is
 * searched for the ways money used to be written here.
 *
 * Pages are found from the panel, not listed by hand, so a screen added later
 * is held to the same rule without anybody remembering to add it.
 */
class MoneyReadsOneWayTest extends TestCase
{
    use AnalyticsFixtures, RefreshDatabase;

    /**
     * The old styles: a currency code before the figure ("CAD 12.50", which
     * the organizations list and the payout forms wrote), Filament's own
     * money() ("CA$12.50"), and naira with kobo that are not there ("₦5,000.00").
     */
    private const OLD_STYLES = [
        'a currency code before the figure' => '/\b(?:CAD|NGN)(?:[ \t]|&nbsp;|&#160;|\x{00A0})+-?\d/u',
        'Filament\'s "CA$"' => '/CA\$/u',
        'whole naira written with .00' => '/₦(?:[ \t]|&nbsp;|&#160;|\x{00A0})?-?[\d,]*\d\.00(?!\d)/u',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTrade();
        $this->seedNaira();
        $this->signIn($this->staffMember(PlatformRole::Admin));
    }

    public function test_every_list_record_and_form_page_writes_money_one_way(): void
    {
        $checked = 0;

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            foreach (array_keys($resource::getPages()) as $name) {
                foreach ($this->urlsFor($resource, $name) as $url) {
                    $response = $this->get($url);
                    $response->assertSuccessful();
                    $this->assertWrittenOneWay($response->getContent(), $url);
                    $checked++;
                }
            }

            // Tables under a record page load after it; open them as the
            // page would, for every record they hang off.
            if (isset($resource::getPages()['view'])) {
                foreach ($resource::getRelations() as $relation) {
                    foreach ($this->records($resource) as $record) {
                        $html = Livewire::test($relation, [
                            'ownerRecord' => $record,
                            'pageClass' => $resource::getPages()['view']->getPage(),
                        ])->html();

                        $this->assertWrittenOneWay($html, class_basename($relation).' on '.$record->getKey());
                        $checked++;
                    }
                }
            }
        }

        // A panel of fifteen resources has well over this many pages; fewer
        // means the loop above stopped finding them.
        $this->assertGreaterThan(40, $checked);
    }

    public function test_the_dashboard_and_the_reports_write_money_one_way_in_both_markets(): void
    {
        foreach (['CAD', 'NGN'] as $currency) {
            $filters = ['currency' => $currency, 'period' => '7d'];

            foreach ((new Dashboard)->getWidgets() as $widget) {
                $html = Livewire::test($widget, ['pageFilters' => $filters])->html();
                $this->assertWrittenOneWay($html, class_basename($widget).' in '.$currency);
            }

            foreach (['/admin', '/admin/operations', '/admin/settings'] as $path) {
                $response = $this->get($path.'?'.http_build_query(['filters' => $filters]));
                $response->assertSuccessful();
                $this->assertWrittenOneWay($response->getContent(), $path);
            }
        }

        foreach ([[$this->toronto, 'CAD'], [$this->lagos, 'NGN']] as [$organization, $currency]) {
            $html = Livewire::test(OrganizerPerformance::class)
                ->set('filters.currency', $currency)
                ->set('filters.period', '7d')
                ->set('filters.organization', $organization->id)
                ->assertSee('Earned and paid out')
                ->html();

            $this->assertWrittenOneWay($html, 'organizer report for '.$organization->name);
        }

        foreach ([$this->night, $this->lagosNight] as $event) {
            $html = Livewire::test(EventPerformance::class)
                ->set('filters.currency', $event->currency)
                ->set('filters.event', $event->id)
                ->assertSee('Sales pace')
                ->html();

            $this->assertWrittenOneWay($html, 'event report for '.$event->title);
        }
    }

    public function test_the_organizations_list_writes_each_balance_as_money(): void
    {
        $this->get(OrganizationResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('$190.00')
            ->assertSee('₦5,000.25');
    }

    /**
     * The forms where money is sent: what is owed is written as money, and
     * the amount field says which currency it is typed in with the symbol,
     * not a code.
     */
    public function test_the_settle_and_pay_forms_write_money_one_way(): void
    {
        $settle = Livewire::test(ListOrganizations::class)
            ->mountTableAction('settle', $this->lagos)
            ->assertMountedActionModalSee('NGN — ₦5,000.25 owed')
            ->assertMountedActionModalSee('in dollars or naira');

        $this->assertWrittenOneWay($settle->getMountedActionModalHtml(), 'settle form');

        $settle->fillForm(['currency' => 'NGN'])
            ->assertMountedActionModalSee('What actually left the account, in naira.');

        // The amount field's prefix: the symbol alone, once the currency is chosen.
        $this->assertMatchesRegularExpression('/fi-input-wrp-label">\s*₦\s*</u', $settle->getMountedActionModalHtml());
        $this->assertWrittenOneWay($settle->getMountedActionModalHtml(), 'settle form in naira');

        $request = PayoutRequest::query()->where('currency', 'NGN')->where('status', 'pending')->firstOrFail();

        $pay = Livewire::test(ListPayoutRequests::class)
            ->mountTableAction('pay', $request)
            ->assertMountedActionModalSee('Asked for ₦3,000 · owed now ₦5,000.25')
            ->assertMountedActionModalSee('What actually left the account, in naira.')
            ->assertMountedActionModalDontSee('in NGN.');

        $this->assertWrittenOneWay($pay->getMountedActionModalHtml(), 'pay form');
    }

    /**
     * Amounts in the audit trail are recorded in minor units beside their
     * currency; on a record's page they are read as money, so a settlement of
     * 500000 kobo is never read as half a million. The full entry keeps what
     * was written and says what it comes to.
     */
    public function test_the_audit_trail_reads_recorded_amounts_as_money(): void
    {
        $this->get(OrganizationResource::getUrl('view', ['record' => $this->lagos]))
            ->assertSuccessful()
            ->assertSee('amount: ₦5,000 · currency: NGN')
            ->assertSee('requested: ₦3,000 · paid: ₦2,000.50 · currency: NGN')
            ->assertSee('before: {"price_amount":"₦2,500"}')
            ->assertSee('tickets: 2')
            ->assertDontSee('amount: 500000');

        $entry = AuditLog::query()->where('action', 'settlement.recorded')->firstOrFail();

        $this->get(AuditLogResource::getUrl('view', ['record' => $entry]))
            ->assertSuccessful()
            ->assertSee('"amount": 500000')
            ->assertSee('amount: ₦5,000');
    }

    /**
     * Lagos Nights' side of the week, beside Toronto's: owed ₦5,000.25 — kobo
     * and all, so the kobo are seen to be kept — after ₦5,000 paid out, with
     * a request for ₦3,000 waiting and one for ₦2,000.50 paid. A part refund
     * and a chargeback on its order, and audit entries that carry amounts.
     */
    private function seedNaira(): void
    {
        $n1 = $this->orders['N1'];
        $buyer = User::factory()->create(['email' => 'tunde@example.com', 'name' => 'Tunde Bakare']);

        // One account that bought in both markets, for the person's page.
        $n1->forceFill(['user_id' => $buyer->id])->save();
        $this->orders['O1']->forceFill(['user_id' => $buyer->id])->save();

        foreach ([[1_000_025, 'sale'], [-500_000, 'settlement']] as [$amount, $type]) {
            DB::table('ledger_entries')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $this->lagos->id,
                'event_id' => $this->lagosNight->id,
                'type' => $type,
                'amount' => $amount,
                'currency' => 'NGN',
                'occurred_at' => '2026-09-18 12:00:00',
            ]);
        }

        DB::table('settlements')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->lagos->id,
            'amount' => 500_000,
            'currency' => 'NGN',
            'rail' => 'bank_transfer',
            'type' => 'partial',
            'status' => 'success',
            'settled_at' => '2026-09-18 12:00:00',
            'created_at' => '2026-09-18 12:00:00',
            'updated_at' => '2026-09-18 12:00:00',
        ]);

        foreach ([
            ['amount' => 300_000, 'status' => 'pending', 'paid_amount' => null, 'decided_at' => null],
            ['amount' => 200_050, 'status' => 'paid', 'paid_amount' => 200_050, 'decided_at' => '2026-09-17 12:00:00'],
        ] as $request) {
            DB::table('payout_requests')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $this->lagos->id,
                'currency' => 'NGN',
                'balance_at_request' => 1_000_025,
                'created_at' => '2026-09-16 12:00:00',
                'updated_at' => '2026-09-16 12:00:00',
                ...$request,
            ]);
        }

        // Toronto's request paid too, so "paid by" is on both markets' rows.
        DB::table('payout_requests')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->toronto->id,
            'currency' => 'CAD',
            'amount' => 2_500,
            'balance_at_request' => 19_000,
            'status' => 'paid',
            'paid_amount' => 2_500,
            'decided_at' => '2026-09-12 12:00:00',
            'created_at' => '2026-09-11 12:00:00',
            'updated_at' => '2026-09-12 12:00:00',
        ]);

        DB::table('refunds')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $n1->id,
            'event_id' => $this->lagosNight->id,
            'organization_id' => $this->lagos->id,
            'currency' => 'NGN',
            'amount' => 20_625,
            'tax_amount' => 0,
            'service_charge_amount' => 0,
            'status' => 'succeeded',
            'reason' => 'requested_by_customer',
            'created_at' => '2026-09-19 12:00:00',
            'updated_at' => '2026-09-19 12:00:00',
        ]);

        DB::table('disputes')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $n1->id,
            'organization_id' => $this->lagos->id,
            'event_id' => $this->lagosNight->id,
            'gateway' => 'paystack',
            'gateway_reference' => 'dsp_'.Str::random(10),
            'amount' => 100_000,
            'currency' => 'NGN',
            'reason' => 'fraudulent',
            'status' => 'open',
            'opened_at' => '2026-09-19 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['settlement.recorded', ['amount' => 500_000, 'currency' => 'NGN', 'rail' => 'bank_transfer']],
            ['payout_request.paid', ['requested' => 300_000, 'paid' => 200_050, 'currency' => 'NGN', 'tickets' => 2]],
            ['ticket.price_changed', ['before' => ['price_amount' => 250_000], 'after' => ['price_amount' => 300_000], 'currency' => 'NGN']],
        ] as [$action, $metadata]) {
            AuditLog::query()->create([
                'organization_id' => $this->lagos->id,
                'action' => $action,
                'subject_type' => Organization::class,
                'subject_id' => $this->lagos->id,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
        }

        AuditLog::query()->create([
            'organization_id' => $this->lagos->id,
            'action' => 'refund.failed_at_processor',
            'subject_type' => $n1::class,
            'subject_id' => $n1->id,
            'metadata' => ['amount' => 20_625, 'currency' => 'NGN'],
            'created_at' => now(),
        ]);
    }

    /**
     * Where a resource's page is: once for a list or a blank form, once per
     * record for a record's page.
     *
     * @param  class-string<FilamentResource>  $resource
     * @return list<string>
     */
    private function urlsFor(string $resource, string $name): array
    {
        if (! in_array($name, ['view', 'edit'], true)) {
            return [$resource::getUrl($name)];
        }

        return $this->records($resource)
            ->map(fn (Model $record) => $resource::getUrl($name, ['record' => $record]))
            ->all();
    }

    /**
     * Every record a resource lists here, in both currencies where it has
     * money. The fixture is a week, so that is dozens, not thousands.
     *
     * @param  class-string<FilamentResource>  $resource
     * @return Collection<int, Model>
     */
    private function records(string $resource): Collection
    {
        return $resource::getEloquentQuery()->limit(25)->get();
    }

    /**
     * Read as a person would see it: entities decoded, and the JSON a
     * component hands its script read with its unicode escapes undone, so a
     * naira sign in a select's options is the sign it will show.
     */
    private function assertWrittenOneWay(string $html, string $where): void
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = (string) preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            fn (array $m) => mb_chr((int) hexdec($m[1]), 'UTF-8'),
            $html,
        );

        foreach (self::OLD_STYLES as $style => $pattern) {
            preg_match_all($pattern, $html, $found, PREG_OFFSET_CAPTURE);

            $this->assertSame([], array_map(
                fn (array $match) => trim(strip_tags(mb_strcut($html, max(0, $match[1] - 60), 120, 'UTF-8'))),
                $found[0],
            ), "{$where}: money written as {$style}.");
        }
    }
}
