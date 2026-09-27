<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * pg_dump, with every table counted in the same instant.
 *
 * A transaction of its own is opened first, read-only and repeatable-read,
 * and its snapshot is exported; the rows are counted inside it and pg_dump is
 * handed the same snapshot. So the counts in the manifest are the counts in
 * the dump — exactly, even with orders arriving while it runs — and a restore
 * can be checked table by table against them rather than roughly.
 *
 * This needs a direct connection to Postgres, not one through a
 * transaction-pooling proxy: the snapshot lives only as long as the session
 * that exported it.
 */
class DatabaseDumper
{
    private const SNAPSHOT_CONNECTION = 'backup_snapshot';

    /**
     * Stream a custom-format dump into $writer.
     *
     * @return array{database: string, server_version: string, pg_dump_version: string, tables: array<string, int>}
     */
    public function dump(string $connection, BackupWriter $writer): array
    {
        $config = Postgres::config($connection);
        $snapshotConnection = Postgres::open($config, self::SNAPSHOT_CONNECTION);

        try {
            $pdo = $snapshotConnection->getPdo();
            $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');

            $snapshot = (string) $pdo->query('select pg_export_snapshot()')->fetchColumn();
            $serverVersion = (string) $pdo->query('show server_version')->fetchColumn();
            $tables = Postgres::rowCounts($snapshotConnection);

            $version = $this->pgDumpVersion();

            $this->runPgDump($config, $snapshot, $writer);

            $pdo->exec('COMMIT');
        } finally {
            Postgres::close(self::SNAPSHOT_CONNECTION);
        }

        return [
            'database' => (string) $config['database'],
            'server_version' => $serverVersion,
            'pg_dump_version' => $version,
            'tables' => $tables,
        ];
    }

    private function pgDumpVersion(): string
    {
        $result = Process::timeout(30)->run([config('operations.backup.pg_dump'), '--version']);

        if (! $result->successful()) {
            throw new RuntimeException('pg_dump is not installed where this runs, or would not start. The API image carries it; see ops/docker/api.Dockerfile.');
        }

        return trim($result->output());
    }

    /** @param  array<string, mixed>  $config */
    private function runPgDump(array $config, string $snapshot, BackupWriter $writer): void
    {
        $errors = '';
        $failure = null;

        $command = [
            config('operations.backup.pg_dump'),
            // Custom format: compressed, and restorable a table at a time or
            // several tables at once (pg_restore --jobs).
            '--format=custom',
            '--compress=6',
            '--snapshot='.$snapshot,
            ...Postgres::arguments($config),
            '--dbname='.$config['database'],
        ];

        // quietly(): the output goes to $writer as it arrives and is kept
        // nowhere else — not in memory, and not in a temporary file of the
        // process library's own, which would be the whole dump unencrypted.
        $process = Process::quietly()
            ->env(Postgres::environment($config))
            ->timeout((int) config('operations.backup.timeout'))
            ->start($command, function (string $type, string $output) use ($writer, &$errors, &$failure) {
                if ($failure !== null) {
                    return;
                }

                if ($type === 'err') {
                    $errors = substr($errors.$output, 0, 8192);

                    return;
                }

                try {
                    $writer->write($output);
                } catch (Throwable $e) {
                    $failure = $e;
                }
            });

        $result = $process->wait();

        if ($failure !== null) {
            throw $failure;
        }

        if (! $result->successful()) {
            throw new RuntimeException('pg_dump failed (exit '.$result->exitCode().'): '.trim($errors));
        }
    }
}
