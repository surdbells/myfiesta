<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Room in the ledger for revenue that is not a ticket sale.
 *
 * Invites+ is not commission on a sale — a host paying for a plan is a
 * different kind of money arriving, and the ledger's type constraint had no
 * word for it. Widening it now costs one statement; widening it once there are
 * millions of rows and a live reporting layer reading them costs considerably
 * more.
 *
 * 'subscription' is revenue the platform earned directly rather than collected
 * on an organizer's behalf, which is why it needs its own type instead of being
 * dressed up as a sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_type_check');

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_check
            CHECK (type IN (
                'sale', 'discount', 'tax', 'commission', 'refund',
                'settlement', 'adjustment', 'subscription'
            ))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_type_check');

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_check
            CHECK (type IN (
                'sale', 'discount', 'tax', 'commission', 'refund',
                'settlement', 'adjustment'
            ))
        SQL);
    }
};
