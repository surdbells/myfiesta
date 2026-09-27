<?php

namespace App\Services\Backups;

use App\Support\Operations\Heartbeat;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Taking the nightly backup and keeping the right ones.
 *
 * The order matters. The dump is written and sealed first, then copied to the
 * target with its manifest, and only once that has worked are older backups
 * thinned out — so a night that fails never costs a backup that was already
 * there.
 */
class Backups
{
    public function __construct(
        private readonly BackupStore $store,
        private readonly DatabaseDumper $dumper,
    ) {}

    /**
     * @return array{backup: StoredBackup, manifest: array<string, mixed>, removed: list<StoredBackup>}
     */
    public function run(bool $prune = true): array
    {
        $key = BackupCipher::key(config('operations.backup.encryption_key'));
        $backup = StoredBackup::named(CarbonImmutable::now('UTC'), $key !== null);

        $path = $this->workFile($backup->name);
        $file = fopen($path, 'w+b');

        if ($file === false) {
            throw new RuntimeException('Could not open a working file in '.config('operations.backup.work_dir').'.');
        }

        try {
            $writer = BackupCipher::writer($file, $key);
            $dump = $this->dumper->dump((string) config('operations.backup.connection'), $writer);
            $stored = $writer->finish();

            $manifest = [
                'format' => 1,
                'name' => $backup->name,
                'taken_at' => $backup->takenAt->format('Y-m-d\TH:i:s\Z'),
                'database' => $dump['database'],
                'server_version' => $dump['server_version'],
                'pg_dump_version' => $dump['pg_dump_version'],
                'release' => config('sentry.release'),
                'encrypted' => $key !== null,
                'bytes' => $stored['bytes'],
                'sha256' => $stored['sha256'],
                'tables' => $dump['tables'],
            ];

            rewind($file);
            $this->store->put($backup, $file, $manifest);
        } finally {
            fclose($file);
            @unlink($path);
        }

        Heartbeat::beat(Heartbeat::BACKUP);

        return [
            'backup' => $backup,
            'manifest' => $manifest,
            'removed' => $prune ? $this->prune() : [],
        ];
    }

    /** @return list<StoredBackup> what was removed */
    public function prune(): array
    {
        $keep = config('operations.backup.keep');

        ['remove' => $remove] = Retention::apply(
            $this->store->all(),
            (int) $keep['daily'],
            (int) $keep['weekly'],
            (int) $keep['monthly'],
        );

        foreach ($remove as $backup) {
            $this->store->delete($backup);
        }

        return $remove;
    }

    /**
     * A file of this run's own in the working directory, cleared of anything
     * an earlier run left behind if it was killed part way.
     */
    public function workFile(string $name): string
    {
        $dir = rtrim((string) config('operations.backup.work_dir'), '/\\');

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("The backup working directory {$dir} does not exist and could not be made.");
        }

        foreach (glob($dir.DIRECTORY_SEPARATOR.'myfiesta-*.dump*') ?: [] as $stale) {
            if (filemtime($stale) < time() - 86400) {
                @unlink($stale);
            }
        }

        $path = $dir.DIRECTORY_SEPARATOR.$name;
        touch($path);
        chmod($path, 0600);

        return $path;
    }
}
