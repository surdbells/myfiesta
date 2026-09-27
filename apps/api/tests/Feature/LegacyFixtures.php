<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A small copy of the old database, built to the shapes the dump has.
 *
 * The `legacy` connection is pointed at a schema of its own, and the old
 * tables are built as they are — varchar joins, money in two different units,
 * a status column with no constraint. That is enough to exercise every
 * decision the importer makes without needing a MySQL server in the test
 * environment, and it is the only way to find out whether rows written
 * through the application's own models survive its own constraints.
 *
 * The rows are modelled on the kinds the source holds: a $15 sale, a $5
 * ticket type, an abandoned checkout stuck at PENDING, an event with a null
 * timezone and no end time. The sales added for reconciliation, and every
 * Stripe id they carry, are made up for the tests.
 */
trait LegacyFixtures
{
    protected const KNOWN_PASSWORD = 'the-one-they-already-use';

    protected function setUpLegacyDatabase(): void
    {
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

    protected function tearDownLegacyDatabase(): void
    {
        DB::connection('legacy')->statement('DROP SCHEMA IF EXISTS legacy_src CASCADE');
    }

    private function legacySchema(): Builder
    {
        return Schema::connection('legacy');
    }

    private function buildLegacySchema(): void
    {
        $this->legacySchema()->create('user_accounts', function ($t) {
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

        $this->legacySchema()->create('events', function ($t) {
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

        $this->legacySchema()->create('event_tickets', function ($t) {
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

        $this->legacySchema()->create('tickets_sales', function ($t) {
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

        $this->legacySchema()->create('ticket_issued', function ($t) {
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

        $this->legacySchema()->create('extra_data', function ($t) {
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

        $this->legacySchema()->create('extra_logo', function ($t) {
            $t->integer('id')->primary();
            $t->string('extra');
            $t->text('logo');
        });

        $this->legacySchema()->create('settlements', function ($t) {
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
                'password' => '356a192b7913b04c54574d18c28d46e6395428ab', // sha1("1"), a textbook value; gitleaks:allow
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

    /**
     * A paid sale of `$cost` cents on the fixture's event, as the source
     * writes one.
     */
    protected function legacySale(int $salesId, int $cost, string $checkout, string $paymentStatus = 'paid'): void
    {
        $dollars = intdiv($cost, 100);
        $quantity = max(1, intdiv($dollars, 5));

        DB::connection('legacy')->table('tickets_sales')->insert([
            'sales_id' => $salesId, '_event' => '176',
            '_ticket' => "58|{$quantity}|{$dollars}",
            '_guest' => "Buyer|{$salesId}|buyer{$salesId}@example.test",
            '_quantity' => "58|{$quantity}|{$dollars}",
            '_cost' => $cost,
            '_ticket_status' => $paymentStatus === 'paid' ? 'PAID' : 'PENDING',
            '_checkout' => $checkout,
            '_payment_status' => $paymentStatus,
            '_pdate' => '2024-05-11 19:00:00',
        ]);
    }
}
