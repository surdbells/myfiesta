<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Legacy\LegacyImporter;
use App\Services\Legacy\LegacyMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The import, run end to end against the old schema.
 *
 * The `legacy` connection is pointed at a schema of its own here, and the old
 * tables are built to the shapes the dump actually has — varchar joins, money in two
 * different units, a status column with no constraint. That is enough to
 * exercise every decision the importer makes without needing a MySQL server
 * in the test environment, and it is the only way to find out whether rows
 * written through the application's own models survive its own constraints.
 *
 * The rows below are taken from the real data: a $15 sale, a $50 ticket type,
 * an abandoned checkout stuck at PENDING, an event with a null timezone and no
 * end time.
 */
class LegacyImportTest extends TestCase
{
    use RefreshDatabase;

    private const KNOWN_PASSWORD = 'the-one-they-already-use';

    protected function setUp(): void
    {
        parent::setUp();

        // The old database is MySQL and this environment has only the Postgres
        // driver, so the fixture lives in a schema of its own on the same
        // server. What is being tested is the importer's reading of a shape,
        // not MySQL — and the shape is reproduced faithfully below: varchar
        // joins, no foreign keys, money in two units.
        config(['database.connections.legacy' => array_merge(
            config('database.connections.pgsql'),
            ['search_path' => 'legacy_src'],
        )]);

        // Dropped and rebuilt per test. This connection is outside the
        // transaction RefreshDatabase wraps the default one in, so its rows
        // would otherwise survive into the next test.
        DB::connection('legacy')->statement('DROP SCHEMA IF EXISTS legacy_src CASCADE');
        DB::connection('legacy')->statement('CREATE SCHEMA legacy_src');

        $this->buildLegacySchema();
        $this->seedLegacyRows();
    }

    protected function tearDown(): void
    {
        DB::connection('legacy')->statement('DROP SCHEMA IF EXISTS legacy_src CASCADE');

        parent::tearDown();
    }

    private function legacy(): \Illuminate\Database\Schema\Builder
    {
        return Schema::connection('legacy');
    }

    private function buildLegacySchema(): void
    {
        $this->legacy()->create('user_accounts', function ($t) {
            $t->integer('id')->primary();
            $t->string('first_name');
            $t->string('last_name');
            $t->string('user_type')->default('event_org');
            $t->string('email_address');
            $t->string('phone_number')->nullable();
            $t->string('password');
            $t->string('_status')->default('active');
            $t->timestamp('_registered')->nullable();
        });

        $this->legacy()->create('events', function ($t) {
            $t->integer('id')->primary();
            // The joins are varchars in the source, not foreign keys. There
            // are none anywhere in that database.
            $t->string('_organizer');
            $t->string('_title');
            $t->string('_category')->nullable();
            $t->string('_location')->nullable();
            $t->string('_timezone')->nullable();
            $t->string('_province')->nullable();
            $t->string('_venue')->nullable();
            $t->text('_description')->nullable();
            $t->string('_start_date');
            $t->string('_start_time')->nullable();
            $t->text('_slug')->nullable();
            $t->string('_dress_code')->nullable();
            $t->boolean('_identity_req')->default(true);
            $t->string('_status')->default('DRAFT');
            $t->boolean('is_featured')->default(false);
            $t->timestamp('created')->nullable();
        });

        $this->legacy()->create('event_tickets', function ($t) {
            $t->integer('id')->primary();
            $t->string('_event');
            $t->string('_organizer');
            $t->string('ticket_title');
            $t->string('ticket_type')->nullable();
            $t->integer('ticket_price');   // whole dollars
            $t->integer('admits')->default(1);
            $t->text('ticket_description')->nullable();
            $t->integer('max_ticket_per_person')->default(10);
            $t->integer('ticket_available')->default(100);
            $t->timestamp('_ticket_added')->nullable();
            $t->string('_ticket_status')->default('Enabled');
        });

        $this->legacy()->create('tickets_sales', function ($t) {
            $t->integer('sales_id')->primary();
            $t->string('_event');
            $t->text('_ticket');
            $t->string('_guest');
            $t->text('_quantity');
            $t->integer('_cost');          // cents
            $t->string('_ticket_status')->default('PENDING');
            $t->string('_checkout')->default('AWAITING');
            $t->string('_payment_status')->default('PENDING');
            $t->timestamp('_pdate')->nullable();
        });

        $this->legacy()->create('ticket_issued', function ($t) {
            $t->integer('ticket_id')->primary();
            $t->string('event');
            $t->string('ticket');
            $t->string('_type');
            $t->string('_sale');
            $t->string('_guest');
            $t->string('_custom_name')->default('');
            $t->timestamp('_date')->nullable();
            $t->boolean('is_checkedin')->default(false);
        });

        $this->legacy()->create('extra_data', function ($t) {
            $t->integer('extra_id')->primary();
            $t->string('user_account');
            $t->string('brand_name')->nullable();
            $t->text('brand_description')->nullable();
            $t->string('brand_number')->nullable();
            $t->string('brand_email_address')->nullable();
            $t->string('brand_twitter')->nullable();
            $t->string('brand_facebook')->nullable();
            $t->string('brand_instagram')->nullable();
            // The columns this import deliberately does not read.
            $t->string('bank_account_number')->nullable();
            $t->string('legal_doc_num')->nullable();
        });

        $this->legacy()->create('extra_logo', function ($t) {
            $t->integer('id')->primary();
            $t->string('extra');
            $t->text('logo');
        });

        $this->legacy()->create('settlements', function ($t) {
            $t->integer('id')->primary();
            $t->string('event');
            $t->string('organizer');
            $t->float('amount');           // a double, in the source
            $t->string('type')->default('full');
            $t->text('note')->nullable();
            $t->string('status')->default('success');
            $t->timestamp('date')->nullable();
        });
    }

