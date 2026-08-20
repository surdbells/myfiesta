<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A scan can now be refused for asking to admit more people than the ticket has
 * left, and that refusal needs a name the log will accept.
 *
 * Every scan is recorded including the refusals — a door reporting the same
 * over-capacity attempt repeatedly is worth seeing afterwards, and it is
 * exactly the sort of thing that gets argued about later.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ticket_scans DROP CONSTRAINT IF EXISTS ticket_scans_result_check');

        DB::statement(<<<'SQL'
            ALTER TABLE ticket_scans ADD CONSTRAINT ticket_scans_result_check
            CHECK (result IN ('accepted', 'duplicate', 'not_found', 'wrong_event', 'void', 'over_capacity'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ticket_scans DROP CONSTRAINT IF EXISTS ticket_scans_result_check');

        DB::statement(<<<'SQL'
            ALTER TABLE ticket_scans ADD CONSTRAINT ticket_scans_result_check
            CHECK (result IN ('accepted', 'duplicate', 'not_found', 'wrong_event', 'void'))
        SQL);
    }
};
