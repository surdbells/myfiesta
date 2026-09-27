<?php

namespace Tests\Feature;

use App\Contracts\Payments\CheckoutSession;
use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Contracts\Payments\RefundResult;
use App\Enums\PlatformRole;
use App\Enums\TokenAbility;
use App\Filament\Resources\Events\Schemas\EventFigures;
use App\Mail\TicketsIssued;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Models\Refund;
use App\Models\TaxRate;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Analytics\Market;
use App\Services\Analytics\Period;
use App\Services\Analytics\PlatformMetrics;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use App\Services\Receipts\Receipt;
use App\Services\Refunds\RefundService;
use App\Services\Settings\PlatformSettings;
use App\Services\StaffSupport\StaffActionRefused;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Tax and the service charge as staff configure them, and the receipt that
 * says what was charged.
 *
 * Every sale here goes through checkout and fulfilment for real, and every
 * one is held to the same two sums the rest of the suite holds orders to: the
 * database's own (total = net revenue + tax + service charge) and the
 * ledger's (what the organizer is owed is exactly the net revenue). A setting
 * that broke either would be charging somebody money nobody can account for.
 */
class TaxAndReceiptsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);
    }

    // --- Quebec -------------------------------------------------------------

    public function test_quebec_pays_gst_and_no_qst_while_qst_is_off(): void
    {
        $order = $this->sell($this->event('CA', 'QC'), 10000);

        $this->assertSame(500, $order->tax_amount, 'Quebec is 5% GST until QST is switched on.');
        $this->assertSame(['GST'], array_column($order->tax_lines, 'name'));
        $this->assertSame(800, $order->service_charge_amount);
        $this->assertSame(11300, $order->total_amount);
        $this->assertBalances($order);
    }

    public function test_quebec_pays_gst_and_qst_as_separate_lines_when_qst_is_on(): void
    {
        $this->set(['qst_enabled' => true]);

        $order = $this->sell($this->event('CA', 'QC'), 10000);

        // 9.975% of 100.00 is 9.975, rounded half up to 9.98.
        $this->assertSame(
            [['GST', 50000, 500], ['QST', 99750, 998]],
            array_map(fn (array $l) => [$l['name'], $l['rate_ppm'], $l['amount']], $order->tax_lines),
        );
        $this->assertSame(1498, $order->tax_amount, 'Both are tax on the ticket, and the order carries their sum.');
        $this->assertSame(10000, $order->net_revenue_amount, 'Neither is the organizer\'s money.');
        $this->assertSame(12298, $order->total_amount);
        $this->assertBalances($order);

        $receipt = Receipt::for($order);
        $this->assertSame(['GST' => '5', 'QST' => '9.975'], collect($receipt->taxes)->mapWithKeys(fn ($l) => [$l->name => $l->percent()])->all());
    }

    public function test_qst_is_only_for_quebec(): void
    {
        $this->set(['qst_enabled' => true]);

        $order = $this->sell($this->event('CA', 'AB'), 10000);

        $this->assertSame(['GST'], array_column($order->tax_lines, 'name'));
        $this->assertSame(500, $order->tax_amount);
    }

    public function test_the_quote_names_both_taxes(): void
    {
        $this->set(['qst_enabled' => true]);
        $event = $this->event('CA', 'QC');
        $type = $this->type($event, 10000);

        $body = $this->postJson("/api/events/{$event->slug}/quote", [
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertOk()->json();

        $this->assertSame('GST + QST', $body['tax_label']);
        $this->assertSame(['5', '9.975'], array_column($body['tax_lines'], 'rate'));
        $this->assertSame(0, $body['service_charge_tax']['amount']);
    }

    // --- the service charge ---------------------------------------------------

    public function test_the_service_charge_is_not_taxed_unless_switched_on(): void
    {
        $order = $this->sell($this->event('CA', 'ON'), 10000);

        $this->assertSame(800, $order->service_charge_amount);
        $this->assertSame(0, $order->service_charge_tax_amount);
        $this->assertSame(12100, $order->total_amount);
    }

    public function test_tax_on_the_service_charge_is_added_in_canada_and_kept_out_of_the_organizers_ledger(): void
    {
        $this->set(['tax_on_service_charge' => true]);

        $order = $this->sell($this->event('CA', 'ON'), 10000);

        // 13% of the 8.00 service charge. It travels with the service
        // charge; the tickets' tax is unchanged.
        $this->assertSame(1300, $order->tax_amount);
        $this->assertSame(104, $order->service_charge_tax_amount);
        $this->assertSame(904, $order->service_charge_amount);
        $this->assertSame(12204, $order->total_amount);
        $this->assertSame(
            [['HST', 'tickets', 1300], ['HST', 'service_charge', 104]],
            array_map(fn (array $l) => [$l['name'], $l['on'], $l['amount']], $order->tax_lines),
        );
        $this->assertBalances($order);
    }

    public function test_quebec_taxes_the_service_charge_with_both(): void
    {
        $this->set(['qst_enabled' => true, 'tax_on_service_charge' => true]);

        $order = $this->sell($this->event('CA', 'QC'), 10000);

        // 5% and 9.975% of 8.00: 0.40 and 0.80.
        $this->assertSame(120, $order->service_charge_tax_amount);
        $this->assertSame(920, $order->service_charge_amount);
        $this->assertSame(10000 + 1498 + 920, $order->total_amount);
        $this->assertBalances($order);
    }

    public function test_in_nigeria_vat_on_the_service_charge_is_inside_it_and_the_buyer_pays_the_same(): void
    {
        $before = $this->sell($this->event('NG', null), 107500, 2);

        $this->set(['tax_on_service_charge' => true]);

        $after = $this->sell($this->event('NG', null), 107500, 2);

        $this->assertSame(15000, $after->tax_amount);
        $this->assertSame(200000, $after->net_revenue_amount);
        $this->assertSame(16000, $after->service_charge_amount, 'Prices include VAT in Nigeria, and so does the service charge.');
        $this->assertSame(1116, $after->service_charge_tax_amount, '7.5% extracted from 16,000 kobo.');
        $this->assertSame($before->total_amount, $after->total_amount);
        $this->assertBalances($after);

        $receipt = Receipt::for($after);
        $this->assertTrue($receipt->taxIncluded());
        $this->assertSame(16000, $receipt->serviceCharge->amount, 'The VAT stays in the figure and is shown as included.');
    }

    public function test_a_door_sale_still_carries_no_service_charge_or_tax_on_one(): void
    {
        $this->set(['tax_on_service_charge' => true, 'seller_of_record' => 'platform']);
        $event = $this->event('CA', 'ON');
        $type = $this->type($event, 10000);

        $order = app(CheckoutService::class)->reserve($event, [$type->id => 1], null, 'Walk-up', channel: 'door', paymentMethod: 'cash');

        $this->assertSame(0, $order->service_charge_amount);
        $this->assertSame(0, $order->service_charge_tax_amount);
        $this->assertSame(0, $order->pricing_snapshot['service_charge_bps']);
    }

    public function test_the_service_charge_is_set_per_currency(): void
    {
        $this->set(['service_charge_bps_cad' => 1000, 'service_charge_bps_ngn' => 500]);

        $this->assertSame(1000, $this->sell($this->event('CA', 'ON'), 10000)->service_charge_amount);
        $this->assertSame(5000, $this->sell($this->event('NG', null), 107500)->service_charge_amount);
    }

    public function test_a_full_refund_of_a_taxed_service_charge_leaves_the_organizer_at_zero(): void
    {
        $this->set(['tax_on_service_charge' => true]);
        $this->fakeProcessor();

        $order = $this->sell($this->event('CA', 'ON'), 10000, 1, 'stripe');

        $refund = app(RefundService::class)->refund($order);

        $this->assertSame($order->total_amount, $refund->amount, 'The buyer gets back everything, the tax on the service charge included.');
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
    }

    public function test_a_refund_records_how_much_of_its_service_charge_was_tax(): void
    {
        $this->set(['tax_on_service_charge' => true]);
        $this->fakeProcessor();

        // Three tickets at 100.00 in Ontario: 800 service charge each, and
        // 104 HST on each of those.
        $order = $this->sell($this->event('CA', 'ON'), 10000, 3, 'stripe');
        $this->assertSame([2712, 312], [$order->service_charge_amount, $order->service_charge_tax_amount]);

        $tickets = $order->tickets()->orderBy('created_at')->orderBy('id')->pluck('id')->all();

        $one = app(RefundService::class)->refund($order, [$tickets[0]]);
        $this->assertSame([904, 104], [$one->service_charge_amount, $one->service_charge_tax_amount]);

        app(RefundService::class)->refund($order->fresh(), [$tickets[1], $tickets[2]]);

        $refunds = Refund::where('order_id', $order->id)->where('status', 'succeeded');
        $this->assertSame(2712, (int) $refunds->sum('service_charge_amount'));
        $this->assertSame(312, (int) $refunds->sum('service_charge_tax_amount'), 'Refunded in pieces, the tax comes back exactly.');
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));

        // One made in the processor's dashboard takes its proportion, and the
        // last takes what is left.
        $other = $this->sell($this->event('CA', 'ON'), 10000, 3, 'stripe');

        $part = app(RefundService::class)->recordMadeElsewhere($other, 12204, 're_part');
        $this->assertSame(104, $part->service_charge_tax_amount);

        $rest = app(RefundService::class)->recordMadeElsewhere($other->fresh(), $other->total_amount - 12204, 're_rest');
        $this->assertSame(208, $rest->service_charge_tax_amount);
        $this->assertSame(2712, $part->service_charge_amount + $rest->service_charge_amount);
    }

    public function test_the_admin_counts_tax_in_the_service_charge_as_tax_and_not_as_platform_revenue(): void
    {
        // The platform as the seller, so the service charge is taxed.
        $this->set(['seller_of_record' => 'platform']);
        $this->fakeProcessor();

        $event = $this->event('CA', 'ON');

        // 10000 + 1300 HST + (800 + 104 HST).
        $one = $this->sell($event, 10000, 1, 'stripe');
        // Three of the same, and one of them refunded.
        $three = $this->sell($event, 10000, 3, 'stripe');
        app(RefundService::class)->refund($three, [$three->tickets()->orderBy('created_at')->orderBy('id')->value('id')]);

        $period = Period::resolve('7d', timezone: Market::timezone('CAD'));
        $metrics = app(PlatformMetrics::class);

        $now = $metrics->overview('CAD', $period)['current'];
        $this->assertSame(800 + 2400, $now['service'], 'The platform keeps its 8%, not the HST on it.');
        $this->assertSame(1300 + 104 + 3900 + 312, $now['tax'], 'The HST on the service charge is tax like any other.');
        $this->assertSame(800, $now['refunded_service']);
        $this->assertSame(3200 - $now['fees'] - 800, $now['net_take']);

        $split = $metrics->revenueSplit('CAD', $period);
        $this->assertSame([
            'gross' => $one->total_amount + $three->total_amount,
            'organizer' => 30000,
            'platform' => 2400,
            'tax' => 5616 - 1404,
            'refunded' => 12204,
        ], $split);
        $this->assertSame($split['gross'], $split['organizer'] + $split['platform'] + $split['tax'] + $split['refunded']);

        $kept = $metrics->revenueSplitTrend('CAD', $period);
        $this->assertSame($split['platform'], array_sum($kept['platform']));
        $this->assertSame($split['tax'], array_sum($kept['tax']));

        $this->assertSame(array_sum($metrics->trend('CAD', $period)['service']), $now['service']);

        $this->assertStringContainsString('32.00', EventFigures::for($event)['service'], 'The event page counts the same way.');
    }

    // --- seller of record -----------------------------------------------------

    public function test_with_the_organizer_as_seller_the_receipt_names_them_and_the_platform_for_the_service(): void
    {
        $this->set([
            'legal_name' => 'Fiesta Tickets Inc.',
            'address_ca' => '1 Front St W, Toronto ON',
            'gst_hst_number' => '123456789 RT0001',
        ]);

        $untaxed = Receipt::for($this->sell($this->event('CA', 'ON'), 10000));

        $this->assertSame('organizer', $untaxed->sellerOfRecord->value);
        $this->assertSame('Lagos Nights', $untaxed->seller['name']);
        $this->assertSame([], $untaxed->seller['registrations'], 'The organizer\'s own numbers are not held.');
        $this->assertSame('Fiesta Tickets Inc.', $untaxed->service['name']);
        $this->assertSame([], $untaxed->service['registrations'], 'The platform charged no tax, so none of its numbers apply.');

        $this->set(['tax_on_service_charge' => true]);

        $taxed = Receipt::for($this->sell($this->event('CA', 'ON'), 10000));

        $this->assertSame([['label' => 'GST/HST', 'number' => '123456789 RT0001']], $taxed->service['registrations']);
        $this->assertSame('1 Front St W, Toronto ON', $taxed->service['address']);
    }

    public function test_with_the_platform_as_seller_it_is_named_and_the_service_charge_is_taxed_regardless(): void
    {
        $this->set([
            'seller_of_record' => 'platform',
            'tax_on_service_charge' => false,
            'legal_name' => 'Fiesta Tickets Inc.',
            'gst_hst_number' => '123456789 RT0001',
            'qst_enabled' => true,
            'qst_number' => '1234567890 TQ0001',
        ]);

        $order = $this->sell($this->event('CA', 'QC'), 10000);

        $this->assertSame('platform', $order->seller_of_record);
        $this->assertSame(120, $order->service_charge_tax_amount, 'Part of the price of what the platform sold.');
        $this->assertBalances($order);

        $receipt = Receipt::for($order);
        $this->assertSame('Fiesta Tickets Inc.', $receipt->seller['name']);
        $this->assertSame('Lagos Nights', $receipt->organizer);
        $this->assertNull($receipt->service);
        $this->assertSame(['GST/HST', 'QST'], array_column($receipt->seller['registrations'], 'label'));
    }

    // --- orders keep what applied to them -------------------------------------

    public function test_an_order_is_unaffected_by_settings_changed_after_it(): void
    {
        $this->set(['legal_name' => 'Fiesta Tickets Inc.']);
        $old = $this->sell($this->event('CA', 'QC'), 10000);
        $then = Receipt::for($old)->toArray();

        $this->set([
            'seller_of_record' => 'platform',
            'tax_on_service_charge' => true,
            'qst_enabled' => true,
            'service_charge_bps_cad' => 1000,
            'legal_name' => 'Somebody Else Ltd.',
            'gst_hst_number' => '999999999 RT0001',
        ]);

        $this->assertSame($then, Receipt::for($old->fresh())->toArray(), 'A receipt printed later says what was charged then.');
        $this->assertSame('Lagos Nights', $then['seller']['name']);
        $this->assertSame(['GST'], array_column($then['taxes'], 'name'));
        $this->assertSame(800, $then['service_charge']['amount']);

        $new = $this->sell($this->event('CA', 'QC'), 10000);
        $this->assertSame('Somebody Else Ltd.', Receipt::for($new)->seller['name']);
        $this->assertSame(1000 + 50 + 100, $new->service_charge_amount);
    }

    public function test_an_order_from_before_orders_kept_their_taxes_reads_as_it_was_made(): void
    {
        $event = $this->event('CA', 'ON');
        $rate = TaxRate::resolve('CA', 'ON');

        $order = Order::create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => 10000,
            'discount_amount' => 0,
            'tax_amount' => 1300,
            'net_revenue_amount' => 10000,
            'service_charge_amount' => 800,
            'total_amount' => 12100,
            'tax_rate_id' => $rate->id,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $receipt = Receipt::for($order);

        $this->assertSame('organizer', $receipt->sellerOfRecord->value);
        $this->assertCount(1, $receipt->taxes);
        $this->assertSame(['HST', '13', 1300], [$receipt->taxes[0]->name, $receipt->taxes[0]->percent(), $receipt->taxes[0]->amount->amount]);
        $this->assertSame(800, $receipt->serviceCharge->amount);
    }

    // --- changing the settings ------------------------------------------------

    public function test_a_settings_change_is_audited_with_what_it_replaced(): void
    {
        $admin = $this->staff(PlatformRole::Admin);

        $changes = app(PlatformSettings::class)->update([
            'service_charge_bps_cad' => 900,
            'qst_enabled' => true,
            'seller_of_record' => 'organizer', // unchanged, so not recorded
            'not_a_setting' => 'ignored',
        ], $admin);

        $this->assertSame(['service_charge_bps_cad', 'qst_enabled'], array_keys($changes));

        $entry = AuditLog::where('action', 'platform_settings.changed')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame(['from' => 800, 'to' => 900], $entry->metadata['changes']['service_charge_bps_cad']);
        $this->assertSame(['from' => false, 'to' => true], $entry->metadata['changes']['qst_enabled']);

        // Applies at once: the cache is not left holding the old value.
        $this->assertSame(900, app(PlatformSettings::class)->serviceChargeBps('CAD'));

        // Saving again with nothing different records nothing.
        app(PlatformSettings::class)->update(['service_charge_bps_cad' => 900], $admin);
        $this->assertSame(1, AuditLog::where('action', 'platform_settings.changed')->count());
    }

    public function test_only_administrators_change_settings(): void
    {
        $finance = $this->staff(PlatformRole::Finance);

        try {
            app(PlatformSettings::class)->update(['service_charge_bps_cad' => 0], $finance);
            $this->fail('Finance changed the service charge.');
        } catch (StaffActionRefused) {
            $this->assertSame(800, app(PlatformSettings::class)->serviceChargeBps('CAD'));
            $this->assertSame(0, PlatformSetting::count());
        }
    }

    public function test_a_setting_outside_its_range_is_refused(): void
    {
        $this->expectException(StaffActionRefused::class);

        app(PlatformSettings::class)->update(['service_charge_bps_cad' => 20000], $this->staff(PlatformRole::Admin));
    }

    public function test_configuration_is_the_default_until_staff_choose(): void
    {
        config([
            'payments.service_charge_bps' => 750,
            'tax.qst.enabled' => true,
            'tax.qst.rate' => '9.975',
            'tax.registration.gst_hst' => '123456789 RT0001',
            'myfiesta.contact.company_name' => 'Configured Inc.',
        ]);

        $settings = app(PlatformSettings::class);

        $this->assertSame(750, $settings->serviceChargeBps('CAD'));
        $this->assertSame(99750, $settings->qstPpm());
        $this->assertSame('Configured Inc.', $settings->legalName());
        $this->assertSame([['label' => 'GST/HST', 'number' => '123456789 RT0001']], $settings->registrations('CA', 'ON'));

        // A number cleared on purpose stays cleared rather than falling back.
        $settings->update(['gst_hst_number' => ''], $this->staff(PlatformRole::Admin));
        $this->assertSame([], $settings->registrations('CA', 'ON'));
    }

    // --- the launch rates -----------------------------------------------------

    public function test_the_launch_rates_install_once_on_an_empty_database(): void
    {
        TaxRate::query()->delete();

        $migration = $this->launchRatesMigration();

        $this->assertSame(count(TaxRateSeeder::RATES), $migration->install());
        $this->assertSame(0, $migration->install());
        $this->assertSame(500, TaxRate::resolve('CA', 'QC')->rate_bps);
        $this->assertSame(1300, TaxRate::resolve('CA', 'ON')->rate_bps);
        $this->assertTrue(TaxRate::resolve('NG', null)->inclusive);
    }

    public function test_the_launch_rates_migration_leaves_a_seeded_database_alone(): void
    {
        // Seeded in setUp, as a developer's or staging database already is.
        // Staff then superseded Ontario.
        $ontario = TaxRate::resolve('CA', 'ON');
        $ontario->update(['effective_to' => '2025-01-01']);
        TaxRate::create([
            'country' => 'CA', 'subdivision' => 'ON', 'default_currency' => 'CAD',
            'name' => 'HST', 'rate_bps' => 1350, 'inclusive' => false, 'effective_from' => '2025-01-01',
        ]);
        $before = TaxRate::query()->orderBy('id')->get(['id', 'rate_bps', 'effective_to'])->toArray();

        $migration = $this->launchRatesMigration();

        $this->assertSame(0, $migration->install());
        $this->assertSame(0, $migration->install());
        $this->assertSame($before, TaxRate::query()->orderBy('id')->get(['id', 'rate_bps', 'effective_to'])->toArray());
        $this->assertSame(1350, TaxRate::resolve('CA', 'ON')->rate_bps, 'The rate staff chose is still the one in force.');
    }

    // --- a rate in use is superseded, never edited ------------------------------

    public function test_a_rate_that_has_applied_cannot_have_its_terms_edited(): void
    {
        $ontario = TaxRate::resolve('CA', 'ON');

        // Its name can be corrected.
        $ontario->update(['name' => 'HST (Ontario)']);
        $this->assertSame('HST (Ontario)', $ontario->fresh()->name);

        $this->expectException(\LogicException::class);
        $ontario->update(['rate_bps' => 1500]);
    }

    public function test_a_scheduled_rate_nothing_has_used_can_still_be_corrected(): void
    {
        $ontario = TaxRate::resolve('CA', 'ON');
        $ontario->update(['effective_to' => now()->addMonth()->toDateString()]);

        $next = TaxRate::create([
            'country' => 'CA', 'subdivision' => 'ON', 'default_currency' => 'CAD',
            'name' => 'HST', 'rate_bps' => 1400, 'inclusive' => false,
            'effective_from' => now()->addMonth()->toDateString(),
        ]);

        $next->update(['rate_bps' => 1350]);

        $this->assertSame(1350, $next->fresh()->rate_bps);
    }

    public function test_a_scheduled_rate_is_never_moved_into_the_past(): void
    {
        $ontario = TaxRate::resolve('CA', 'ON');
        $ontario->update(['effective_to' => now()->addMonth()->toDateString()]);

        $next = TaxRate::create([
            'country' => 'CA', 'subdivision' => 'ON', 'default_currency' => 'CAD',
            'name' => 'HST', 'rate_bps' => 1400, 'inclusive' => false,
            'effective_from' => now()->addMonth()->toDateString(),
        ]);

        try {
            $next->update(['effective_from' => now()->subMonth()->toDateString()]);
            $this->fail('A scheduled rate was moved to start last month.');
        } catch (\LogicException) {
            $this->assertSame(now()->addMonth()->toDateString(), $next->fresh()->effective_from->toDateString());
        }

        $this->assertSame($ontario->id, TaxRate::resolve('CA', 'ON')->id);
    }

    public function test_the_database_refuses_two_rates_for_a_place_on_one_day(): void
    {
        $ontario = TaxRate::resolve('CA', 'ON');

        // The old index allowed this: only open-ended rows were unique, and
        // this one has an end date. Checkout would then have had two.
        foreach (['ON', 'on'] as $province) {
            try {
                DB::transaction(fn () => TaxRate::query()->insert([
                    'id' => (string) Str::uuid(),
                    'country' => 'CA', 'subdivision' => $province, 'default_currency' => 'CAD',
                    'name' => 'HST', 'rate_bps' => 1500, 'inclusive' => false,
                    'effective_from' => now()->toDateString(),
                    'effective_to' => now()->addYear()->toDateString(),
                ]));
                $this->fail("A second rate for {$province} was stored beside the one in force.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('tax_rates_no_overlap', $e->getMessage());
            }
        }

        $this->assertSame(1, TaxRate::query()->forPlace('CA', 'ON')->count());
        $this->assertSame($ontario->id, TaxRate::resolve('CA', 'ON')->id);

        // A rate that closes the day its replacement starts shares no day
        // with it, which is what a supersession leaves.
        $ontario->update(['effective_to' => now()->addMonth()->toDateString()]);
        TaxRate::create([
            'country' => 'CA', 'subdivision' => 'on', 'default_currency' => 'CAD',
            'name' => 'HST', 'rate_bps' => 1400, 'inclusive' => false,
            'effective_from' => now()->addMonth()->toDateString(),
        ]);

        $this->assertSame(1400, TaxRate::resolve('CA', 'ON', now()->addMonth()->toDateString())->rate_bps, 'Stored as ON, so an Ontario event finds it.');
        $this->assertSame(1300, TaxRate::resolve('CA', 'ON')->rate_bps);
    }

    // --- the receipt, where the buyer reads it ---------------------------------

    public function test_the_confirmation_email_carries_the_receipt(): void
    {
        $this->set([
            'qst_enabled' => true,
            'tax_on_service_charge' => true,
            'legal_name' => 'Fiesta Tickets Inc.',
            'gst_hst_number' => '123456789 RT0001',
            'qst_number' => '1234567890 TQ0001',
        ]);

        $order = $this->sell($this->event('CA', 'QC'), 10000, 2);
        $html = (new TicketsIssued($order->fresh()))->render();

        $this->assertStringContainsString('Receipt', $html);
        $this->assertStringContainsString($order->reference, $html);
        $this->assertStringContainsString('General', $html);
        $this->assertStringContainsString('GST 5%', $html);
        $this->assertStringContainsString('QST 9.975%', $html);
        $this->assertStringContainsString('$19.95', $html, 'QST on 200.00 at 9.975%.');
        $this->assertStringContainsString('GST 5% on the service charge', $html);
        $this->assertStringContainsString('Service charge', $html);
        $this->assertStringContainsString('$16.00', $html);
        $this->assertStringContainsString(Str::of('$')->append(number_format($order->total_amount / 100, 2))->toString(), $html);
        $this->assertStringContainsString('Tickets sold by', $html);
        $this->assertStringContainsString('Lagos Nights', $html);
        $this->assertStringContainsString('Fiesta Tickets Inc.', $html);
        $this->assertStringContainsString('GST/HST 123456789 RT0001', $html);
        $this->assertStringContainsString('QST 1234567890 TQ0001', $html);

        // The codes are where they always were — once each, in the panel —
        // and the receipt adds none.
        foreach ($order->tickets as $ticket) {
            $this->assertSame(1, substr_count($html, $ticket->code));
        }
    }

    public function test_the_ticket_page_carries_the_receipt_without_codes(): void
    {
        $this->set(['tax_on_service_charge' => true]);

        $order = $this->sell($this->event('CA', 'ON'), 10000);

        $receipt = $this->getJson("/api/tickets/{$order->access_token}")->assertOk()->json('receipt');

        $this->assertSame($order->reference, $receipt['reference']);
        $this->assertSame('Lagos Nights', $receipt['seller']['name']);
        $this->assertSame([
            ['name' => 'HST', 'rate' => '13', 'on' => 'tickets', 'included' => false, 'amount' => ['amount' => 1300, 'currency' => 'CAD']],
            ['name' => 'HST', 'rate' => '13', 'on' => 'service_charge', 'included' => false, 'amount' => ['amount' => 104, 'currency' => 'CAD']],
        ], $receipt['taxes']);
        $this->assertSame(800, $receipt['service_charge']['amount']);
        $this->assertSame(12204, $receipt['total']['amount']);

        // It adds up the way the contract says it does.
        $added = array_sum(array_map(fn ($t) => $t['included'] ? 0 : $t['amount']['amount'], $receipt['taxes']));
        $this->assertSame(
            $receipt['total']['amount'],
            $receipt['subtotal']['amount'] - $receipt['discount']['amount'] + $added + $receipt['service_charge']['amount'],
        );

        $json = json_encode($receipt);
        foreach ($order->tickets as $ticket) {
            $this->assertStringNotContainsString($ticket->code, $json);
        }
    }

    public function test_the_app_shows_the_receipt_only_to_the_account_that_bought(): void
    {
        $buyer = User::factory()->create();
        $friend = User::factory()->create();
        $event = $this->event('CA', 'ON');
        $type = $this->type($event, 10000);

        $order = app(CheckoutService::class)->reserve($event, [$type->id => 2], $buyer->email, $buyer->name, user: $buyer);
        app(Fulfiller::class)->fulfil($order);

        $tickets = Ticket::where('order_id', $order->id)->get();
        $tickets[1]->forceFill(['owner_user_id' => $friend->id, 'owner_email' => $friend->email])->save();

        Sanctum::actingAs($buyer, [TokenAbility::Attendee->value]);
        $mine = $this->getJson('/api/me/tickets')->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->assertSame($order->reference, $mine[0]['receipt']['reference']);

        Sanctum::actingAs($friend, [TokenAbility::Attendee->value]);
        $theirs = $this->getJson('/api/me/tickets')->assertOk()->json('data');
        $this->assertCount(1, $theirs);
        $this->assertNull($theirs[0]['receipt'], 'A ticket passed on does not carry what the buyer paid.');
    }

    public function test_the_app_shows_the_receipt_for_an_order_placed_without_signing_in(): void
    {
        $this->fakeProcessor();
        $event = $this->event('CA', 'ON');
        $type = $this->type($event, 10000);

        $ada = User::factory()->create(['email' => 'ada@example.com', 'email_verified_at' => now()]);
        $friend = User::factory()->create(['email' => 'friend@example.com', 'email_verified_at' => now()]);

        // Ada has an account and buys on the website, which sends no token.
        $this->postJson("/api/events/{$event->slug}/orders", [
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 2]],
            'buyer' => ['name' => 'Ada Okafor', 'email' => 'Ada@Example.com'],
            'accept_terms' => true,
        ])->assertCreated();

        $order = Order::where('event_id', $event->id)->sole();
        $this->assertNull($order->user_id, 'Nobody was signed in to place it.');
        app(Fulfiller::class)->fulfil($order);

        // Then she opens the app.
        Sanctum::actingAs($ada, [TokenAbility::Attendee->value]);
        $mine = $this->getJson('/api/me/tickets')->assertOk()->json('data');
        $this->assertCount(2, $mine);
        $this->assertSame([$order->reference, $order->reference], array_column(array_column($mine, 'receipt'), 'reference'));

        // She passes one to a friend, whose copy says nothing of what she paid.
        $this->postJson("/api/tickets/{$mine[0]['id']}/transfer", ['email' => 'friend@example.com', 'name' => 'Chidi Friend'])
            ->assertOk();

        Sanctum::actingAs($friend, [TokenAbility::Attendee->value]);
        $theirs = $this->getJson('/api/me/tickets')->assertOk()->json('data');
        $this->assertCount(1, $theirs);
        $this->assertNull($theirs[0]['receipt']);

        // She moves her account to a new address. The ticket that never left
        // her still shows what she paid.
        $ada->forceFill(['email' => 'ada@new.example'])->save();

        Sanctum::actingAs($ada->fresh(), [TokenAbility::Attendee->value]);
        $kept = $this->getJson('/api/me/tickets')->assertOk()->json('data');
        $this->assertCount(1, $kept);
        $this->assertSame($order->reference, $kept[0]['receipt']['reference']);
    }

    // --- fixtures -------------------------------------------------------------

    /** @param  array<string, mixed>  $values */
    private function set(array $values): void
    {
        foreach ($values as $key => $value) {
            PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    private function event(string $country, ?string $subdivision): Event
    {
        $organization = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights-'.Str::lower(Str::random(6))]);

        return Event::create([
            'organization_id' => $organization->id,
            'slug' => 'night-'.Str::lower(Str::random(8)),
            'title' => 'Afro Fest',
            'currency' => $country === 'NG' ? 'NGN' : 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => $country === 'NG' ? 'Africa/Lagos' : 'America/Toronto',
            'city' => $country === 'NG' ? 'Lagos' : 'Montréal',
            'subdivision' => $subdivision,
            'country' => $country,
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    private function type(Event $event, int $price): TicketType
    {
        return TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => $price,
            'status' => 'on_sale',
        ]);
    }

    private function sell(Event $event, int $price, int $quantity = 1, ?string $gateway = null): Order
    {
        $type = $this->type($event, $price);

        $order = app(CheckoutService::class)->reserve($event, [$type->id => $quantity], 'buyer@example.com', 'Ada Buyer');

        if ($gateway !== null) {
            $order->forceFill(['gateway' => $gateway, 'gateway_reference' => 'pi_'.Str::random(8)])->save();
        }

        return app(Fulfiller::class)->fulfil($order)->fresh();
    }

    /**
     * The two sums every order is held to.
     *
     * The database checks the first on every write; asserted here as well so
     * a failure names the order rather than a constraint.
     */
    private function assertBalances(Order $order): void
    {
        $this->assertSame('paid', $order->status);
        $this->assertSame(
            $order->total_amount,
            $order->net_revenue_amount + $order->tax_amount + $order->service_charge_amount,
        );

        $ticketTax = array_sum(array_map(fn (array $l) => $l['on'] === 'tickets' ? $l['amount'] : 0, $order->tax_lines));
        $chargeTax = array_sum(array_map(fn (array $l) => $l['on'] === 'service_charge' ? $l['amount'] : 0, $order->tax_lines));
        $this->assertSame($order->tax_amount, $ticketTax);
        $this->assertSame($order->service_charge_tax_amount, $chargeTax);

        $ledger = LedgerEntry::where('order_id', $order->id);
        $this->assertSame($order->net_revenue_amount, (int) $ledger->sum('amount'), 'The organizer is owed exactly their net revenue.');
        $this->assertEqualsCanonicalizing(['sale', 'tax'], LedgerEntry::where('order_id', $order->id)->pluck('type')->all());
    }

    private function staff(PlatformRole $role): User
    {
        return User::factory()->create(['platform_role' => $role, 'email_verified_at' => now()]);
    }

    private function launchRatesMigration(): object
    {
        return require database_path('migrations/2026_09_27_020200_the_launch_tax_rates.php');
    }

    private function fakeProcessor(): void
    {
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('name')->andReturn('stripe');
        $gateway->shouldReceive('supports')->andReturn(true);
        $gateway->shouldReceive('createCheckout')->andReturnUsing(
            fn () => new CheckoutSession('cs_test_'.Str::random(8), 'https://checkout.example/session', null),
        );
        $gateway->shouldReceive('refund')->andReturnUsing(
            fn (Order $order, int $amount) => new RefundResult(true, 're_'.Str::random(8), $amount, $order->currency),
        );

        $registry = new PaymentGatewayRegistry;
        $registry->register($gateway);
        $this->app->instance(PaymentGatewayRegistry::class, $registry);
    }
}
