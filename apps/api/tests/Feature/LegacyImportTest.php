<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Event;
use App\Models\EventReview;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Venue;
use App\Services\Events\EventSnapshot;
use App\Services\Legacy\LegacyImporter;
use App\Services\Legacy\LegacyMap;
use App\Services\Refunds\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * The import, run end to end against the old schema (see LegacyFixtures).
 */
class LegacyImportTest extends TestCase
{
    use LegacyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLegacyDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownLegacyDatabase();

        parent::tearDown();
    }

    private function import(): array
    {
        $map = new LegacyMap;
        $map->warm();

        return (new LegacyImporter($map))->run();
    }

    public function test_an_organizer_account_becomes_a_user_and_an_organization_that_owns_it(): void
    {
        $this->import();

        $user = User::where('email', 'ada@lagosnights.test')->firstOrFail();
        // The account's own address, not the organization's: the brand has a
        // public one of its own and they are different things.
        $organization = Organization::where('name', 'Lagos Nights')->firstOrFail();

        $this->assertSame('Ada Okoro', $user->name);
        // The old system had no organizations. One is derived per organizer,
        // which is the shape it already had.
        $this->assertTrue($organization->members()->where('users.id', $user->id)->exists());
        $this->assertSame(Role::Owner, $organization->members()->first()->pivot->role);
    }

    public function test_a_bcrypt_password_survives_and_a_sha1_one_does_not(): void
    {
        $this->import();

        $kept = User::where('email', 'ada@lagosnights.test')->firstOrFail();
        $reset = User::where('email', 'bem@example.test')->firstOrFail();

        // The thing that actually matters: this person signs in on the morning
        // of the cutover with the password they already had.
        $this->assertTrue(Hash::check(self::KNOWN_PASSWORD, $kept->password));

        // And this one does not. Not the SHA-1, not empty either — a value
        // nobody knows, so the account exists and the only way into it is a
        // reset.
        $this->assertNotSame('356a192b7913b04c54574d18c28d46e6395428ab', $reset->password);
        $this->assertNotEmpty($reset->password);
        $this->assertFalse(Hash::check('hunter2', $reset->password));
    }

    public function test_an_event_gets_the_end_time_and_timezone_the_source_never_had(): void
    {
        $this->import();

        $event = Event::where('title', 'Standard Night')->firstOrFail();

        $this->assertSame('America/Toronto', $event->timezone);
        $this->assertSame('2024-05-11 22:00', $event->starts_at->setTimezone($event->timezone)->format('Y-m-d H:i'));
        $this->assertSame('2024-05-12 04:00', $event->ends_at->setTimezone($event->timezone)->format('Y-m-d H:i'));
        $this->assertSame('published', $event->status);

        // Both guesses are on the record rather than indistinguishable from
        // data somebody actually entered.
        $inferred = DB::table('legacy_map')
            ->where('source_table', 'events')->where('source_id', '176')
            ->value('inferred');

        $this->assertStringContainsString('ends_at', (string) $inferred);
        $this->assertStringContainsString('timezone', (string) $inferred);
    }

    public function test_an_event_is_never_given_a_link_the_sites_own_pages_answer(): void
    {
        // Event pages live at the root, beside the site's own. An old event
        // called "Events", and one whose old slug was "refunds", would each be
        // given a link that opens the site's page instead of theirs.
        DB::connection('legacy')->table('events')->insert([
            [
                'id' => 178, '_organizer' => '26', '_title' => 'Events',
                '_category' => 'Nightlife', '_location' => 'Toronto',
                '_timezone' => null, '_province' => 'ON', '_venue' => 'The Room',
                '_description' => '', '_start_date' => '2024-07-01',
                '_start_time' => '22:00', '_slug' => null, '_dress_code' => null,
                '_identity_req' => false, '_status' => 'PUBLISHED', 'is_featured' => false,
                'created' => '2024-06-01 12:00:00',
            ],
            [
                'id' => 179, '_organizer' => '26', '_title' => 'Refund Party',
                '_category' => 'Nightlife', '_location' => 'Toronto',
                '_timezone' => null, '_province' => 'ON', '_venue' => 'The Room',
                '_description' => '', '_start_date' => '2024-07-02',
                '_start_time' => '22:00', '_slug' => 'Refunds', '_dress_code' => null,
                '_identity_req' => false, '_status' => 'PUBLISHED', 'is_featured' => false,
                'created' => '2024-06-01 12:00:00',
            ],
        ]);

        $this->import();

        $this->assertSame('events-2', Event::where('title', 'Events')->sole()->slug);
        $this->assertSame('refunds-2', Event::where('title', 'Refund Party')->sole()->slug);

        foreach (Event::query()->pluck('slug') as $slug) {
            $this->assertNotContains($slug, Event::RESERVED_SLUGS);
        }

        // Where it went, on the record beside why.
        $this->assertStringContainsString(
            "'refunds' is one of this site's own pages",
            (string) DB::table('legacy_map')->where('source_table', 'events')->where('source_id', '179')->value('inferred'),
        );

        // An ordinary title still gets its own words.
        $this->assertSame('standard-night', Event::where('title', 'Standard Night')->sole()->slug);
    }

    public function test_an_unrecognised_status_drafts_rather_than_publishes(): void
    {
        $this->import();

        $this->assertSame('draft', Event::where('title', 'Unknown State')->firstOrFail()->status);
    }

    public function test_an_event_on_sale_arrives_approved_as_it_stands(): void
    {
        $this->import();

        // It was on sale on the old platform. Taking it off to be looked at
        // again would punish the organizer for a rule that did not exist.
        $event = Event::where('title', 'Standard Night')->sole();
        $this->assertSame('published', $event->status);
        $this->assertNotNull($event->approved_at);
        $this->assertSame(EventSnapshot::fingerprint(EventSnapshot::of($event)), $event->approved_fingerprint);
        $this->assertSame(['imported'], EventReview::where('event_id', $event->id)->pluck('via')->all());

        // A draft arrives unapproved, and a second run approves nothing twice.
        $this->assertNull(Event::where('title', 'Unknown State')->sole()->approved_at);
        $this->import();
        $this->assertSame(1, EventReview::where('event_id', $event->id)->count());
    }

    public function test_the_two_money_units_are_kept_apart(): void
    {
        $this->import();

        // ticket_price 5 is five dollars.
        $this->assertSame(500, TicketType::where('name', 'Standard Ticket')->firstOrFail()->price_amount);

        // _cost 1500 is already fifteen dollars in cents. Multiplying it here
        // as well is the mistake that balances perfectly all the way through.
        $paid = Order::where('status', 'paid')->firstOrFail();
        $this->assertSame(1500, $paid->net_revenue_amount);
    }

    public function test_a_migrated_order_carries_the_fee_model_this_platform_now_runs(): void
    {
        $this->import();

        $order = Order::where('status', 'paid')->firstOrFail();

        $this->assertSame(1500, $order->net_revenue_amount, 'The organizer is owed the ticket price.');
        $this->assertSame(120, $order->service_charge_amount, '8% of 1500.');
        $this->assertSame(1620, $order->total_amount);

        // Recomputed, not carried. The source stored this as a generated
        // column that added 0.30 to an amount in cents.
        $this->assertSame(77, $order->gateway_fee_amount, '2.9% of 1620, plus thirty cents.');
    }

    public function test_the_buyers_real_email_and_name_survive(): void
    {
        $this->import();

        $order = Order::where('status', 'paid')->firstOrFail();

        // `_guest` is First|Last|email on every row in that table. Treating it
        // as an address and falling back to a placeholder lost the real one
        // for all 2,694 orders — and with it any chance of a buyer finding
        // their own ticket.
        $this->assertSame('buyer@example.test', $order->buyer_email);
        $this->assertSame('Ada Buyer', $order->buyer_name);
        $this->assertStringNotContainsString('imported.invalid', $order->buyer_email);
    }

    public function test_an_order_arrives_with_the_basket_that_was_bought(): void
    {
        $this->import();

        $order = Order::where('status', 'paid')->with('lines')->firstOrFail();

        $this->assertCount(1, $order->lines);

        $line = $order->lines->first();

        $this->assertSame(3, $line->quantity);
        $this->assertSame(500, $line->unit_price_amount);
        $this->assertSame(1500, $line->line_total_amount);
        $this->assertSame('Standard Ticket', $line->name);

        // The lines have to add up to what was charged, or refunds allocate
        // against weights that do not describe the order.
        $this->assertSame(
            $order->net_revenue_amount,
            (int) $order->lines->sum('line_total_amount'),
        );
    }

    public function test_a_migrated_order_can_actually_be_refunded(): void
    {
        $this->import();

        $order = Order::where('status', 'paid')->firstOrFail();

        // The reason order lines are not decoration. RefundService allocates
        // by line weight; with no lines every weight is zero and the refund
        // is refused as being worth nothing.
        $share = (new \ReflectionMethod(RefundService::class, 'shareFor'))
            ->invoke(app(RefundService::class), $order, $order->tickets);

        $this->assertGreaterThan(0, $share['amount']);
    }

    public function test_the_organization_is_named_for_the_brand_not_the_person(): void
    {
        $this->import();

        // Every poster said "Lagos Nights". The account is in somebody's own
        // name, and naming the organization after the account puts the wrong
        // one in front of buyers.
        $organization = Organization::where('name', 'Lagos Nights')->firstOrFail();

        $this->assertSame('Afrobeats, monthly.', $organization->description);
        $this->assertSame('hello@lagosnights.test', $organization->contact_email);
        $this->assertSame('lagos.nights', $organization->instagram);
        $this->assertNotNull($organization->logo_path, 'The logo is a data URI, not image bytes.');
    }

    public function test_bank_details_and_identity_documents_are_left_behind(): void
    {
        $this->import();

        // Organizers re-verify. Carrying payout details across without the
        // verification that releases them moves the liability without
        // bringing forward the moment anybody can be paid.
        $this->assertSame(0, DB::table('organization_payout_details')->count());
        $this->assertSame(0, DB::table('organization_identity_documents')->count());
    }

    public function test_the_venue_becomes_a_row_and_is_reused(): void
    {
        $this->import();

        $event = Event::where('title', 'Standard Night')->firstOrFail();

        $this->assertNotNull($event->venue_id);
        $this->assertSame('The Room', $event->venue->name);
    }

    public function test_an_abandoned_checkout_arrives_cancelled(): void
    {
        $this->import();

        $abandoned = Order::where('buyer_name', 'Someone Who Left')->firstOrFail();

        $this->assertSame('cancelled', $abandoned->status);
        $this->assertNull($abandoned->paid_at);
        // Cancelled orders write no ledger entries: nothing was ever collected.
        $this->assertSame(0, LedgerEntry::where('order_id', $abandoned->id)->count());
    }

    public function test_the_ledger_credits_the_organizer_the_whole_ticket_price(): void
    {
        $this->import();

        $order = Order::where('status', 'paid')->firstOrFail();

        $this->assertSame(
            1500,
            (int) LedgerEntry::where('order_id', $order->id)->sum('amount'),
            'The service charge was the buyer paying us, not a deduction from them.',
        );
    }

    public function test_issued_tickets_keep_the_codes_that_are_already_printed(): void
    {
        $this->import();

        $checkedIn = Ticket::where('code', 'MFST-9K2L4XQ7')->firstOrFail();
        $notYet = Ticket::where('code', 'MFST-3H8N2VRD')->firstOrFail();

        $this->assertSame('checked_in', $checkedIn->status);
        $this->assertSame('valid', $notYet->status);

        // The old system recorded that somebody came in and never when.
        $this->assertNull($checkedIn->checked_in_at);
    }

    public function test_a_settlement_leaves_the_balance_as_money_already_paid_out(): void
    {
        $this->import();

        $settlement = LedgerEntry::where('type', 'settlement')->firstOrFail();

        // 12.34 as a floating point double in the source, rounded on the way in.
        $this->assertSame(-1234, (int) $settlement->amount);
    }

    public function test_running_it_twice_does_not_import_anything_twice(): void
    {
        $this->import();

        $before = $this->everythingCounted();

        // A quarter of a gigabyte over a network does not finish first time.
        // The recovery has to be running it again.
        $this->import();

        $this->assertSame($before, $this->everythingCounted());
    }

    public function test_an_order_that_fails_part_way_leaves_nothing_behind_and_is_tried_again(): void
    {
        // The connection going between an order and its basket. Before each
        // row was one transaction, this left an order with no lines that
        // legacy_map called done, and the next run skipped it for good.
        $dropped = true;

        OrderLine::creating(function () use (&$dropped): void {
            if ($dropped) {
                throw new RuntimeException('server closed the connection unexpectedly');
            }
        });

        $result = $this->import();

        // Nothing of either order: its row, its lines, its ledger entry and
        // its map row all went back together. Sale 18 fails the same way —
        // it has a basket too — and says so.
        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderLine::count());
        $this->assertSame(0, LedgerEntry::whereNotNull('order_id')->count());
        $this->assertFalse(DB::table('legacy_map')->where('source_table', 'tickets_sales')->exists());

        // The tickets for it are skipped as missing rather than written
        // against an order the map remembered and the database did not.
        $this->assertSame(0, Ticket::count());
        $this->assertContains('ticket 900 skipped: order or event not imported', $result['notes']);

        // Written down, with the source row it came from and why.
        $failure = DB::table('legacy_import_failures')
            ->where('source_table', 'tickets_sales')->where('source_id', '17')
            ->first();

        $this->assertNotNull($failure);
        $this->assertStringContainsString('server closed the connection', $failure->reason);
        $this->assertNull($failure->resolved_at);
        $this->assertContains(
            ['table' => 'tickets_sales', 'id' => '17', 'reason' => $failure->reason],
            $result['failures'],
        );

        // The run carried on past it: settlements come after orders.
        $this->assertSame(1, LedgerEntry::where('type', 'settlement')->count());

        // The connection is back. The same command, run again.
        $dropped = false;

        $retry = $this->import();

        $order = Order::where('buyer_email', 'buyer@example.test')->with('lines')->firstOrFail();

        $this->assertSame([], $retry['failures']);
        $this->assertCount(1, $order->lines);
        $this->assertSame(1500, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
        $this->assertSame(2, Ticket::where('order_id', $order->id)->count());
        $this->assertSame(1, LedgerEntry::where('type', 'settlement')->count(), 'Not written a second time.');

        $this->assertNotNull(
            DB::table('legacy_import_failures')->where('source_id', '17')->value('resolved_at'),
            'Kept, and marked as having come across.',
        );
    }

    public function test_an_order_whose_ledger_entry_fails_takes_its_lines_back_with_it(): void
    {
        // The last write before the map row. Committed piecemeal, this was an
        // order and its basket that the organizer was never credited for.
        LedgerEntry::creating(function (LedgerEntry $entry): void {
            if ($entry->type === 'sale') {
                throw new RuntimeException('bad row');
            }
        });

        $result = $this->import();

        // The paid sale is gone whole. The abandoned one writes no ledger
        // entry, so it came across.
        $this->assertSame(['17'], array_column(
            array_filter($result['failures'], fn (array $f) => $f['table'] === 'tickets_sales'),
            'id',
        ));
        $this->assertFalse(Order::where('buyer_email', 'buyer@example.test')->exists());
        $this->assertSame(1, OrderLine::count(), 'Only the abandoned checkout\'s basket.');
        $this->assertNull(DB::table('legacy_map')->where('source_table', 'tickets_sales')->where('source_id', '17')->first());
        $this->assertSame(1, DB::table('legacy_import_failures')->whereNull('resolved_at')->where('source_id', '17')->count());
    }

    public function test_bad_data_in_one_row_does_not_stop_the_rest(): void
    {
        // The same person twice, as the old system allowed: its email column
        // had no unique index. The second cannot become a user here, and it
        // must not take anybody else down with it.
        DB::connection('legacy')->table('user_accounts')->insert([
            'id' => 32, 'first_name' => 'Ada', 'last_name' => 'Again',
            'email_address' => 'ADA@lagosnights.test', 'phone_number' => null,
            'password' => password_hash('other', PASSWORD_BCRYPT),
            '_registered' => '2024-02-01 10:00:00',
        ]);

        $result = $this->import();

        $this->assertCount(1, $result['failures']);
        $this->assertSame('user_accounts', $result['failures'][0]['table']);
        $this->assertSame('32', $result['failures'][0]['id']);

        // The constraint, not the row: Postgres quotes the offending value
        // on the line after, and that line is not kept.
        $this->assertStringContainsString('users_email_unique', $result['failures'][0]['reason']);
        $this->assertStringNotContainsString('lagosnights', $result['failures'][0]['reason']);

        // No half of it: no second organization with no owner, no logo, no
        // map row saying it came across.
        $this->assertSame(2, Organization::count());
        $this->assertSame(1, User::where('email', 'ada@lagosnights.test')->count());
        $this->assertNull(DB::table('legacy_map')->where('source_table', 'organization_for_account')->where('source_id', '32')->first());

        // Everything else is here.
        $this->assertSame(2, Event::count());
        $this->assertSame(2, Order::count());
        $this->assertSame(2, Ticket::count());
    }

    public function test_a_failed_row_does_not_leave_its_venue_or_its_count_behind(): void
    {
        // An event that cannot be written after its venue already was.
        Event::creating(function (Event $event): void {
            if ($event->title === 'Standard Night') {
                throw new RuntimeException('bad row');
            }
        });

        $result = $this->import();

        $this->assertSame(0, Venue::count(), 'The venue went back with its event.');
        $this->assertArrayNotHasKey('venues', $result['counts']);
        $this->assertSame(1, Event::count());
    }

    public function test_an_order_waits_for_a_ticket_type_that_failed_rather_than_losing_its_basket(): void
    {
        // Written around, the paid order committed with no lines, the map
        // called it done, and the run that brought its ticket type across
        // passed it by: no basket for good, and nothing outstanding.
        $failing = true;

        TicketType::creating(function () use (&$failing): void {
            if ($failing) {
                throw new RuntimeException('bad row');
            }
        });

        $result = $this->import();

        $this->assertEqualsCanonicalizing(
            ['event_tickets:58', 'tickets_sales:17', 'tickets_sales:18'],
            array_map(fn (array $f) => $f['table'].':'.$f['id'], $result['failures']),
        );
        $this->assertSame(0, Order::count());
        $this->assertStringContainsString(
            'waiting for ticket type 58',
            (string) DB::table('legacy_import_failures')->where('source_table', 'tickets_sales')->where('source_id', '17')->value('reason'),
        );
        $this->assertNotContains('order 17 imported with no lines at all', $result['notes']);

        $failing = false;

        $retry = $this->import();

        $this->assertSame([], $retry['failures']);
        $this->assertSame([], (new LegacyMap)->outstandingFailures());

        $order = Order::where('buyer_email', 'buyer@example.test')->with('lines')->firstOrFail();

        $this->assertCount(1, $order->lines);
        $this->assertSame(1500, (int) $order->lines->sum('line_total_amount'));
        $this->assertSame(1500, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
        $this->assertSame(2, Ticket::where('order_id', $order->id)->count());
    }

    public function test_a_settlement_waits_for_an_event_that_failed_rather_than_belonging_to_none(): void
    {
        // A settlement for an event the old database never had is kept as it
        // was, under no event. Only one still to come is waited for.
        DB::connection('legacy')->table('settlements')->insert([
            'id' => 41, 'event' => '999', 'organizer' => '26', 'amount' => 5.0,
            'type' => 'full', 'note' => null, 'status' => 'success', 'date' => '2024-06-01 10:00:00',
        ]);

        $failing = true;

        Event::creating(function (Event $event) use (&$failing): void {
            if ($failing && $event->title === 'Standard Night') {
                throw new RuntimeException('bad row');
            }
        });

        $result = $this->import();

        $this->assertContains('settlements:40', array_map(fn (array $f) => $f['table'].':'.$f['id'], $result['failures']));
        $this->assertSame(0, LedgerEntry::where('type', 'settlement')->where('amount', -1234)->count());

        $orphan = LedgerEntry::where('type', 'settlement')->where('amount', -500)->sole();
        $this->assertNull($orphan->event_id);
        $this->assertContains('settlement 41: event 999 is not in the old database, kept without it', $result['notes']);

        $failing = false;

        $retry = $this->import();

        $this->assertSame([], $retry['failures']);
        $this->assertSame([], (new LegacyMap)->outstandingFailures());

        $settlement = LedgerEntry::where('type', 'settlement')->where('amount', -1234)->sole();

        $this->assertSame(Event::where('title', 'Standard Night')->firstOrFail()->id, $settlement->event_id);
    }

    public function test_a_row_can_be_left_behind_on_purpose_and_stops_being_listed(): void
    {
        // The same person twice. The fix is to not bring the second one, and
        // until there was a way to say so the import exited non-zero for good.
        DB::connection('legacy')->table('user_accounts')->insert([
            'id' => 32, 'first_name' => 'Ada', 'last_name' => 'Again',
            'email_address' => 'ADA@lagosnights.test', 'phone_number' => null,
            'password' => password_hash('other', PASSWORD_BCRYPT),
            '_registered' => '2024-02-01 10:00:00',
        ]);

        $this->artisan('legacy:import')->assertFailed();

        // Not without a reason, and not a row that is not on the list.
        $this->artisan('legacy:import', ['--leave-behind' => ['user_accounts:32']])->assertFailed();
        $this->artisan('legacy:import', ['--leave-behind' => ['user_accounts:33'], '--because' => 'typo'])->assertFailed();
        $this->assertNull(DB::table('legacy_import_failures')->where('source_id', '32')->value('resolved_at'));

        $this->artisan('legacy:import', ['--leave-behind' => ['user_accounts:32'], '--because' => 'duplicate of account 26'])
            ->expectsOutputToContain('Nothing else is outstanding')
            ->assertSuccessful();

        // Still in the source and still tried, in case it was fixed. It was
        // not, and it stays where it was put.
        $this->artisan('legacy:import')->assertSuccessful();

        $failure = DB::table('legacy_import_failures')->where('source_id', '32')->first();

        $this->assertNotNull($failure->resolved_at);
        $this->assertSame('duplicate of account 26', $failure->left_behind_because);
        $this->assertSame(2, (int) $failure->attempts);

        // Fixed after all: it comes across, and the record says that instead.
        DB::connection('legacy')->table('user_accounts')->where('id', 32)->update(['email_address' => 'ada.again@example.test']);

        $this->artisan('legacy:import')->assertSuccessful();

        $this->assertNull(DB::table('legacy_import_failures')->where('source_id', '32')->value('left_behind_because'));
        $this->assertTrue(User::where('email', 'ada.again@example.test')->exists());
    }

    public function test_a_failure_for_a_row_that_did_come_across_is_cleared(): void
    {
        $this->import();

        // The COMMIT reached the server and its answer was lost with the
        // connection: the row is across, and the run wrote it down as failed.
        // Nothing would try it again, so nothing would clear it.
        DB::table('legacy_import_failures')->insert([
            'source_table' => 'tickets_sales', 'source_id' => '17',
            'reason' => 'server closed the connection unexpectedly', 'attempts' => 1,
            'first_failed_at' => now(), 'last_failed_at' => now(),
        ]);

        $this->artisan('legacy:import')->assertSuccessful();

        $this->assertNotNull(DB::table('legacy_import_failures')->where('source_id', '17')->value('resolved_at'));
    }

    // --- a parallel run: the same database, imported again and again -------

    public function test_a_second_run_brings_only_the_rows_that_are_new(): void
    {
        $this->import();

        // The old app kept selling after the first run: one more sale, and
        // the ticket it issued.
        $this->legacySale(19, 1000, 'cs_live_new19');
        DB::connection('legacy')->table('ticket_issued')->insert([
            'ticket_id' => 902, 'event' => '176', 'ticket' => 'MFST-7Q4M2ZPX',
            '_type' => '58', '_sale' => '19', '_guest' => 'buyer19@example.test',
            '_custom_name' => '', '_date' => '2024-05-11 19:00:01', 'is_checkedin' => false,
        ]);

        $before = $this->everythingCounted();
        $result = $this->import();

        $this->assertSame(['across' => 2, 'new' => 1], $result['plan']['tickets_sales']);
        $this->assertSame(['across' => 2, 'new' => 1], $result['plan']['ticket_issued']);
        $this->assertSame(['across' => 2], $result['plan']['user_accounts']);
        $this->assertSame(['across' => 1], $result['plan']['settlements']);

        // One order, its line, its ledger entry, its ticket and their two map
        // rows. Nothing else, and nothing twice.
        [$users, $organizations, $events, $venues, $types, $orders, $lines, $tickets, $ledger, $map] = $before;
        $this->assertSame(
            [$users, $organizations, $events, $venues, $types, $orders + 1, $lines + 1, $tickets + 1, $ledger + 1, $map + 2],
            $this->everythingCounted(),
        );
        $this->assertSame(1, Order::where('gateway_reference', 'cs_live_new19')->count());
        $this->assertSame([], $result['changed']);
    }

    public function test_a_dry_run_writes_nothing_and_says_what_a_run_would_do(): void
    {
        // A duplicate account, so there is a failure for the dry run to count.
        DB::connection('legacy')->table('user_accounts')->insert([
            'id' => 32, 'first_name' => 'Ada', 'last_name' => 'Again',
            'email_address' => 'ADA@lagosnights.test', 'phone_number' => null,
            'password' => password_hash('other', PASSWORD_BCRYPT),
            '_registered' => '2024-02-01 10:00:00',
        ]);

        $map = new LegacyMap;
        $map->warm(write: false);
        $first = (new LegacyImporter($map, dryRun: true))->run();

        // Every row counted as one a run would bring, the ones hanging off new
        // parents included, and nothing written anywhere.
        $this->assertSame(['new' => 3], $first['plan']['user_accounts']);
        $this->assertSame(['new' => 2], $first['plan']['events']);
        $this->assertSame(['new' => 1], $first['plan']['event_tickets']);
        $this->assertSame(['new' => 2], $first['plan']['tickets_sales']);
        $this->assertSame(['new' => 2], $first['plan']['ticket_issued']);
        $this->assertSame(['new' => 1], $first['plan']['settlements']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0], $this->everythingCounted());
        $this->assertSame(0, DB::table('legacy_import_failures')->count());
        $this->assertSame(0, EventReview::count());

        // The real run, which fails the duplicate, then a new sale.
        $this->import();
        $this->legacySale(19, 1000, 'cs_live_new19');

        $before = $this->everythingCounted();
        $failure = DB::table('legacy_import_failures')->where('source_id', '32')->first();

        $this->artisan('legacy:import', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('would retry')
            ->assertSuccessful();

        $map = new LegacyMap;
        $map->warm(write: false);
        $second = (new LegacyImporter($map, dryRun: true))->run();

        $this->assertSame(['across' => 2, 'retried' => 1], $second['plan']['user_accounts']);
        $this->assertSame(['across' => 2, 'new' => 1], $second['plan']['tickets_sales']);
        $this->assertSame(['across' => 2], $second['plan']['ticket_issued']);

        // Still nothing written: not the sale, not another attempt at the
        // failure, not the failure cleared.
        $this->assertSame($before, $this->everythingCounted());
        $this->assertEquals($failure, DB::table('legacy_import_failures')->where('source_id', '32')->first());
    }

    public function test_a_real_run_counts_each_row_once_whatever_became_of_it(): void
    {
        // The duplicate account again. It used to be counted as new and as
        // failed both, so the summary said three new accounts and one failure
        // for three read, of which two came across.
        DB::connection('legacy')->table('user_accounts')->insert([
            'id' => 32, 'first_name' => 'Ada', 'last_name' => 'Again',
            'email_address' => 'ADA@lagosnights.test', 'phone_number' => null,
            'password' => password_hash('other', PASSWORD_BCRYPT),
            '_registered' => '2024-02-01 10:00:00',
        ]);

        $first = $this->import();

        $this->assertSame(['new' => 2, 'failed' => 1], $first['plan']['user_accounts']);
        $this->assertSame(3, $first['counts']['user_accounts']);
        $this->assertSame(2, User::count());

        // Tried again and failed again: a failure, not a row tried again.
        $second = $this->import();

        $this->assertSame(['across' => 2, 'failed' => 1], $second['plan']['user_accounts']);

        // Fixed in the old app: it comes across, as a row tried again.
        DB::connection('legacy')->table('user_accounts')->where('id', 32)->update(['email_address' => 'ada.again@example.test']);

        $third = $this->import();

        $this->assertSame(['across' => 2, 'retried' => 1], $third['plan']['user_accounts']);
        $this->assertSame([], $third['failures']);

        // Every row read is in exactly one column, in every table, on every
        // run: `changed` is a part of `across`, and `gone` was not read.
        foreach ([$first, $second, $third] as $run) {
            foreach ($run['plan'] as $table => $outcomes) {
                unset($outcomes['changed'], $outcomes['gone']);

                $this->assertSame($run['counts'][$table] ?? 0, array_sum($outcomes), $table);
            }
        }
    }

    public function test_a_second_import_is_refused_while_one_is_running(): void
    {
        // Another run, as another session holding the lock: the legacy
        // fixture's connection is a second session on this same database.
        DB::connection('legacy')->select("select pg_advisory_lock(hashtext('myfiesta:legacy:import'))");

        try {
            $this->artisan('legacy:import')
                ->expectsOutputToContain('Another legacy:import is running')
                ->assertFailed();
            $this->artisan('legacy:import', ['--dry-run' => true])->assertFailed();

            $this->assertSame(0, Order::count());
            $this->assertSame(0, DB::table('legacy_map')->count());
        } finally {
            DB::connection('legacy')->select("select pg_advisory_unlock(hashtext('myfiesta:legacy:import'))");
        }

        // The other run finished. This one goes ahead, and lets go when done.
        $this->artisan('legacy:import')->assertSuccessful();

        $this->assertSame(2, Order::count());
        $this->assertTrue((bool) DB::connection('legacy')
            ->selectOne("select pg_try_advisory_lock(hashtext('myfiesta:legacy:import')) as locked")->locked);
        DB::connection('legacy')->select("select pg_advisory_unlock(hashtext('myfiesta:legacy:import'))");
    }

    public function test_a_row_the_old_app_changed_after_it_came_across_is_reported_and_not_overwritten(): void
    {
        $this->import();

        // The parallel run's week: the paid sale refunded in the old app, the
        // abandoned one paid after all, an event renamed, a ticket scanned.
        $legacy = DB::connection('legacy');
        $legacy->table('tickets_sales')->where('sales_id', 17)->update(['_payment_status' => 'refunded']);
        $legacy->table('tickets_sales')->where('sales_id', 18)->update(['_payment_status' => 'paid']);
        $legacy->table('events')->where('id', 176)->update(['_title' => 'Standard Night (moved)']);
        $legacy->table('ticket_issued')->where('ticket_id', 901)->update(['is_checkedin' => true]);

        $result = $this->import();

        $this->assertSame(['17', '18'], $result['changed']['tickets_sales']);
        $this->assertSame(['176'], $result['changed']['events']);
        $this->assertSame(['901'], $result['changed']['ticket_issued']);
        $this->assertArrayNotHasKey('user_accounts', $result['changed']);
        $this->assertSame(['across' => 2, 'changed' => 2], $result['plan']['tickets_sales']);

        // Each order beside what the old app says now, and what to do.
        $orders = collect($result['orders'])->keyBy('legacy_id');
        $this->assertSame(['paid', 'refunded'], [$orders['17']['here'], $orders['17']['there']]);
        $this->assertStringContainsString('legacy:reconcile --apply', $orders['17']['advice']);
        $this->assertSame(['cancelled', 'paid'], [$orders['18']['here'], $orders['18']['there']]);
        $this->assertStringContainsString('paid_in_stripe_only', $orders['18']['advice']);
        $this->assertSame(Order::where('status', 'paid')->sole()->reference, $orders['17']['reference']);

        // Nothing here was touched.
        $this->assertSame('paid', Order::where('buyer_email', 'buyer@example.test')->sole()->status);
        $this->assertSame('Standard Night', Event::find(DB::table('legacy_map')->where('source_table', 'events')->where('source_id', '176')->value('target_id'))->title);
        $this->assertSame('valid', Ticket::where('code', 'MFST-3H8N2VRD')->sole()->status);

        // Said by the command too, with ids and statuses and nothing a buyer
        // could be known by.
        $this->artisan('legacy:import')
            ->expectsOutputToContain('Nothing here was changed')
            ->expectsOutputToContain('legacy:reconcile --apply records the refund Stripe made')
            ->doesntExpectOutputToContain('buyer@example.test')
            ->doesntExpectOutputToContain('MFST-3H8N2VRD')
            ->assertSuccessful();

        // A row that came across before fingerprints were kept has nothing to
        // be compared with, and is not reported on a guess.
        DB::table('legacy_map')->where('source_table', 'events')->update(['source_hash' => null]);
        $this->assertArrayNotHasKey('events', $this->import()['changed']);
    }

    public function test_a_checkout_that_may_still_be_paid_is_left_for_a_later_run(): void
    {
        // Somebody at the old app's checkout as the run reads the table.
        DB::connection('legacy')->table('tickets_sales')->insert([
            'sales_id' => 20, '_event' => '176', '_ticket' => '58|1|5',
            '_guest' => 'Now|Paying|paying@example.test', '_quantity' => '58|1|5',
            '_cost' => 500, '_ticket_status' => 'PENDING', '_checkout' => 'cs_live_open20',
            '_payment_status' => 'PENDING', '_pdate' => now()->subHour()->format('Y-m-d H:i:s'),
        ]);

        $result = $this->import();

        $this->assertSame(['20'], $result['deferred']);
        $this->assertSame(['new' => 2, 'deferred' => 1], $result['plan']['tickets_sales']);
        $this->assertFalse(Order::where('gateway_reference', 'cs_live_open20')->exists());
        $this->assertSame([], $result['failures']);

        // Two days on, the old app knows. Paid, it comes across paid.
        $this->travel(2)->days();
        DB::connection('legacy')->table('tickets_sales')->where('sales_id', 20)->update(['_payment_status' => 'paid']);

        $later = $this->import();

        $this->assertSame([], $later['deferred']);
        $this->assertSame('paid', Order::where('gateway_reference', 'cs_live_open20')->sole()->status);
    }

    public function test_once_the_old_app_is_frozen_an_open_checkout_comes_across_as_it_stands(): void
    {
        // The same basket, read by the last run after the freeze. The old app
        // will never learn whether it was paid, so waiting gains nothing and
        // keeps it out of legacy:reconcile, which is what would find out.
        DB::connection('legacy')->table('tickets_sales')->insert([
            'sales_id' => 20, '_event' => '176', '_ticket' => '58|1|5',
            '_guest' => 'Now|Paying|paying@example.test', '_quantity' => '58|1|5',
            '_cost' => 500, '_ticket_status' => 'PENDING', '_checkout' => 'cs_live_open20',
            '_payment_status' => 'PENDING', '_pdate' => now()->subHour()->format('Y-m-d H:i:s'),
        ]);

        $map = new LegacyMap;
        $map->warm();
        $result = (new LegacyImporter($map, frozen: true))->run();

        $this->assertSame([], $result['deferred']);
        $this->assertSame(['new' => 3], $result['plan']['tickets_sales']);
        $this->assertSame('cancelled', Order::where('gateway_reference', 'cs_live_open20')->sole()->status);

        $this->artisan('legacy:import', ['--frozen' => true])
            ->doesntExpectOutputToContain('left for a later run')
            ->assertSuccessful();
    }

    public function test_a_row_deleted_in_the_old_app_is_reported_and_kept(): void
    {
        $this->import();

        DB::connection('legacy')->table('ticket_issued')->where('ticket_id', 901)->delete();

        $result = $this->import();

        $this->assertSame(['ticket_issued' => ['901']], $result['gone']);
        $this->assertSame(1, $result['plan']['ticket_issued']['gone']);
        $this->assertTrue(Ticket::where('code', 'MFST-3H8N2VRD')->exists(), 'Somebody may be holding it.');
    }

    /**
     * @return list<int>
     */
    private function everythingCounted(): array
    {
        return [
            User::count(), Organization::count(), Event::count(), Venue::count(),
            TicketType::count(), Order::count(), OrderLine::count(), Ticket::count(),
            LedgerEntry::count(), DB::table('legacy_map')->count(),
        ];
    }
}
