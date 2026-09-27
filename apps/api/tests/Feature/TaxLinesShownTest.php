<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Models\TaxRate;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use App\Services\Integrations\Payloads;
use Database\Seeders\TaxRateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Each tax, and the tax in the service charge, wherever an organizer or
 * staff read an order's money: the spreadsheet, the webhook, the admin's
 * order page.
 *
 * The receipt already showed them. Everywhere else an order's taxes were one
 * figure and its service charge had its tax hidden inside, so an organizer in
 * Quebec could not tell GST from QST in their own export — and they are filed
 * separately. Every sale goes through checkout for real, so the figures are
 * the ones a buyer was charged.
 */
class TaxLinesShownTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TaxRateSeeder::class);
        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
    }

    // --- the organizer's spreadsheet --------------------------------------------

    public function test_the_export_adds_each_tax_after_the_columns_it_already_had(): void
    {
        $this->set(['qst_enabled' => true, 'tax_on_service_charge' => true]);
        $this->sell($this->night('CA', 'QC'), 10000, 2);
        $this->signedInAs(Role::Owner);

        [$header, $row] = $this->exported();

        // Everything that was there is where it was, so a sheet built on the
        // old columns still lines up.
        $this->assertSame(
            ['Reference', 'Paid at', 'Time zone', 'Event', 'Buyer', 'Email', 'Status', 'Tickets', 'Currency', 'Subtotal', 'Discount', 'Tax', 'Service charge', 'Total paid', 'Refunded', 'Owed to organizer'],
            array_slice($header, 0, 16),
        );
        $this->assertSame(['Tax in service charge', 'Tax lines', 'Seller of record'], array_slice($header, 16));

        $row = array_combine($header, $row);

        // 5% and 9.975% of 200.00 on the tickets; of the 16.00 service
        // charge, 0.80 and 1.60 more.
        $this->assertSame('29.95', $row['Tax']);
        $this->assertSame('18.40', $row['Service charge']);
        $this->assertSame('2.40', $row['Tax in service charge']);
        $this->assertSame(
            'GST 5%: 10.00; QST 9.975%: 19.95; GST 5% on the service charge: 0.80; QST 9.975% on the service charge: 1.60',
            $row['Tax lines'],
        );
        $this->assertSame('organizer', $row['Seller of record']);

        // The tax in the service charge is inside it, not beside it: the old
        // columns still add up to what was paid.
        $this->assertSame('248.35', $row['Total paid']);
        $this->assertEqualsWithDelta(200.00 + 29.95 + 18.40, (float) $row['Total paid'], 0.001);
    }

    public function test_naira_say_their_vat_was_included(): void
    {
        $this->set(['tax_on_service_charge' => true, 'seller_of_record' => 'platform']);
        $this->sell($this->night('NG', null), 107500, 2);
        $this->signedInAs(Role::Owner);

        [$header, $row] = $this->exported();
        $row = array_combine($header, $row);

        $this->assertSame('VAT 7.5%, included: 150.00; VAT 7.5% on the service charge, included: 11.16', $row['Tax lines']);
        $this->assertSame('11.16', $row['Tax in service charge']);
        $this->assertSame('platform', $row['Seller of record']);
    }

    public function test_an_order_from_before_orders_kept_their_taxes_is_exported_as_it_was_made(): void
    {
        $this->legacyOrder();
        $this->signedInAs(Role::Owner);

        [$header, $row] = $this->exported();
        $row = array_combine($header, $row);

        $this->assertSame('HST 13%: 13.00', $row['Tax lines']);
        $this->assertSame('0.00', $row['Tax in service charge']);
        $this->assertSame('organizer', $row['Seller of record']);
    }

    // --- the webhook and the key-read API ------------------------------------------

    public function test_the_order_payload_gains_each_tax_and_keeps_every_figure_it_had(): void
    {
        $this->set(['qst_enabled' => true, 'tax_on_service_charge' => true]);
        $order = $this->sell($this->night('CA', 'QC'), 10000, 2);

        $payload = app(Payloads::class)->order($order);

        // What an integration already reads, unchanged.
        $this->assertSame(['amount' => 2995, 'currency' => 'CAD'], $payload['tax']);
        $this->assertSame(['amount' => 1840, 'currency' => 'CAD'], $payload['service_charge']);
        $this->assertSame(['amount' => 24835, 'currency' => 'CAD'], $payload['total']);

        $this->assertSame(['amount' => 240, 'currency' => 'CAD'], $payload['service_charge_tax']);
        $this->assertSame('organizer', $payload['seller_of_record']);
        $this->assertSame([
            ['name' => 'GST', 'rate' => '5', 'on' => 'tickets', 'included' => false, 'amount' => ['amount' => 1000, 'currency' => 'CAD']],
            ['name' => 'QST', 'rate' => '9.975', 'on' => 'tickets', 'included' => false, 'amount' => ['amount' => 1995, 'currency' => 'CAD']],
            ['name' => 'GST', 'rate' => '5', 'on' => 'service_charge', 'included' => false, 'amount' => ['amount' => 80, 'currency' => 'CAD']],
            ['name' => 'QST', 'rate' => '9.975', 'on' => 'service_charge', 'included' => false, 'amount' => ['amount' => 160, 'currency' => 'CAD']],
        ], $payload['tax_lines']);
    }

    public function test_an_older_orders_payload_names_the_one_tax_it_had(): void
    {
        $payload = app(Payloads::class)->order($this->legacyOrder());

        $this->assertSame([
            ['name' => 'HST', 'rate' => '13', 'on' => 'tickets', 'included' => false, 'amount' => ['amount' => 1300, 'currency' => 'CAD']],
        ], $payload['tax_lines']);
        $this->assertSame(['amount' => 0, 'currency' => 'CAD'], $payload['service_charge_tax']);
        $this->assertSame('organizer', $payload['seller_of_record']);
    }

    // --- the admin's order page ------------------------------------------------------

    public function test_the_admin_order_page_shows_each_tax_and_the_tax_in_the_service_charge(): void
    {
        $this->set(['qst_enabled' => true, 'tax_on_service_charge' => true, 'seller_of_record' => 'platform']);
        $order = $this->sell($this->night('CA', 'QC'), 10000, 2);

        $this->actingAs(User::factory()->create(['platform_role' => PlatformRole::Support, 'email_verified_at' => now()]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee('$29.95')
            ->assertSee('$18.40')
            ->assertSee('Includes $2.40 of tax')
            ->assertSee('GST 5% on the tickets: $10.00')
            ->assertSee('QST 9.975% on the tickets: $19.95')
            ->assertSee('GST 5% on the service charge: $0.80')
            ->assertSee('QST 9.975% on the service charge: $1.60')
            ->assertSee('The platform');
    }

    public function test_naira_on_the_admin_order_page_are_whole_where_there_are_no_kobo(): void
    {
        $this->set(['tax_on_service_charge' => true]);
        $order = $this->sell($this->night('NG', null), 107500, 2);

        $this->actingAs(User::factory()->create(['platform_role' => PlatformRole::Support, 'email_verified_at' => now()]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee('VAT 7.5% on the tickets, included: ₦150')
            ->assertDontSee('₦150.00')
            ->assertSee('VAT 7.5% on the service charge, included: ₦11.16')
            ->assertSee('Includes ₦11.16 of tax')
            ->assertSee('The organizer');
    }

    public function test_free_tickets_show_no_tax_rather_than_a_tax_of_nothing(): void
    {
        // Ontario's HST is kept on the order at $0.00 against free tickets.
        $order = $this->sell($this->night('CA', 'ON'), 0, 2);
        $this->assertSame(0, (int) $order->tax_amount);
        $this->assertCount(1, $order->tax_lines);

        $this->actingAs(User::factory()->create(['platform_role' => PlatformRole::Support, 'email_verified_at' => now()]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee('No tax on this order')
            ->assertDontSee('HST 13% on the tickets');
    }

    // --- fixtures -------------------------------------------------------------------

    /** @param  array<string, mixed>  $values */
    private function set(array $values): void
    {
        foreach ($values as $key => $value) {
            PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    private function night(string $country, ?string $subdivision): Event
    {
        return Event::create([
            'organization_id' => $this->org->id,
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

    private function sell(Event $event, int $price, int $quantity): Order
    {
        $type = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => $price,
            'status' => 'on_sale',
        ]);

        $order = app(CheckoutService::class)->reserve($event, [$type->id => $quantity], 'buyer@example.com', 'Ada Buyer');

        return app(Fulfiller::class)->fulfil($order)->fresh();
    }

    /** One tax, pointed at by its rate, and no copy of how it was taxed: as orders were. */
    private function legacyOrder(): Order
    {
        $event = $this->night('CA', 'ON');

        return Order::create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => 10000,
            'discount_amount' => 0,
            'tax_amount' => 1300,
            'net_revenue_amount' => 10000,
            'service_charge_amount' => 800,
            'total_amount' => 12100,
            'tax_rate_id' => TaxRate::resolve('CA', 'ON')->id,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    private function signedInAs(Role $role): void
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
    }

    /** @return array{0: list<string>, 1: list<string>} the header and the one order */
    private function exported(): array
    {
        $body = $this->get('/api/organizer/orders/export')->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', array_values(array_filter(preg_split("/\r?\n/", substr($body, 3)))));

        $this->assertCount(2, $rows);

        return [$rows[0], $rows[1]];
    }
}
