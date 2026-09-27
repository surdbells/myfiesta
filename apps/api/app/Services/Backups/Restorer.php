<?php

namespace App\Services\Backups;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Putting a backup back — always into a new database, never over the live one.
 *
 * Restoring is a drill until the moment it is not, and both go the same way:
 * fetch the backup, check it is the file that was stored, open it, load it
 * into an empty database beside the live one, and count every table against
 * the manifest. Switching the application over is a separate, deliberate step
 * (docs/OPERATIONS.md), because the most expensive mistake available here is
 * restoring last night over this morning's sales.
 */
class Restorer
{
    private const TARGET_CONNECTION = 'backup_restore_target';

    private const SERVER_CONNECTION = 'backup_restore_server';

    /**
     * The database the last restore made, if it made one — the only kind a
     * drill may drop afterwards. One that was already there is somebody's.
     */
    public ?string $created = null;

    public function __construct(
        private readonly BackupStore $store,
        private readonly Backups $backups,
    ) {}

    /**
     * @param  callable(string): void  $say
     * @return array{restored_into: string, tables: int, mismatches: array<string, array{expected: int, found: ?int}>}
     */
    public function restore(StoredBackup $backup, string $into, bool $create, int $jobs, callable $say): array
    {
        $source = (string) config('operations.backup.connection');
        $live = Postgres::config($source);

        $this->refuseTheLiveDatabase($live, $into);

        $manifest = $this->store->manifest($backup)
            ?? throw new RuntimeException("{$backup->name} has no manifest beside it, so there is nothing to check a restore against.");

        $path = $this->backups->workFile(preg_replace('/\.enc$/', '', $backup->name).'.restoring');

        try {
            $say("Fetching {$backup->name} from ".$this->store->describe().'…');
            $this->fetch($backup, $manifest, $path);

            $target = array_merge($live, ['database' => $into]);

            if ($create) {
                $say("Creating the database {$into}…");
                $this->create($source, $into);
                $this->created = $into;
            }

            $this->refuseANonEmptyDatabase($target);

            $say("Loading it into {$into} with {$jobs} job(s)…");
            $this->pgRestore($target, $path, $jobs);
        } finally {
            @unlink($path);
        }

        $say('Counting every table against the manifest…');
        $mismatches = $this->compare($target, (array) $manifest['tables']);

        return ['restored_into' => $into, 'tables' => count($manifest['tables']), 'mismatches' => $mismatches];
    }

