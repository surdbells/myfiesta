<?php

namespace Tests\Feature;

use App\Models\EmailChange;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PendingRegistration;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Checkout\TicketIssuer;
use App\Services\Door\CheckInService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the scheduler deletes, and what it never may.
 *
 * Every nightly clean-up deletes rows that were only waiting — a failed job,
 * a token past its expiry, a link nobody opened. None of them may reach a
 * record: the ledger, the audit trail, an order, a ticket or a scan. These
 * run every clean-up the schedule has against years-old records of each and
 * count them afterwards, twice, because a job that is safe once and not the
 * second night is not safe.
 */
class PruneScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** Every scheduled command that deletes anything. */
    private const CLEAN_UPS = [
        'queue:prune-failed' => ['--hours' => 720],
        'sanctum:prune-expired' => ['--hours' => 168],
        'auth:clear-resets' => [],
        'app:prune-expired' => [],
        'model:prune' => [],
        'checkouts:expire' => [],
        'webhooks:prune' => [],
        'impersonation:close-lapsed' => [],
        'privacy:prune' => [],
    ];

    /** Records. Never pruned, by anything. */
    private const RECORDS = ['audit_logs', 'ledger_entries', 'orders', 'tickets', 'ticket_scans'];

    public function test_every_table_a_clean_up_deletes_from_exists(): void
    {
        foreach ([
            'failed_jobs', 'personal_access_tokens', 'password_reset_tokens', 'email_changes',
            'pending_registrations', 'inventory_holds', 'webhook_deliveries', 'impersonation_sessions',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} is pruned on a schedule and is not in the schema.");
        }
    }

    public function test_the_schedule_runs_each_clean_up_and_nothing_aimed_at_a_record(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => (string) $event->command)
            ->filter();

        foreach (['queue:prune-failed --hours=720', 'sanctum:prune-expired --hours=168', 'auth:clear-resets', 'app:prune-expired', 'model:prune', 'backup:run', 'app:heartbeat'] as $expected) {
            $this->assertTrue(
                $commands->contains(fn (string $command) => str_contains(str_replace(["'", '"'], '', $command), $expected)),
                "The schedule does not run {$expected}.",
            );
        }

        foreach ($commands as $command) {
            $this->assertDoesNotMatchRegularExpression('/audit|ledger|activitylog/i', $command, 'Nothing scheduled may prune the audit trail or the ledger.');
        }
    }

    public function test_clean_ups_remove_what_expired_keep_what_did_not_and_never_touch_a_record(): void
    {
        $this->records();

        [$expired, $current] = $this->waitingRows();

        // Years on, so every record is older than any retention window.
        $this->travel(3)->years();
        $this->touchWaitingRowsRelativeToNow($expired, $current);

        $records = $this->rowsIn(self::RECORDS);

        $this->cleanUp();

        $this->assertSame($records, $this->rowsIn(self::RECORDS), 'A clean-up deleted or added a record.');

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $expired['failed_job']]);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $current['failed_job']]);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $expired['token']]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current['token']]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current['recently_expired_token']]);

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'stale@example.com']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'fresh@example.com']);

        $this->assertDatabaseMissing('email_changes', ['id' => $expired['email_change']]);
        $this->assertDatabaseHas('email_changes', ['id' => $current['email_change']]);

        $this->assertDatabaseMissing('pending_registrations', ['id' => $expired['sign_up']]);
        $this->assertDatabaseHas('pending_registrations', ['id' => $current['sign_up']]);

        // A second night finds nothing more to do, and still no record moves.
        $after = $this->rowsIn([...self::RECORDS, 'failed_jobs', 'personal_access_tokens', 'password_reset_tokens', 'email_changes', 'pending_registrations']);

        $this->cleanUp();

        $this->assertSame($after, $this->rowsIn(array_keys($after)));
    }

    /** One of each record, the kind a clean-up must never reach. */
    private function records(): void
    {
        $organization = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights-'.Str::lower(Str::random(6))]);
        $event = Event::create([
            'organization_id' => $organization->id,
            'slug' => 'afro-fest-'.Str::lower(Str::random(6)),
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addHour(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);
        $type = TicketType::create(['event_id' => $event->id, 'name' => 'General', 'price_amount' => 5000, 'status' => 'on_sale']);

        $order = Order::create([
            'organization_id' => $organization->id,
            'event_id' => $event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => 5000,
            'total_amount' => 5000,
            'net_revenue_amount' => 5000,
            'gateway' => 'stripe',
            'gateway_reference' => 'cs_'.Str::random(10),
            'gateway_payment_reference' => 'pi_'.Str::random(10),
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        LedgerEntry::create([
            'organization_id' => $organization->id,
            'event_id' => $event->id,
            'order_id' => $order->id,
            'type' => 'sale',
            'amount' => 5000,
            'currency' => 'CAD',
            'occurred_at' => now(),
        ]);

        $ticket = app(TicketIssuer::class)->issueComp($event->id, $type->id, 'guest@example.com', 'Ada Guest');
        app(CheckInService::class)->scan($ticket->code, $event->id);

        app(Auditor::class)->record('event.published', $event, null, $organization->id);

        foreach (self::RECORDS as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), "The fixture made no {$table}.");
        }
    }

    /**
     * Rows that were only waiting, each once past its time and once not.
     *
     * @return array{0: array<string, string|int>, 1: array<string, string|int>}
     */
    private function waitingRows(): array
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        return [
            [
                'failed_job' => (string) Str::uuid(),
                'token' => $user->createToken('old', ['*'], now()->addDay())->accessToken->id,
                'email_change' => EmailChange::create(['user_id' => $user->id, 'email' => 'new@example.com', 'token_hash' => hash('sha256', 'a'), 'expires_at' => now()->addHour()])->id,
                'sign_up' => PendingRegistration::create(['email' => 'late@example.com', 'name' => 'Late', 'password' => 'x', 'expires_at' => now()->addDay()])->id,
            ],
            [
                'failed_job' => (string) Str::uuid(),
                'token' => $other->createToken('current', ['*'], null)->accessToken->id,
                'recently_expired_token' => $other->createToken('lapsed', ['*'], now()->addDay())->accessToken->id,
                'email_change' => EmailChange::create(['user_id' => $other->id, 'email' => 'newer@example.com', 'token_hash' => hash('sha256', 'b'), 'expires_at' => now()->addHour()])->id,
                'sign_up' => PendingRegistration::create(['email' => 'soon@example.com', 'name' => 'Soon', 'password' => 'x', 'expires_at' => now()->addDay()])->id,
            ],
        ];
    }

    /**
     * @param  array<string, string|int>  $expired
     * @param  array<string, string|int>  $current
     */
    private function touchWaitingRowsRelativeToNow(array $expired, array $current): void
    {
        foreach ([[$expired['failed_job'], now()->subDays(31)], [$current['failed_job'], now()->subDays(2)]] as [$uuid, $at]) {
            DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => $at]);
        }

        DB::table('personal_access_tokens')->where('id', $expired['token'])->update(['expires_at' => now()->subDays(10)]);
        DB::table('personal_access_tokens')->where('id', $current['recently_expired_token'])->update(['expires_at' => now()->subDay()]);

        DB::table('password_reset_tokens')->insert([
            ['email' => 'stale@example.com', 'token' => 'x', 'created_at' => now()->subHours(2)],
            ['email' => 'fresh@example.com', 'token' => 'y', 'created_at' => now()],
        ]);

        DB::table('email_changes')->where('id', $expired['email_change'])->update(['expires_at' => now()->subMinute()]);
        DB::table('email_changes')->where('id', $current['email_change'])->update(['expires_at' => now()->addHour()]);
        DB::table('pending_registrations')->where('id', $expired['sign_up'])->update(['expires_at' => now()->subMinute()]);
        DB::table('pending_registrations')->where('id', $current['sign_up'])->update(['expires_at' => now()->addDay()]);
    }

    private function cleanUp(): void
    {
        foreach (self::CLEAN_UPS as $command => $options) {
            $this->assertSame(0, Artisan::call($command, $options), "{$command} failed: ".Artisan::output());
        }
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function rowsIn(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }
}
