<?php

namespace Tests\Feature;

use App\Services\Backups\BackupCipher;
use App\Services\Backups\Postgres;
use App\Services\Backups\Retention;
use App\Services\Backups\StoredBackup;
use App\Support\Operations\Heartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The nightly backup, and getting it back.
 *
 * pg_dump and pg_restore are stood in for — the point here is everything
 * around them: that the dump is sealed on its way to disk, that the manifest's
 * counts come from the database itself, that a failed night costs no backup
 * that was already there, that old ones are thinned out to the schedule, and
 * that a restore refuses the live database, a damaged file and the wrong key
 * before it touches anything. docs/OPERATIONS.md has the drill that runs the
 * real tools against a real server.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $target;

    private string $work;

    private const DUMP = 'PGDMP pretend custom-format archive';

    protected function setUp(): void
    {
        parent::setUp();

        $root = storage_path('framework/testing/backups-'.Str::random(8));
        $this->target = $root.'/target';
        $this->work = $root.'/work';
        File::ensureDirectoryExists($this->target);
        File::ensureDirectoryExists($this->work);

        config([
            'operations.backup.target' => 'volume',
            'operations.backup.volume.path' => $this->target,
            'operations.backup.work_dir' => $this->work,
            'operations.backup.encryption_key' => BackupCipher::generateKey(),
            'sentry.release' => 'test-release',
        ]);

        $this->fakeTools();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->target));

        parent::tearDown();
    }

    private function fakeTools(int $dumpExit = 0): void
    {
        Process::fake([
            '*--version*' => Process::result('pg_dump (PostgreSQL) 17.2'),
            '*--format=custom*' => $dumpExit === 0
                ? Process::result(self::DUMP)
                : Process::result(errorOutput: 'pg_dump: error: connection to server failed', exitCode: $dumpExit),
            '*pg_restore*' => Process::result(),
        ]);
    }

    /** @return list<string> */
    private function targetFiles(): array
    {
        return collect(File::files($this->target))->map->getFilename()->sort()->values()->all();
    }

    public function test_a_backup_is_sealed_and_stored_with_a_manifest_of_the_database_it_came_from(): void
    {
        $this->artisan('backup:run')
            ->expectsOutputToContain('Stored myfiesta-')
            ->assertSuccessful();

        $files = $this->targetFiles();
        $this->assertCount(2, $files);
        $this->assertMatchesRegularExpression('/^myfiesta-\d{8}T\d{6}Z\.dump\.enc$/', $files[0]);
        $this->assertMatchesRegularExpression('/^myfiesta-\d{8}T\d{6}Z\.json$/', $files[1]);

        $dump = $this->target.'/'.$files[0];
        $manifest = json_decode(File::get($this->target.'/'.$files[1]), true);

        // Nothing readable on disk: the archive only comes back with the key.
        $this->assertStringNotContainsString('PGDMP', File::get($dump));
        $plain = fopen('php://memory', 'w+b');
        BackupCipher::decrypt(fopen($dump, 'rb'), $plain, BackupCipher::key(config('operations.backup.encryption_key')));
        rewind($plain);
        $this->assertSame(self::DUMP."\n", stream_get_contents($plain));

        // Counted by the database itself, table by table.
        $this->assertTrue($manifest['encrypted']);
        $this->assertSame(hash_file('sha256', $dump), $manifest['sha256']);
        $this->assertSame(filesize($dump), $manifest['bytes']);
        $this->assertSame('test-release', $manifest['release']);
        $this->assertSame(config('database.connections.pgsql.database'), $manifest['database']);
        foreach (['public.orders', 'public.ledger_entries', 'public.audit_logs', 'public.migrations'] as $table) {
            $this->assertArrayHasKey($table, $manifest['tables']);
        }
        $this->assertGreaterThan(0, $manifest['tables']['public.migrations']);

        // pg_dump read the snapshot the rows were counted in, and was never
        // handed the password where a process list would show it.
        $password = (string) config('database.connections.pgsql.password');

        Process::assertRan(function (PendingProcess $process) use ($password) {
            $command = implode(' ', (array) $process->command);

            return str_contains($command, '--format=custom')
                && str_contains($command, '--snapshot=')
                && ! str_contains(strtolower($command), 'password=')
                && ! str_contains($command, '@')
                && ($process->environment['PGPASSWORD'] ?? null) === ($password === '' ? null : $password);
        });

        $this->assertSame(0, Heartbeat::secondsSince(Heartbeat::BACKUP));
        $this->assertSame([], File::files($this->work), 'Nothing is left in the working directory.');
    }

    public function test_without_a_key_the_dump_is_stored_as_it_is_and_the_run_says_so(): void
    {
        config(['operations.backup.encryption_key' => '']);

        $this->artisan('backup:run')
            ->expectsOutputToContain('BACKUP_ENCRYPTION_KEY is empty')
            ->assertSuccessful();

        $dump = collect($this->targetFiles())->first(fn (string $name) => str_ends_with($name, '.dump'));

        $this->assertNotNull($dump);
        $this->assertSame(self::DUMP."\n", File::get($this->target.'/'.$dump));
    }

    public function test_a_key_that_is_not_a_key_is_refused_rather_than_ignored(): void
    {
        config(['operations.backup.encryption_key' => 'not-a-real-key']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BACKUP_ENCRYPTION_KEY is not 32 bytes');

        $this->artisan('backup:run');
    }

    public function test_a_failed_night_leaves_every_earlier_backup_where_it_was(): void
    {
        File::put($this->target.'/myfiesta-20260101T063000Z.dump.enc', 'old');
        File::put($this->target.'/myfiesta-20260101T063000Z.json', '{}');
        $before = $this->targetFiles();

        $this->fakeTools(dumpExit: 1);

        try {
            $this->artisan('backup:run');
            $this->fail('A failed pg_dump has to fail the run, so the scheduler and Sentry both hear about it.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pg_dump failed', $e->getMessage());
        }

        $this->assertSame($before, $this->targetFiles());
        $this->assertSame([], File::files($this->work));
    }

    public function test_old_backups_are_thinned_to_seven_days_four_weeks_and_six_months(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27 07:00:00', 'UTC'));

        foreach (range(1, 400) as $daysAgo) {
            $backup = StoredBackup::named(CarbonImmutable::parse('2026-09-27 06:30:00', 'UTC')->subDays($daysAgo), true);
            File::put($this->target.'/'.$backup->name, 'x');
            File::put($this->target.'/'.$backup->manifestName(), '{}');
        }

        // Something else in the directory is never taken for a backup.
        File::put($this->target.'/notes.txt', 'keep me');

        $this->artisan('backup:run')->assertSuccessful();

        $kept = collect($this->targetFiles())
            ->filter(fn (string $name) => str_ends_with($name, '.enc'))
            ->map(fn (string $name) => substr($name, 9, 8))
            ->values()
            ->all();

        $this->assertSame([
            '20260430', '20260531', '20260630', '20260731', '20260831',
            '20260906', '20260913', '20260920', '20260921', '20260922',
            '20260923', '20260924', '20260925', '20260926', '20260927',
        ], $kept);
        $this->assertFileExists($this->target.'/notes.txt');
    }

    public function test_a_file_named_for_a_moment_that_never_was_is_not_taken_for_a_backup(): void
    {
        // Each of these rolls over into some other date if it is parsed and
        // not read back: month 13, the 30th of February, hour 25, and the
        // year 10007.
        foreach ([
            'myfiesta-20261340T063000Z.dump',
            'myfiesta-20260230T063000Z.dump.enc',
            'myfiesta-20260927T253000Z.dump',
            'myfiesta-99999999T999999Z.dump.enc',
        ] as $name) {
            $this->assertNull(StoredBackup::fromName($name), "{$name} was taken for a backup.");
        }

        $real = StoredBackup::fromName('myfiesta-20260927T063000Z.dump.enc');

        $this->assertNotNull($real);
        $this->assertSame('2026-09-27 06:30:00', $real->takenAt->format('Y-m-d H:i:s'));
        $this->assertTrue($real->encrypted);

        // Beside a real backup from tonight, the stray is not the newest one:
        // the health check measures tonight's, and passes.
        File::put($this->target.'/myfiesta-99999999T999999Z.dump.enc', 'stray');
        $this->artisan('backup:run')->assertSuccessful();

        $this->artisan('app:health', ['--only' => ['backup']])->assertSuccessful();
        $this->assertFileExists($this->target.'/myfiesta-99999999T999999Z.dump.enc');
    }

    public function test_retention_skips_empty_periods_rather_than_counting_them(): void
    {
        // A backup every ten days: seven "daily" backups still means seven.
        $backups = array_map(
            fn (int $i) => StoredBackup::named(CarbonImmutable::parse('2026-09-27 06:30', 'UTC')->subDays($i * 10), false),
            range(0, 29),
        );

        ['keep' => $keep] = Retention::apply($backups, 7, 0, 0);

        $this->assertCount(7, $keep);
        $this->assertSame('myfiesta-20260927T063000Z.dump', $keep[0]->name);
    }

    public function test_a_sealed_backup_cut_short_altered_or_opened_with_the_wrong_key_does_not_open(): void
    {
        $key = BackupCipher::key(BackupCipher::generateKey());
        $plain = random_bytes(3 * 1048576 + 12345);

        $sealed = fopen('php://memory', 'w+b');
        $writer = BackupCipher::writer($sealed, $key);
        foreach (str_split($plain, 65536) as $piece) {
            $writer->write($piece);
        }
        $writer->finish();
        rewind($sealed);
        $bytes = stream_get_contents($sealed);

        $open = function (string $bytes, string $key): string {
            $in = fopen('php://memory', 'w+b');
            fwrite($in, $bytes);
            rewind($in);
            $out = fopen('php://memory', 'w+b');
            BackupCipher::decrypt($in, $out, $key);
            rewind($out);

            return stream_get_contents($out);
        };

        $this->assertSame($plain, $open($bytes, $key), 'Several pieces, and the whole comes back.');

        foreach ([
            'cut short' => substr($bytes, 0, -100),
            'missing its last piece' => substr($bytes, 0, strlen($bytes) - 12345 - 17 - 4),
            'altered' => substr_replace($bytes, chr(ord($bytes[5000]) ^ 1), 5000, 1),
        ] as $what => $damaged) {
            try {
                $open($damaged, $key);
                $this->fail("A backup {$what} opened.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(RuntimeException::class);
        $open($bytes, BackupCipher::key(BackupCipher::generateKey()));
    }

    public function test_a_restore_never_goes_into_the_live_database(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $this->artisan('backup:restore', ['--into' => config('database.connections.pgsql.database')])
            ->expectsOutputToContain('is the database the application is using')
            ->assertFailed();

        $this->artisan('backup:restore')
            ->expectsOutputToContain('Say which new database to restore into')
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $process) => str_contains(implode(' ', (array) $process->command), 'pg_restore'));
    }

    public function test_a_damaged_copy_or_the_wrong_key_is_refused_before_anything_is_loaded(): void
    {
        $this->artisan('backup:run')->assertSuccessful();
        $dump = collect($this->targetFiles())->first(fn (string $name) => str_ends_with($name, '.enc'));
        $original = File::get($this->target.'/'.$dump);

        File::put($this->target.'/'.$dump, $original.'x');

        $this->artisan('backup:restore', ['--into' => 'myfiesta_restore_drill'])
            ->expectsOutputToContain('checksum does not match')
            ->assertFailed();

        File::put($this->target.'/'.$dump, $original);
        config(['operations.backup.encryption_key' => BackupCipher::generateKey()]);

        $this->artisan('backup:restore', ['--into' => 'myfiesta_restore_drill'])
            ->expectsOutputToContain('does not open with this key')
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $process) => str_contains(implode(' ', (array) $process->command), 'pg_restore'));
        $this->assertSame([], File::files($this->work), 'Nothing opened is left lying about.');
    }

    public function test_a_restore_counts_every_table_and_says_which_do_not_match(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $into = 'myfiesta_restore_'.Str::lower(Str::random(8));
        $live = Postgres::config('pgsql');

        try {
            // The stand-in pg_restore loads nothing, so every table is
            // missing from the new database — which is what the count is for.
            $this->artisan('backup:restore', ['--into' => $into, '--create' => true, '--drop-after' => true, '--jobs' => 2])
                ->expectsOutputToContain('do not hold what the backup says they should')
                ->expectsOutputToContain('public.migrations')
                ->expectsOutputToContain("Dropped {$into} again.")
                ->assertFailed();

            $this->assertFalse($this->databaseExists($into), 'A drill leaves no database behind.');

            Process::assertRan(function (PendingProcess $process) use ($into) {
                $command = implode(' ', (array) $process->command);

                return str_contains($command, 'pg_restore')
                    && str_contains($command, '--no-owner')
                    && str_contains($command, '--jobs=2')
                    && str_contains($command, '--dbname='.$into);
            });
        } finally {
            Postgres::open($live, 'backup_test_admin')->unprepared('DROP DATABASE IF EXISTS '.Postgres::quote($into).' WITH (FORCE)');
            Postgres::close('backup_test_admin');
        }
    }

    public function test_a_restore_still_creates_its_database_when_the_live_one_is_gone(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        // The case switching over is for: the application's database has
        // gone from the server — or, on a new server, was never made. Nothing
        // may need to connect to it to make the one the backup goes into.
        $gone = 'myfiesta_gone_'.Str::lower(Str::random(8));
        config([
            'database.connections.gone' => array_merge(config('database.connections.pgsql'), ['database' => $gone]),
            'operations.backup.connection' => 'gone',
        ]);
        $this->assertFalse($this->databaseExists($gone));

        $into = 'myfiesta_restore_'.Str::lower(Str::random(8));
        $live = Postgres::config('pgsql');

        try {
            $this->artisan('backup:restore', ['--into' => $into, '--create' => true, '--drop-after' => true])
                ->expectsOutputToContain("Creating the database {$into}")
                ->expectsOutputToContain('do not hold what the backup says they should')
                ->expectsOutputToContain("Dropped {$into} again.")
                ->assertFailed();

            Process::assertRan(fn (PendingProcess $process) => str_contains(implode(' ', (array) $process->command), '--dbname='.$into));
            $this->assertFalse($this->databaseExists($into), 'Dropped again, also without the live database.');
        } finally {
            Postgres::open($live, 'backup_test_admin')->unprepared('DROP DATABASE IF EXISTS '.Postgres::quote($into).' WITH (FORCE)');
            Postgres::close('backup_test_admin');
        }
    }

    public function test_a_drill_never_drops_a_database_it_did_not_make(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $into = 'myfiesta_somebodys_'.Str::lower(Str::random(8));
        $live = Postgres::config('pgsql');

        try {
            Postgres::open($live, 'backup_test_admin')->unprepared('CREATE DATABASE '.Postgres::quote($into));
            Postgres::close('backup_test_admin');

            // CREATE fails because it is there already — and it stays there.
            $this->artisan('backup:restore', ['--into' => $into, '--create' => true, '--drop-after' => true])
                ->assertFailed();

            $this->assertTrue($this->databaseExists($into));

            $this->artisan('backup:restore', ['--into' => $into, '--drop-after' => true])
                ->expectsOutputToContain('--drop-after only drops a database this run creates')
                ->assertFailed();
        } finally {
            Postgres::open($live, 'backup_test_admin')->unprepared('DROP DATABASE IF EXISTS '.Postgres::quote($into).' WITH (FORCE)');
            Postgres::close('backup_test_admin');
        }
    }

    private function databaseExists(string $name): bool
    {
        try {
            return Postgres::open(Postgres::config('pgsql'), 'backup_test_admin')
                ->selectOne('select count(*) as n from pg_database where datname = ?', [$name])->n > 0;
        } finally {
            Postgres::close('backup_test_admin');
        }
    }

    public function test_the_health_check_knows_how_old_the_newest_backup_is(): void
    {
        $this->artisan('app:health', ['--only' => ['backup']])
            ->expectsOutputToContain('There is no backup.')
            ->assertFailed();

        $this->artisan('backup:run')->assertSuccessful();
        $this->artisan('app:health', ['--only' => ['backup']])->assertSuccessful();
        $this->artisan('backup:list')->expectsOutputToContain('test-release')->assertSuccessful();

        $this->travel(27)->hours();

        $this->artisan('app:health', ['--only' => ['backup']])
            ->expectsOutputToContain('The newest backup is 27 hours old.')
            ->assertFailed();
    }
}
