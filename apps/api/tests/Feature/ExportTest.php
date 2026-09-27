<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Orders and guest lists, downloaded as spreadsheets.
 *
 * What these pin is what makes an export safe to hand over: it holds every
 * row the filter matches and nothing from another organization; text typed
 * by a stranger cannot run as a formula when the file is opened; a guest list
 * is not a book of working ticket codes; and every bulk export is on record.
 */
class ExportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afrobeats-rooftop',
            'title' => 'Afrobeats Rooftop',
            'currency' => 'CAD',
            'starts_at' => now()->addWeek(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);
        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
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

    private function order(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => 5000,
            'discount_amount' => 0,
            'tax_amount' => 650,
            'net_revenue_amount' => 5000,
            'service_charge_amount' => 400,
            'total_amount' => 6050,
            'gateway' => 'stripe',
            'gateway_reference' => 'pi_'.Str::random(6),
            'status' => 'paid',
            'paid_at' => now(),
        ], $attributes));
    }

    private function ticket(array $attributes = []): Ticket
    {
        return Ticket::create(array_merge([
            'event_id' => $this->event->id,
            'ticket_type_id' => $this->type->id,
            'code' => 'WFY7-'.strtoupper(Str::random(8)),
            'owner_email' => 'guest@example.com',
            'holder_name' => 'Guest',
            'status' => 'valid',
            'admits' => 1,
            'admitted_count' => 0,
        ], $attributes));
    }

    /** @return array{0: string, 1: list<list<string>>} */
    private function csv(TestResponse $response): array
    {
        $body = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(preg_split("/\r?\n/", substr($body, 3))));

        return [$body, $rows];
    }

    // --- orders --------------------------------------------------------------

    public function test_the_export_holds_every_matching_order_not_one_page(): void
    {
        $this->signedInAs(Role::Owner);

        foreach (range(1, 30) as $i) {
            $this->order();
        }

        [, $rows] = $this->csv($this->get('/api/organizer/orders/export')->assertOk());

        // The screen pages at twenty-five. An export of twenty-five rows is
        // not what anybody asking for an export means.
        $this->assertCount(31, $rows);
        $this->assertSame('Reference', $rows[0][0]);
    }

    public function test_it_is_the_same_filter_as_the_screen(): void
    {
        $this->signedInAs(Role::Owner);

        $this->order(['buyer_name' => 'Chidi Okeke']);
        $this->order(['buyer_name' => 'Ada Okafor']);

        [, $rows] = $this->csv($this->get('/api/organizer/orders/export?q=chidi')->assertOk());

        $this->assertCount(2, $rows);
        $this->assertSame('Chidi Okeke', $rows[1][4]);
    }

    public function test_a_buyer_cannot_put_a_formula_in_the_organizers_spreadsheet(): void
    {
        $this->signedInAs(Role::Owner);

        $this->order([
            'buyer_name' => '=HYPERLINK("https://evil.example","Refund here")',
            'buyer_email' => '+1@example.com',
        ]);

        [, $rows] = $this->csv($this->get('/api/organizer/orders/export')->assertOk());

        // A leading quote makes a spreadsheet show the text instead of running it.
        $this->assertStringStartsWith("'=HYPERLINK", $rows[1][4]);
        $this->assertStringStartsWith("'+1@", $rows[1][5]);
    }

    public function test_money_columns_stay_numbers_an_accountant_can_sum(): void
    {
        $this->signedInAs(Role::Owner);
        $this->order();

        [, $rows] = $this->csv($this->get('/api/organizer/orders/export')->assertOk());
        $row = array_combine($rows[0], $rows[1]);

        $this->assertSame('50.00', $row['Subtotal']);
        $this->assertSame('6.50', $row['Tax']);
        $this->assertSame('4.00', $row['Service charge']);
        $this->assertSame('60.50', $row['Total paid']);
        $this->assertSame('50.00', $row['Owed to organizer']);
    }

    public function test_times_are_the_events_own_evening_with_the_zone_beside_them(): void
    {
        $this->signedInAs(Role::Owner);

        // 02:00 UTC is ten the evening before in Toronto — the night being
        // reconciled, not the next morning.
        $this->order(['paid_at' => '2026-09-12T02:00:00Z']);

        [, $rows] = $this->csv($this->get('/api/organizer/orders/export')->assertOk());
        $row = array_combine($rows[0], $rows[1]);

        $this->assertSame('2026-09-11 22:00', $row['Paid at']);
        $this->assertSame('America/Toronto', $row['Time zone']);
    }

    public function test_the_file_opens_correctly_and_is_never_cached(): void
    {
        $this->signedInAs(Role::Owner);
        $this->order(['buyer_name' => 'Adébáyọ̀ Québec']);

        $response = $this->get('/api/organizer/orders/export')->assertOk();
        [$body, $rows] = $this->csv($response);

        // The byte-order mark is what stops Excel reading accents as mojibake.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertSame('Adébáyọ̀ Québec', $rows[1][4]);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_another_organizations_orders_never_appear(): void
    {
        $this->signedInAs(Role::Owner);

        $stranger = Organization::create(['name' => 'Other Co', 'slug' => 'other-co']);
        $theirs = Event::create([
            'organization_id' => $stranger->id, 'slug' => 'theirs', 'title' => 'Theirs', 'currency' => 'CAD',
            'starts_at' => now()->addWeek(), 'timezone' => 'America/Toronto', 'city' => 'Toronto',
            'country' => 'CA', 'status' => 'published',
        ]);
        $this->order(['organization_id' => $stranger->id, 'event_id' => $theirs->id, 'reference' => 'STRANGER01']);
        $this->order();

        [$body, $rows] = $this->csv($this->get('/api/organizer/orders/export')->assertOk());

        $this->assertCount(2, $rows);
        $this->assertStringNotContainsString('STRANGER01', $body);
    }

    public function test_marketing_cannot_export_orders(): void
    {
        $this->signedInAs(Role::Marketing);
        $this->order();

        $this->get('/api/organizer/orders/export')->assertForbidden();
    }

    public function test_an_order_export_is_on_record(): void
    {
        $user = $this->signedInAs(Role::Owner);
        $this->order();
        $this->order();

        $this->get('/api/organizer/orders/export?status=paid')->assertOk();

        $log = AuditLog::where('action', 'orders.exported')->sole();

        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame(2, $log->metadata['count']);
        $this->assertSame('paid', $log->metadata['filters']['status']);
    }

    // --- guests --------------------------------------------------------------

    public function test_the_guest_list_holds_everybody_still_coming_and_no_ticket_codes(): void
    {
        $this->signedInAs(Role::Owner);

        $coming = $this->ticket(['holder_name' => 'Bisi Ade']);
        $arrived = $this->ticket(['holder_name' => 'Ada Okafor', 'status' => 'checked_in', 'admitted_count' => 1, 'checked_in_at' => now()]);
        $refunded = $this->ticket(['holder_name' => 'Refunded Person', 'status' => 'refunded']);

        [$body, $rows] = $this->csv($this->get("/api/organizer/events/{$this->event->id}/guests/export")->assertOk());

        $names = array_column(array_slice($rows, 1), 0);

        $this->assertSame(['Ada Okafor', 'Bisi Ade'], $names);

        // A printed or forwarded guest list must not be a set of working tickets.
        foreach ([$coming, $arrived, $refunded] as $ticket) {
            $this->assertStringNotContainsString($ticket->code, $body);
        }

        $ada = array_combine($rows[0], $rows[1]);
        $this->assertSame('Arrived', $ada['Status']);
    }

    public function test_door_staff_cannot_take_the_guest_list_away(): void
    {
        $this->signedInAs(Role::Door);
        $this->ticket();

        // Scanning a door does not carry the right to read who is coming.
        $this->get("/api/organizer/events/{$this->event->id}/guests/export")->assertForbidden();
    }

    public function test_a_guest_export_is_on_record(): void
    {
        $this->signedInAs(Role::Manager);
        $this->ticket();

        $this->get("/api/organizer/events/{$this->event->id}/guests/export")->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'guests.exported')->sole()->metadata['count']);
    }
}
