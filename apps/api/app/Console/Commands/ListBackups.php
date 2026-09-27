<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupStore;
use App\Services\Backups\StoredBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

class ListBackups extends Command
{
    protected $signature = 'backup:list';

    protected $description = 'List the backups on the backup target, newest first';

    public function handle(BackupStore $store): int
    {
        $backups = $store->all();

        if ($backups === []) {
            $this->warn('There are no backups in '.$store->describe().'.');

            return self::FAILURE;
        }

        $this->table(
            ['Backup', 'Taken (UTC)', 'Size', 'Encrypted', 'Release'],
            array_map(function (StoredBackup $backup) use ($store) {
                $manifest = $store->manifest($backup);

                return [
                    $backup->name,
                    $backup->takenAt->format('Y-m-d H:i'),
                    Number::fileSize($store->size($backup), precision: 1),
                    $backup->encrypted ? 'yes' : 'no',
                    $manifest === null ? 'no manifest' : (string) ($manifest['release'] ?? '—'),
                ];
            }, $backups),
        );

        return self::SUCCESS;
    }
}
