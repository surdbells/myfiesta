<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who works for myFiesta, as opposed to who has an account.
 *
 * One users table holds attendees, organization members, and platform staff.
 * That is right — a person is often more than one of those — but it means the
 * admin panel needs an explicit gate. Without one, anyone who ever bought a
 * ticket could sign in at /admin, because they hold a perfectly valid account.
 *
 * Null is the answer for almost every row, and the column stays null unless
 * someone is deliberately granted it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // admin   — full platform access
            // finance — settlements, ledger, payouts
            // support — read-mostly, for answering tickets
            $table->string('platform_role')->nullable()->after('email_verified_at');
            $table->index('platform_role');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT users_platform_role_check
            CHECK (platform_role IS NULL OR platform_role IN ('admin', 'finance', 'support'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_platform_role_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['platform_role']);
            $table->dropColumn('platform_role');
        });
    }
};