    private function seedLegacyRows(): void
    {
        $db = DB::connection('legacy');

        $db->table('user_accounts')->insert([
            [
                'id' => 26, 'first_name' => 'Ada', 'last_name' => 'Okoro',
                'email_address' => 'ada@lagosnights.test', 'phone_number' => '+14165550101',
                // A real bcrypt hash of a known password, so the test can
                // check the thing that matters: that this person signs in
                // after the cutover with the password they already have.
                'password' => password_hash(self::KNOWN_PASSWORD, PASSWORD_BCRYPT),
                '_registered' => '2024-01-04 10:00:00',
            ],
            [
                // The other generation of hash. This account has to arrive
                // without a usable password.
                'id' => 31, 'first_name' => 'Bem', 'last_name' => 'Tar',
                'email_address' => 'bem@example.test', 'phone_number' => null,
                'password' => '356a192b7913b04c54574d18c28d46e6395428ab',
                '_registered' => '2023-06-01 09:00:00',
            ],
        ]);

        $db->table('events')->insert([
            [
                'id' => 176, '_organizer' => '26', '_title' => 'Standard Night',
                '_category' => 'Nightlife', '_location' => 'Toronto',
                '_timezone' => null, '_province' => 'ON', '_venue' => 'The Room',
                '_description' => 'A night.', '_start_date' => '2024-05-11',
                '_start_time' => '22:00', '_slug' => null, '_dress_code' => 'Smart',
                '_identity_req' => true, '_status' => 'PUBLISHED', 'is_featured' => false,
                'created' => '2024-05-01 12:00:00',
            ],
            [
                // No status the new system recognises, so it must draft. Also
                // no start time, which the source frequently leaves blank.
                'id' => 177, '_organizer' => '31', '_title' => 'Unknown State',
                '_category' => null, '_location' => null,
                '_timezone' => null, '_province' => null, '_venue' => null,
                '_description' => '', '_start_date' => '2024-06-01',
                '_start_time' => null, '_slug' => null, '_dress_code' => null,
                '_identity_req' => true, '_status' => 'ARCHIVED', 'is_featured' => false,
                'created' => '2024-05-20 12:00:00',
            ],
        ]);

        $db->table('event_tickets')->insert([
            [
                'id' => 58, '_event' => '176', '_organizer' => '26',
                'ticket_title' => 'Standard Ticket', 'ticket_price' => 5,
                'admits' => 1, 'max_ticket_per_person' => 2, 'ticket_available' => 4,
                '_ticket_added' => '2024-05-11 15:26:18', '_ticket_status' => 'Enabled',
            ],
        ]);

        $db->table('tickets_sales')->insert([
            [
                'sales_id' => 17, '_event' => '176', '_ticket' => '58|3|15',
                // The real shape: First|Last|email, on every row in that
                // table. A basket of three at $5.
                '_guest' => 'Ada|Buyer|buyer@example.test',
                '_quantity' => '58|3|15', '_cost' => 1500,
                '_ticket_status' => 'PAID', '_checkout' => 'cs_live_abc123',
                '_payment_status' => 'paid', '_pdate' => '2024-05-11 18:00:00',
            ],
            [
                // One of the 981. Two years at PENDING.
                'sales_id' => 18, '_event' => '176', '_ticket' => '58|1|5',
                '_guest' => 'Someone|Who Left|left@example.test', '_quantity' => '58|1|5',
                '_cost' => 500, '_ticket_status' => 'PENDING',
                '_checkout' => 'AWAITING', '_payment_status' => 'PENDING',
                '_pdate' => '2024-05-11 18:05:00',
            ],
        ]);

        $db->table('ticket_issued')->insert([
            [
                'ticket_id' => 900, 'event' => '176', 'ticket' => 'MFST-9K2L4XQ7',
                '_type' => '58', '_sale' => '17', '_guest' => 'buyer@example.test',
                '_custom_name' => 'Ada Guest', '_date' => '2024-05-11 18:00:01',
                'is_checkedin' => true,
            ],
            [
                'ticket_id' => 901, 'event' => '176', 'ticket' => 'MFST-3H8N2VRD',
                '_type' => '58', '_sale' => '17', '_guest' => 'buyer@example.test',
                '_custom_name' => '', '_date' => '2024-05-11 18:00:02',
                'is_checkedin' => false,
            ],
        ]);

        $db->table('extra_data')->insert([[
            'extra_id' => 12, 'user_account' => '26',
            'brand_name' => 'Lagos Nights', 'brand_description' => 'Afrobeats, monthly.',
            'brand_number' => '+14165550199', 'brand_email_address' => 'hello@lagosnights.test',
            'brand_twitter' => 'lagosnights', 'brand_facebook' => 'lagosnightsto',
            'brand_instagram' => 'lagos.nights',
            // Present, and deliberately not carried across.
            'bank_account_number' => '000123456', 'legal_doc_num' => 'AB1234567',
        ]]);

        // A one-pixel PNG, as a data URI — which is how every image in that
        // database is actually stored, longblob column or not.
        $db->table('extra_logo')->insert([[
            'id' => 5, 'extra' => '12',
            'logo' => 'data:image/png;base64,'.base64_encode(base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
            )),
        ]]);

        $db->table('settlements')->insert([[
            'id' => 40, 'event' => '176', 'organizer' => '26',
            'amount' => 12.34, 'type' => 'full', 'note' => 'Paid by Interac',
            'status' => 'success', 'date' => '2024-05-20 10:00:00',
        ]]);
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

    public function test_an_unrecognised_status_drafts_rather_than_publishes(): void
    {
        $this->import();

        $this->assertSame('draft', Event::where('title', 'Unknown State')->firstOrFail()->status);
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
        $this->assertSame('Standard Ticket', $line->ticket_type_name);

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
        $share = (new \ReflectionMethod(\App\Services\Refunds\RefundService::class, 'shareFor'))
            ->invoke(app(\App\Services\Refunds\RefundService::class), $order, $order->tickets);

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

        $before = [
            User::count(), Organization::count(), Event::count(),
            TicketType::count(), Order::count(), Ticket::count(),
            LedgerEntry::count(),
        ];

        // A quarter of a gigabyte over a network does not finish first time.
        // The recovery has to be running it again.
        $this->import();

        $after = [
            User::count(), Organization::count(), Event::count(),
            TicketType::count(), Order::count(), Ticket::count(),
            LedgerEntry::count(),
        ];

        $this->assertSame($before, $after);
    }
}
