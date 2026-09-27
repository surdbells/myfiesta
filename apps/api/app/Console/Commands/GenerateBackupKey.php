<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupCipher;
use Illuminate\Console\Command;

class GenerateBackupKey extends Command
{
    protected $signature = 'backup:key';

    protected $description = 'Print a new key for BACKUP_ENCRYPTION_KEY';

    /**
     * Printed, never written anywhere: the key belongs in the password manager
     * and in .env.production, and nowhere a backup is kept.
     */
    public function handle(): int
    {
        $this->line(BackupCipher::generateKey());

        $this->comment('Keep a copy somewhere other than this server and the backup target. Without it no backup made with it can be opened.');

        return self::SUCCESS;
    }
}