    /**
     * The live database is refused by name, on the same server, whatever else
     * is said. There is no flag that overrides this.
     *
     * @param  array<string, mixed>  $live
     */
    private function refuseTheLiveDatabase(array $live, string $into): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $into)) {
            throw new RuntimeException("\"{$into}\" is not a database name this will create: letters, digits and underscores, starting with a letter.");
        }

        if (strtolower($into) === strtolower((string) $live['database'])) {
            throw new RuntimeException("{$into} is the database the application is using. Restore into a new one, check it, then switch over (docs/OPERATIONS.md).");
        }
    }

    /**
     * The stored bytes, checked against the manifest before anything is
     * opened — a damaged or altered copy is refused before it is decrypted,
     * let alone loaded.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function fetch(StoredBackup $backup, array $manifest, string $path): void
    {
        $key = null;

        if ($backup->encrypted) {
            $key = BackupCipher::key(config('operations.backup.encryption_key'))
                ?? throw new RuntimeException("{$backup->name} is encrypted and BACKUP_ENCRYPTION_KEY is empty here. Set the key it was made with.");
        }

        $stored = $key === null ? $path : $path.'.sealed';
        $in = $this->store->read($backup);
        $out = fopen($stored, 'w+b');
        $hash = hash_init('sha256');

        try {
            while (! feof($in) && ($piece = fread($in, 1048576)) !== false && $piece !== '') {
                hash_update($hash, $piece);
                fwrite($out, $piece);
            }

            if (! hash_equals((string) ($manifest['sha256'] ?? ''), hash_final($hash))) {
                throw new RuntimeException("{$backup->name} is not the file that was stored: its checksum does not match the manifest.");
            }

            if ($key !== null) {
                rewind($out);
                $plain = fopen($path, 'w+b');

                try {
                    BackupCipher::decrypt($out, $plain, $key);
                } finally {
                    fclose($plain);
                }
            }
        } finally {
            fclose($in);
            fclose($out);

            if ($stored !== $path) {
                @unlink($stored);
            }
        }
    }

    /**
     * Drop a database a drill restored into. The same refusal as restoring:
     * never the live one, by name, whatever else is said.
     */
    public function drop(string $into): void
    {
        $source = (string) config('operations.backup.connection');
        $live = Postgres::config($source);

        $this->refuseTheLiveDatabase($live, $into);

        // FORCE, because this process's own connection to it may not have
        // been let go of yet.
        $this->onTheServer($live, fn (Connection $server) => $server
            ->unprepared('DROP DATABASE IF EXISTS '.Postgres::quote($into).' WITH (FORCE)'));
    }

    /**
     * The name cannot be a bound parameter; it was checked against a strict
     * pattern above.
     */
    private function create(string $source, string $into): void
    {
        $this->onTheServer(Postgres::config($source), fn (Connection $server) => $server
            ->unprepared('CREATE DATABASE '.Postgres::quote($into)));
    }

    /**
     * CREATE and DROP DATABASE, connected to the server rather than to the
     * live database: that is the one a restore is for when it has gone, and
     * on a new server it was never made. `postgres` is on nearly every
     * server, managed ones included; `template1` is on all of them. On a
     * connection of its own, too: neither statement can run inside a
     * transaction, and whatever else is using the live connection may be in
     * one.
     *
     * @param  array<string, mixed>  $live
     * @param  callable(Connection): mixed  $run
     */
    private function onTheServer(array $live, callable $run): void
    {
        $failed = null;

        foreach (['postgres', 'template1'] as $database) {
            try {
                $server = Postgres::open(array_merge($live, ['database' => $database]), self::SERVER_CONNECTION);
                // Connected here, so a server without this database is told
                // apart from the statement failing.
                $server->getPdo();
            } catch (Throwable $e) {
                Postgres::close(self::SERVER_CONNECTION);
                $failed = $e;

                continue;
            }

            try {
                $run($server);

                return;
            } finally {
                Postgres::close(self::SERVER_CONNECTION);
            }
        }

        throw new RuntimeException('Could not connect to the database server to create or drop a database: '.$failed?->getMessage(), previous: $failed);
    }

    /** @param  array<string, mixed>  $target */
    private function refuseANonEmptyDatabase(array $target): void
    {
        try {
            $tables = (int) Postgres::open($target, self::TARGET_CONNECTION)
                ->selectOne("select count(*) as n from pg_tables where schemaname not in ('pg_catalog', 'information_schema')")->n;
        } catch (Throwable $e) {
            throw new RuntimeException("Could not reach the database {$target['database']}. Pass --create to have it made.", previous: $e);
        } finally {
            Postgres::close(self::TARGET_CONNECTION);
        }

        if ($tables > 0) {
            throw new RuntimeException("{$target['database']} already has tables in it. Restore into an empty database.");
        }
    }

    /** @param  array<string, mixed>  $target */
    private function pgRestore(array $target, string $path, int $jobs): void
    {
        $errors = '';

        $result = Process::quietly()
            ->env(Postgres::environment($target))
            ->timeout((int) config('operations.backup.timeout'))
            ->start([
                config('operations.backup.pg_restore'),
                // Owned by whoever restores it: role names differ between
                // servers, and a managed one will not hand out another's.
                '--no-owner',
                '--no-privileges',
                '--exit-on-error',
                '--jobs='.max(1, $jobs),
                ...Postgres::arguments($target),
                '--dbname='.$target['database'],
                $path,
            ], function (string $type, string $output) use (&$errors) {
                if ($type === 'err') {
                    $errors = substr($errors.$output, 0, 8192);
                }
            })
            ->wait();

        if (! $result->successful()) {
            throw new RuntimeException('pg_restore failed (exit '.$result->exitCode().'): '.trim($errors));
        }
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, int>  $expected
     * @return array<string, array{expected: int, found: ?int}>
     */
    private function compare(array $target, array $expected): array
    {
        try {
            $found = Postgres::rowCounts(Postgres::open($target, self::TARGET_CONNECTION));
        } finally {
            Postgres::close(self::TARGET_CONNECTION);
        }

        $mismatches = [];

        foreach ($expected as $table => $count) {
            if (($found[$table] ?? null) !== (int) $count) {
                $mismatches[$table] = ['expected' => (int) $count, 'found' => $found[$table] ?? null];
            }
        }

        return $mismatches;
    }
}
