<?php

namespace App\Services\Backups;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Where the nightly backups are kept: a directory, or an S3-compatible bucket.
 *
 * Built here from config/operations.php rather than named in
 * config/filesystems.php, so no other part of the application can be pointed
 * at it by accident — the media disk is public, and a disk name is one typo
 * away from another.
 */
class BackupStore
{
    private ?Filesystem $disk = null;

    public function disk(): Filesystem
    {
        return $this->disk ??= Storage::build($this->diskConfig());
    }

    /** @return list<StoredBackup> newest first */
    public function all(): array
    {
        $backups = [];

        foreach ($this->disk()->files() as $path) {
            if ($backup = StoredBackup::fromName(basename($path))) {
                $backups[] = $backup;
            }
        }

        usort($backups, fn (StoredBackup $a, StoredBackup $b) => $b->takenAt <=> $a->takenAt);

        return $backups;
    }

    public function latest(): ?StoredBackup
    {
        return $this->all()[0] ?? null;
    }

    public function find(string $name): ?StoredBackup
    {
        foreach ($this->all() as $backup) {
            if ($backup->name === $name || $backup->manifestName() === $name) {
                return $backup;
            }
        }

        return null;
    }

    /** @param  resource  $stream */
    public function put(StoredBackup $backup, $stream, array $manifest): void
    {
        // The dump first and the manifest after, so a manifest on the target
        // always has its dump beside it.
        $this->disk()->writeStream($backup->name, $stream);
        $this->disk()->put($backup->manifestName(), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null */
    public function manifest(StoredBackup $backup): ?array
    {
        if (! $this->disk()->exists($backup->manifestName())) {
            return null;
        }

        $manifest = json_decode((string) $this->disk()->get($backup->manifestName()), true);

        return is_array($manifest) ? $manifest : null;
    }

    /** @return resource */
    public function read(StoredBackup $backup)
    {
        $stream = $this->disk()->readStream($backup->name);

        if (! is_resource($stream)) {
            throw new RuntimeException("{$backup->name} could not be read from the backup target.");
        }

        return $stream;
    }

    public function size(StoredBackup $backup): int
    {
        return (int) $this->disk()->size($backup->name);
    }

    public function delete(StoredBackup $backup): void
    {
        $this->disk()->delete([$backup->name, $backup->manifestName()]);
    }

    /** What the target is, for a person reading the output: never a credential. */
    public function describe(): string
    {
        $config = config('operations.backup');

        return match ($config['target']) {
            's3' => 's3://'.$config['s3']['bucket'].'/'.trim((string) $config['s3']['prefix'], '/'),
            default => (string) $config['volume']['path'],
        };
    }

    /** @return array<string, mixed> */
    private function diskConfig(): array
    {
        $config = config('operations.backup');

        return match ($config['target']) {
            'volume' => [
                'driver' => 'local',
                'root' => $config['volume']['path'],
                // Readable by the application's own user and nobody else.
                'visibility' => 'private',
                'throw' => true,
                'report' => false,
            ],
            's3' => array_filter([
                'driver' => 's3',
                'key' => $config['s3']['key'],
                'secret' => $config['s3']['secret'],
                'region' => $config['s3']['region'],
                'bucket' => $config['s3']['bucket'],
                'endpoint' => $config['s3']['endpoint'],
                'use_path_style_endpoint' => $config['s3']['use_path_style_endpoint'],
                'root' => trim((string) $config['s3']['prefix'], '/'),
                // Private, whatever the bucket's default: nobody reads a
                // backup except by the keys above.
                'visibility' => 'private',
                'options' => array_filter([
                    'ServerSideEncryption' => $config['s3']['server_side_encryption'],
                ]),
                'throw' => true,
                'report' => false,
            ], fn ($value) => $value !== null),
            default => throw new RuntimeException('BACKUP_TARGET is "'.$config['target'].'". It must be volume or s3.'),
        };
    }
}
